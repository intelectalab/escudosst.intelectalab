<?php

namespace App\Modules\NOT;

use App\Modules\SUS\Services\AccountsService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Alertas SST diarias (M-NOT): revisa cada empresa y deja avisos en la campana de las personas que deben actuar,
 * con enlace al registro. No repite el mismo aviso (dedupe por registro y motivo); los vencidos se recuerdan
 * una vez por semana. Destinatarios: el responsable del registro y los roles SST de la empresa.
 */
final class SstAlerts
{
    private const SST_ROLES = ['sst_manager', 'sst_supervisor', 'account_admin'];

    private const HEALTH_ROLES = ['occupational_doctor', 'health_staff', 'sst_manager'];

    public function __construct(private AccountsService $accounts) {}

    /** @return int avisos creados */
    public function run(): int
    {
        $companies = DB::connection('platform')->select(
            "select c.id from companies c join subscriptions s on s.company_id = c.id
              where c.deleted_at is null and c.status = 'active' and s.deleted_at is null and s.status in ('trialing','active','past_due','grace')"
        );
        $total = 0;
        foreach ($companies as $c) {
            $total += $this->accounts->withCompany($c->id, fn (ConnectionInterface $db) => $this->forCompany($db, $c->id));
        }

        return $total;
    }

    /**
     * Estados que cambian con el paso del tiempo: certificados de capacitación vencidos y fotochecks que dejan de cumplir
     * (o vuelven a cumplir) sus requisitos. Así el QR de la garita no queda desactualizado.
     */
    public function refreshStatuses(ConnectionInterface $db): void
    {
        // M-ACC §9: una notificación obligatoria pendiente pasa a «vencida» al pasar su plazo (queda marcada fuera de plazo).
        $db->update("update event_notifications set status = 'overdue', notification_overdue = true, updated_at = now()
                      where status = 'pending' and deadline_at < now() and deleted_at is null");
        // M-ACR RN-ACR-07: una acción abierta o en curso pasa a «vencida» al pasar su fecha meta.
        $db->update("update corrective_actions set status = 'overdue', updated_at = now()
                      where status in ('open', 'in_progress') and due_on < (now() at time zone 'America/Lima')::date and deleted_at is null");
        $db->update("update training_certificates set status = 'expired' where deleted_at is null and status = 'validated' and expires_on < current_date");
        // EMO periódico (R.M. 312-2011-MINSA): a quien su aptitud vence en 30 días (o no tiene) y su puesto exige EMO,
        // se le deja una orden «programada» sin fecha para que el área de salud la agende. No se duplica.
        $db->insert(
            "insert into medical_exam_orders (company_id, worker_id, exam_type, position_id, protocol_code, status)
             select w.company_id, w.id, case when f.id is null then 'pre_employment' else 'periodic' end, w.current_position_id, p.emo_protocol_code, 'scheduled'
               from workers w join positions p on p.id = w.current_position_id and p.requires_emo
               left join fitness_status f on f.worker_id = w.id and f.is_current and f.deleted_at is null
              where w.deleted_at is null and w.status = 'active'
                and (f.id is null or f.valid_until <= current_date + 30)
                and not exists (select 1 from medical_exam_orders o where o.worker_id = w.id and o.deleted_at is null and o.status in ('scheduled', 'attended', 'results_received'))"
        );
        foreach ($db->select("select id, credential_type_id, worker_id, contractor_worker_id, expires_on, status from credentials where deleted_at is null and status in ('active','blocked')") as $c) {
            $reasons = \App\Modules\REC\Rules\PeopleRules::credentialReasons($c->credential_type_id, $c->worker_id, $c->contractor_worker_id, $c->expires_on);
            $status = $reasons ? 'blocked' : 'active';
            $db->update('update credentials set status = ?, blocking_reasons = ?::jsonb, last_evaluated_at = '.($status !== $c->status ? 'now()' : 'last_evaluated_at').' where id = ?',
                [$status, json_encode($reasons, JSON_UNESCAPED_UNICODE), $c->id]);
        }
    }

    public function forCompany(ConnectionInterface $db, string $company): int
    {
        $enabled = array_column($db->select("select module_code from feature_flags where mode = 'enabled' and deleted_at is null"), 'module_code');
        $this->refreshStatuses($db);
        $week = date('o-W');
        $created = 0;
        $byUser = [];
        $checks = [
            ['ACR', "select id, code as ref, title as name, responsible_user_id as owner, due_on as due, 'overdue' as kind from corrective_actions
                      where deleted_at is null and status in ('open','in_progress','overdue') and due_on < current_date",
                'acr.overdue', 'high', 'Acción correctiva vencida', fn ($r) => "{$r->ref} · {$r->name} venció el ".date('d/m/Y', strtotime($r->due)).'.', '/m/acr?t=corrective_actions&open=', self::SST_ROLES, true],
            ['ACR', "select id, code as ref, title as name, responsible_user_id as owner, due_on as due, 'soon' as kind from corrective_actions
                      where deleted_at is null and status in ('open','in_progress') and due_on between current_date and current_date + 3",
                'acr.due_soon', 'normal', 'Acción correctiva por vencer', fn ($r) => "{$r->ref} · {$r->name} vence el ".date('d/m/Y', strtotime($r->due)).'.', '/m/acr?t=corrective_actions&open=', [], false],
            ['ACC', "select n.id, e.code as ref, e.description as name, null as owner, n.deadline_at as due, 'deadline' as kind from event_notifications n join sst_events e on e.id = n.event_id
                      where n.deleted_at is null and n.status = 'pending' and n.deadline_at < now() + interval '24 hours'",
                'acc.notification', 'critical', 'Notificación obligatoria por vencer', fn ($r) => "El evento {$r->ref} debe notificarse antes del ".date('d/m/Y H:i', strtotime($r->due)).'.', '/m/acc?t=event_notifications&open=', self::SST_ROLES, true],
            ['IPC', "select id, code as ref, title as name, null as owner, next_review_on as due, 'review' as kind from iperc_matrices
                      where deleted_at is null and status = 'active' and next_review_on <= current_date + 30",
                'ipc.review', 'normal', 'IPERC por revisar', fn ($r) => "La matriz {$r->ref} · {$r->name} debe revisarse antes del ".date('d/m/Y', strtotime($r->due)).'.', '/m/ipc?t=matrices&matriz=', self::SST_ROLES, true],
            ['INS', "select id, code as ref, title as name, inspector_user_id as owner, scheduled_on as due, 'late' as kind from inspections
                      where deleted_at is null and status = 'scheduled' and scheduled_on < current_date",
                'ins.late', 'normal', 'Inspección sin ejecutar', fn ($r) => "{$r->ref} · {$r->name} estaba programada para el ".date('d/m/Y', strtotime($r->due)).'.', '/m/ins?t=inspections&open=', self::SST_ROLES, true],
            ['EMO', "select f.id, w.full_name as ref, '' as name, null as owner, f.valid_until as due, 'emo' as kind from fitness_status f join workers w on w.id = f.worker_id
                      where f.deleted_at is null and f.is_current and w.status = 'active' and f.valid_until between current_date and current_date + 30",
                'emo.expiring', 'normal', 'Aptitud médica por vencer', fn ($r) => "La aptitud de {$r->ref} vence el ".date('d/m/Y', strtotime($r->due)).'.', '/m/emo?t=fitness_status&open=', self::HEALTH_ROLES, false],
            ['EPP', "select d.id, w.full_name as ref, i.name as name, null as owner, d.expires_on as due, 'ppe' as kind from ppe_deliveries d join workers w on w.id = d.worker_id join ppe_items i on i.id = d.ppe_item_id
                      where d.deleted_at is null and d.status = 'delivered' and d.expires_on between current_date and current_date + 15",
                'epp.expiring', 'low', 'EPP por reponer', fn ($r) => "{$r->name} de {$r->ref} vence el ".date('d/m/Y', strtotime($r->due)).'.', '/m/epp?t=ppe_deliveries&open=', self::SST_ROLES, false],
            ['FOT', "select c.id, c.credential_number as ref, '' as name, null as owner, c.expires_on as due, 'fot' as kind from credentials c
                      where c.deleted_at is null and c.status = 'active' and c.expires_on between current_date and current_date + 15",
                'fot.expiring', 'low', 'Fotocheck por vencer', fn ($r) => "El fotocheck {$r->ref} vence el ".date('d/m/Y', strtotime($r->due)).'.', '/m/fot?t=credentials&open=', self::SST_ROLES, false],
            ['CAP', "select c.id, c.holder_name as ref, c.course_title as name, null as owner, c.expires_on as due, 'cert' as kind from training_certificates c
                      where c.deleted_at is null and c.status = 'validated' and c.expires_on between current_date and current_date + 30",
                'cap.expiring', 'normal', 'Capacitación por vencer', fn ($r) => "La capacitación «{$r->name}» de {$r->ref} vence el ".date('d/m/Y', strtotime($r->due)).'.', '/m/cap?t=training_certificates&open=', self::SST_ROLES, false],
            ['FOT', "select c.id, c.credential_number as ref, '' as name, null as owner, c.last_evaluated_at as due, 'blocked' as kind from credentials c
                      where c.deleted_at is null and c.status = 'blocked' and c.last_evaluated_at > now() - interval '1 day'",
                'fot.blocked', 'normal', 'Fotocheck bloqueado', fn ($r) => "El fotocheck {$r->ref} quedó bloqueado: revisa sus requisitos.", '/m/fot?t=credentials&open=', self::SST_ROLES, true],
            ['ACT', "select id, code as ref, description as name, null as owner, reported_at as due, 'act' as kind from substandard_reports
                      where deleted_at is null and status = 'received' and reported_at < now() - interval '2 days'",
                'act.untriaged', 'normal', 'Reporte sin revisar', fn ($r) => "El reporte {$r->ref} lleva más de 2 días sin revisar.", '/m/act?t=substandard_reports&open=', self::SST_ROLES, true],
            // M-AMB §10: residuo peligroso sin manifiesto pasados «waste_manifest_days» desde que se generó.
            ['AMB', "select id, coalesce(description, 'Residuo peligroso') as ref, '' as name, null as owner, generated_on as due, 'manifest' as kind from waste_records
                      where deleted_at is null and waste_type in ('hazardous', 'biocontaminated') and coalesce(manifest_number, '') = '' and status <> 'disposed'
                        and generated_on < current_date - coalesce((select p.value::int from legal_parameters p
                            where p.key = 'waste_manifest_days' and p.deleted_at is null and p.valid_from <= current_date and (p.valid_to is null or p.valid_to >= current_date)
                              and p.value ~ '^[0-9]+$' order by p.company_id nulls last, p.valid_from desc limit 1), 30)",
                'amb.manifest_missing', 'normal', 'Residuo peligroso sin manifiesto', fn ($r) => "«{$r->ref}», generado el ".date('d/m/Y', strtotime($r->due)).', aún no tiene manifiesto.', '/m/amb?t=waste_records&open=', self::SST_ROLES, true],
            // M-VUL §10 / RN-VUL-04: adecuación por vencer («adjustment_notice_days» antes) y vencida; nunca se nombra la condición.
            ['VUL', "select a.id, w.full_name as ref, '' as name, null as owner, a.ends_on as due, 'adj' as kind from work_adjustments a join workers w on w.id = a.worker_id
                      where a.deleted_at is null and a.status = 'active' and a.ends_on between current_date and current_date + coalesce((select p.value::int from legal_parameters p
                            where p.key = 'adjustment_notice_days' and p.deleted_at is null and p.valid_from <= current_date and (p.valid_to is null or p.valid_to >= current_date)
                              and p.value ~ '^[0-9]+$' order by p.company_id nulls last, p.valid_from desc limit 1), 15)",
                'vul.adjustment_expiring', 'normal', 'Adecuación de puesto por vencer', fn ($r) => "La adecuación de puesto de {$r->ref} vence el ".date('d/m/Y', strtotime($r->due)).': revísala.', '/m/vul?t=work_adjustments&open=', ['sst_manager', 'occupational_doctor'], false],
            ['VUL', "select a.id, w.full_name as ref, '' as name, null as owner, a.ends_on as due, 'adj_end' as kind from work_adjustments a join workers w on w.id = a.worker_id
                      where a.deleted_at is null and a.status = 'active' and a.ends_on < current_date",
                'vul.adjustment_expired', 'high', 'Adecuación de puesto vencida', fn ($r) => "La adecuación de puesto de {$r->ref} venció el ".date('d/m/Y', strtotime($r->due)).': revísala, extiéndela o dala por terminada.', '/m/vul?t=work_adjustments&open=', ['sst_manager', 'occupational_doctor'], true],
            // M-DES §10: retorno de un descanso por accidente o enfermedad de trabajo (el día siguiente al fin), para el EMO de reintegro.
            // No se avisa si el descanso se extendió con otra ausencia encadenada (RN-DES-06).
            ['DES', "select a.id, w.full_name as ref, '' as name, null as owner, (a.ends_on + 1) as due, 'return' as kind from absences a join workers w on w.id = a.worker_id
                      where a.deleted_at is null and a.status <> 'cancelled' and (a.absence_type in ('occupational_accident', 'occupational_disease') or a.is_work_related)
                        and a.ends_on + 1 between current_date - 3 and current_date
                        and not exists (select 1 from absences x where x.worker_id = a.worker_id and x.id <> a.id and x.deleted_at is null and x.status <> 'cancelled'
                                         and x.starts_on <= a.ends_on + 1 and x.ends_on > a.ends_on)",
                'des.return_to_work', 'high', 'Retorno de descanso por accidente', fn ($r) => "{$r->ref} retorna el ".date('d/m/Y', strtotime($r->due)).' de su descanso: programa el EMO de reintegro.', '/m/des?t=absences&open=', ['sst_manager', 'occupational_doctor'], false],
        ];
        foreach ($checks as [$module, $sql, $event, $priority, $title, $body, $link, $roles, $weekly]) {
            if (! in_array($module, $enabled, true)) {
                continue;
            }
            $rows = $db->select($sql);
            if ($rows === []) {
                continue;
            }
            $team = $roles ? array_column($db->select(
                "select m.user_id from memberships m join roles r on r.id = m.role_id where m.company_id = ? and m.status = 'active' and m.deleted_at is null and coalesce(r.base_role_code, r.code) = any(?::text[])",
                [$company, '{'.implode(',', $roles).'}']
            ), 'user_id') : [];
            foreach ($rows as $r) {
                $to = array_unique(array_filter([...$team, $r->owner]));
                $dedupe = $event.':'.$r->id.($weekly ? ':'.$week : '');
                foreach ($to as $user) {
                    // La llave única de la tabla es (empresa, dedupe_key): incluye a la persona para avisar a cada una sin chocar.
                    $key = $dedupe.':'.$user;
                    if ($db->selectOne('select 1 as ok from notifications where company_id = ? and recipient_user_id = ? and dedupe_key in (?, ?) and deleted_at is null', [$company, $user, $key, $dedupe])) {
                        continue;
                    }
                    $nid = $db->selectOne(
                        'insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, due_at, dedupe_key)
                         values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) returning id',
                        [$company, $event, $module, $r->id, $user, $title, mb_substr($body($r), 0, 500), $link.$r->id, $priority, $r->due, $key]
                    )->id;
                    $byUser[$user][] = ['id' => $nid, 'module' => strtolower($module), 'title' => $title, 'body' => mb_substr($body($r), 0, 500), 'link' => $link.$r->id, 'priority' => $priority];
                    $created++;
                }
            }
        }

        $this->email($db, $company, $byUser);

        return $created;
    }

    /**
     * Un correo por persona con todos sus avisos nuevos del día. Respeta sus preferencias (canal correo
     * desactivado para ese módulo o para todo) y deja constancia de cada envío en notification_deliveries.
     */
    private function email(\Illuminate\Database\ConnectionInterface $db, string $company, array $byUser): void
    {
        if ($byUser === []) {
            return;
        }
        $companyName = $db->selectOne('select coalesce(trade_name, legal_name) as n from companies where id = ?', [$company])?->n ?? 'tu empresa';
        $front = rtrim((string) config('escudo.frontend_url'), '/');
        foreach ($byUser as $userId => $items) {
            $user = $db->selectOne('select full_name, email from users where id = ? and deleted_at is null', [$userId]);
            if (! $user) {
                continue;
            }
            $off = array_column($db->select("select event_category from user_notification_prefs where company_id = ? and user_id = ? and channel = 'email'
                                               and not enabled and deleted_at is null", [$company, $userId]), 'event_category');
            $items = array_values(array_filter($items, fn ($i) => ! in_array('*', $off, true) && ! in_array($i['module'], $off, true)));
            if ($items === []) {
                continue;
            }
            $status = 'sent';
            $error = null;
            try {
                Mail::to($user->email)->send(new SstDigestMail(explode(' ', $user->full_name)[0], $companyName,
                    array_map(fn ($i) => ['title' => $i['title'], 'body' => $i['body'], 'url' => $front.$i['link'], 'priority' => $i['priority']], $items), $front.'/inicio'));
            } catch (Throwable $e) {
                $status = 'failed';
                $error = mb_substr($e->getMessage(), 0, 300);
            }
            foreach ($items as $i) {
                $db->insert(
                    "insert into notification_deliveries (company_id, notification_id, channel, provider, status, attempt, error_message, attempted_at)
                     values (?, ?, 'email', ?, ?, 1, ?, now())",
                    [$company, $i['id'], (string) config('mail.default'), $status, $error]
                );
            }
        }
    }
}
