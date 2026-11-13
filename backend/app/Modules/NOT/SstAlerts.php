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
        // M-IPC RN-IPC-08: matriz vigente que pasó su fecha de revisión → «revisión vencida».
        $db->update("update iperc_matrices set status = 'review_overdue', review_reason = 'Pasó la fecha de revisión', review_flagged_at = now()
                      where status = 'active' and next_review_on < (now() at time zone 'America/Lima')::date and deleted_at is null");
        // M-ACR RN-ACR-07: una acción abierta o en curso pasa a «vencida» al pasar su fecha meta.
        $db->update("update corrective_actions set status = 'overdue', updated_at = now()
                      where status in ('open', 'in_progress') and due_on < (now() at time zone 'America/Lima')::date and deleted_at is null");
        // M-PTR RN-PTR-05: un permiso aprobado (o esperando aprobación) cuyo horario terminó sin iniciarse queda «vencido».
        $db->update("update work_permits set status = 'expired', updated_at = now()
                      where status in ('pending_approval', 'approved') and valid_until < now() and deleted_at is null");
        // M-EQE RN-EQE-04: un botiquín con ítems vencidos pasa a «con vencidos».
        $db->update("update first_aid_kits k set status = 'expired_items' where k.status in ('complete', 'incomplete') and k.deleted_at is null
                      and exists (select 1 from first_aid_kit_items i where i.kit_id = k.id and i.deleted_at is null and i.expires_on < current_date)");
        $db->update("update training_certificates set status = 'expired' where deleted_at is null and status = 'validated' and expires_on < current_date");
        // M-ING RN-ING-05: los eventos de garita se conservan access_retention_days (0 = sin purga hasta que la empresa lo defina).
        $retention = (int) \App\Core\Support\LegalParams::number('access_retention_days', 0);
        if ($retention > 0) {
            $db->delete('delete from access_events where occurred_at < now() - make_interval(days => ?::int)', [$retention]);
        }
        // M-HOM RN-HOM-05: la homologación aprobada que pasó su vigencia queda «vencida».
        $db->update("update vendor_evaluations set status = 'expired', updated_at = now() where deleted_at is null and status = 'approved' and valid_until < current_date");
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
        // M-EMO RN-EMO-07: la aptitud que llegó a su fecha sin un nuevo examen queda «vencida» (luego se reevalúan los fotochecks).
        $db->update("update fitness_status set status = 'expired', updated_at = now()
                      where is_current and status in ('fit', 'fit_with_restrictions') and valid_until < current_date and deleted_at is null");
        // M-DES RN-DES-05: en licencia mientras dure su descanso; vuelve a activo al terminar.
        \App\Modules\REC\Rules\PeopleRules::syncLeave($db);
        foreach ($db->select("select id, credential_type_id, worker_id, contractor_worker_id, valid_from, expires_on, status from credentials where deleted_at is null and status in ('active','blocked')") as $c) {
            // RN-FOT-02: el fin de vigencia sigue al requisito que vence primero (si se renovó, se extiende hasta la vigencia del tipo).
            $expires = \App\Modules\REC\Rules\PeopleRules::credentialExpiry($c->credential_type_id, $c->valid_from, $c->worker_id, $c->contractor_worker_id);
            $reasons = \App\Modules\REC\Rules\PeopleRules::credentialReasons($c->credential_type_id, $c->worker_id, $c->contractor_worker_id, $expires);
            $status = $reasons ? 'blocked' : 'active';
            $db->update('update credentials set status = ?, expires_on = ?, blocking_reasons = ?::jsonb, last_evaluated_at = '.($status !== $c->status ? 'now()' : 'last_evaluated_at').' where id = ?',
                [$status, $expires, json_encode($reasons, JSON_UNESCAPED_UNICODE), $c->id]);
        }
    }

    /** Días (u horas) de aviso desde los parámetros legales, siempre como número entero para el SQL. */
    /**
     * M-PSI §10: recordatorio survey_reminder_days antes del cierre a cada trabajador del alcance que aún no respondió
     * (por la app o por WhatsApp; la respuesta sigue siendo anónima: solo se compara la huella del respondiente).
     */
    private static function surveyReminders(ConnectionInterface $db, string $company): int
    {
        $n = 0;
        $surveys = $db->select("select id, title, ends_on from psychosocial_surveys
                                 where deleted_at is null and status = 'open' and ends_on between current_date and current_date + ".self::days('survey_reminder_days', 2));
        foreach ($surveys as $s) {
            $workers = $db->select(
                "select w.id, w.user_id from workers w, psychosocial_surveys s
                  where s.id = ? and w.status = 'active' and w.deleted_at is null
                    and (jsonb_typeof(s.target_scope -> 'site_ids') is distinct from 'array' or w.current_site_id::text in (select jsonb_array_elements_text(s.target_scope -> 'site_ids')))
                    and (jsonb_typeof(s.target_scope -> 'area_ids') is distinct from 'array' or w.current_area_id::text in (select jsonb_array_elements_text(s.target_scope -> 'area_ids')))",
                [$s->id]
            );
            $answered = array_flip(array_map(fn ($r) => bin2hex(is_resource($r->h) ? stream_get_contents($r->h) : $r->h),
                $db->select('select respondent_token_hash as h from psychosocial_responses where survey_id = ? and deleted_at is null and respondent_token_hash is not null', [$s->id])));
            foreach ($workers as $w) {
                if (isset($answered[\App\Modules\PSI\PsychosocialSurvey::respondentHash($s->id, $w->id)])) {
                    continue;
                }
                $n += $db->affectingStatement(
                    "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, recipient_worker_id, title, body, link, priority, due_at, dedupe_key)
                     values (?, 'psi.closing_soon', 'PSI', ?, ?, ?, 'Encuesta por cerrar', ?, '/mi/pendientes', 'low', ?, ?) on conflict do nothing",
                    [$company, $s->id, $w->user_id, $w->id, "La encuesta «{$s->title}» cierra el ".date('d/m/Y', strtotime($s->ends_on)).': si aún no respondes, hazlo. Es anónima.',
                        $s->ends_on, 'psi.closing_soon:'.$s->id.':'.$w->id]
                );
            }
        }

        return $n;
    }

    /** Avisos §10 que no salen de una consulta simple: indicadores, consultores, invitaciones, ISO y paquetes sectoriales. */
    private function extras(ConnectionInterface $db, string $company, array $enabled): int
    {
        $n = 0;
        $lima = now('America/Lima');
        $sst = array_column($db->select("select distinct m.user_id from memberships m join roles r on r.id = m.role_id where m.status = 'active' and m.deleted_at is null
                                          and coalesce(r.base_role_code, r.code) = 'sst_manager'"), 'user_id');
        $ins = function (array $users, string $event, string $module, string $subject, string $title, string $body, string $link, string $dedupe, string $priority = 'normal') use ($db, $company, &$n) {
            foreach (array_unique(array_filter($users)) as $u) {
                $n += $db->affectingStatement(
                    'insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, dedupe_key) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) on conflict do nothing',
                    [$company, $event, $module, $subject, $u, $title, mb_substr($body, 0, 500), $link, $priority, $dedupe.':'.$u]);
            }
        };
        // M-DSH §10: KPI en rojo al recalcular (cada revisión) → Jefe SST, una vez por indicador y mes; resumen mensual a gerencia el día dsh_report_day.
        if (in_array('DSH', $enabled, true)) {
            try {
                $kpis = app(\App\Modules\DSH\Services\KpiService::class)->overview((int) $lima->format('Y'), null);
                foreach ($kpis as $k) {
                    if (($k['color'] ?? null) === 'red') {
                        $ins($sst, 'dsh.kpi_red', 'DSH', $company, 'Indicador en rojo', "El indicador «{$k['name']}» está en rojo: ".($k['label'] ?? '').'.', '/inicio', 'dsh.kpi_red:'.$k['code'].':'.$lima->format('Y-m'));
                    }
                }
                if ((int) $lima->format('j') >= self::days('dsh_report_day', 5)) {
                    $c = array_count_values(array_filter(array_map(fn ($k) => $k['color'] ?? null, $kpis)));
                    $mgmt = array_column($db->select("select distinct m.user_id from memberships m join roles r on r.id = m.role_id where m.status = 'active' and m.deleted_at is null
                                                       and coalesce(r.base_role_code, r.code) = 'management'"), 'user_id');
                    $ins($mgmt, 'dsh.monthly_summary', 'DSH', $company, 'Resumen mensual de indicadores SST',
                        'Indicadores del año: '.($c['green'] ?? 0).' en verde, '.($c['amber'] ?? 0).' en ámbar y '.($c['red'] ?? 0).' en rojo. El detalle está en el inicio de EscudoSST.',
                        '/inicio', 'dsh.monthly_summary:'.$lima->format('Y-m'), 'low');
                }
            } catch (Throwable) {
            }
        }
        // M-CON §10: vencimientos de la semana de la empresa cliente → sus consultores asignados, el día con_digest_weekday.
        if ((int) $lima->format('N') === self::days('con_digest_weekday', 1)) {
            $consultants = array_column($db->select("select consultant_user_id from consultant_assignments where status = 'active' and deleted_at is null"), 'consultant_user_id');
            if ($consultants) {
                $due = (int) $db->selectOne("select count(*) as n from notifications where deleted_at is null and dismissed_at is null and due_at between now() and now() + interval '7 days'")->n;
                $name = $db->selectOne('select coalesce(trade_name, legal_name) as n from companies where id = ?', [$company])?->n ?? 'tu cliente';
                if ($due > 0) {
                    $ins($consultants, 'con.weekly_due', 'CON', $company, 'Vencimientos de la semana de tu cliente', "{$name} tiene {$due} vencimiento(s) SST en los próximos 7 días.", '/inicio',
                        'con.weekly_due:'.$lima->format('o-W'));
                }
            }
        }
        // M-USR §10: invitación sin aceptar → recordatorio invite_reminder_days después (una vez).
        foreach ($db->select("select m.id, m.user_id, u.email, u.full_name, r.name as role_name, c.legal_name, coalesce(c.trade_name, c.legal_name) as company_name
                                from memberships m join users u on u.id = m.user_id join roles r on r.id = m.role_id join companies c on c.id = m.company_id
                               where m.status = 'invited' and m.deleted_at is null and not m.is_support_session
                                 and m.invited_at < now() - make_interval(days => ?::int)
                                 and not exists (select 1 from notifications x where x.dedupe_key = 'usr.invite_reminder:'||m.id and x.deleted_at is null)", [self::days('invite_reminder_days', 3)]) as $m) {
            try {
                app(\App\Modules\USR\Services\InvitationService::class)->send($m->user_id, $m->email, $m->full_name, $company, $m->company_name, $m->id, true, $m->role_name);
                $db->insert("insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, dedupe_key, read_at)
                             values (?, 'usr.invite_reminder', 'USR', ?, ?, 'Recordatorio de invitación', 'Te recordamos tu invitación a EscudoSST.', '/inicio', 'low', ?, now()) on conflict do nothing",
                    [$company, $m->id, $m->user_id, 'usr.invite_reminder:'.$m->id]);
                $n++;
            } catch (Throwable) {
            }
        }
        // M-ISO §10: evidencia ligada a un documento obsoleto, archivado o borrado → Jefe SST (una vez por semana mientras siga así).
        if (in_array('ISO', $enabled, true)) {
            foreach ($db->select("select e.id, e.description from iso_evidences e join documents d on d.id = e.document_id
                                   where e.deleted_at is null and (d.deleted_at is not null or d.status in ('obsolete', 'archived'))") as $e) {
                $ins($sst, 'iso.evidence_obsolete', 'ISO', $e->id, 'Evidencia ISO obsoleta', 'La evidencia «'.mb_substr((string) $e->description, 0, 120).'» apunta a un documento que ya no está vigente.',
                    '/m/iso?t=iso_evidences&open='.$e->id, 'iso.evidence_obsolete:'.$e->id.':'.date('o-W'));
            }
        }
        // M-PSE §10: nueva versión publicada de un paquete sectorial instalado → Jefe SST (una vez por versión).
        if (in_array('PSE', $enabled, true)) {
            foreach ($db->select("select c.id, p.name, p.version_number from company_sector_packs c join sector_packs p on p.id = c.sector_pack_id
                                   where c.deleted_at is null and c.uninstalled_at is null and p.status = 'published' and p.version_number > coalesce(c.installed_version, c.pack_version, 0)") as $k) {
                $ins($sst, 'pse.new_version', 'PSE', $k->id, 'Nueva versión del paquete sectorial', "El paquete «{$k->name}» tiene la versión {$k->version_number}: revisa qué cambia antes de actualizar.",
                    '/m/pse', 'pse.new_version:'.$k->id.':'.$k->version_number);
            }
        }

        return $n;
    }

    private static function days(string $key, int $default): int
    {
        return max(0, (int) \App\Core\Support\LegalParams::number($key, $default));
    }

    /** @param ?bool $digest null = solo a la hora digest_hour (Lima); true/false para forzarlo (pruebas). */
    public function forCompany(ConnectionInterface $db, string $company, ?bool $digest = null): int
    {
        $enabled = array_column($db->select("select module_code from feature_flags where mode = 'enabled' and deleted_at is null"), 'module_code');
        $this->refreshStatuses($db);
        $week = date('o-W');
        $created = 0;
        $checks = [
            // M-ACR §10: acción vencida → responsable, jefe del área y Jefe SST; al vencer y cada overdue_repeat_days.
            ['ACR', "select a.id, a.code as ref, a.title as name, a.responsible_user_id as owner, (select mw.user_id from areas ar join workers mw on mw.id = ar.manager_worker_id where ar.id = a.area_id) as owner2, a.due_on as due,
                            ((current_date - a.due_on - 1) / ".max(1, self::days('overdue_repeat_days', 7)).")::text as dk, 'overdue' as kind from corrective_actions a
                      where a.deleted_at is null and a.status in ('open','in_progress','overdue') and a.due_on < current_date",
                'acr.overdue', 'high', 'Acción correctiva vencida', fn ($r) => "{$r->ref} · {$r->name} venció el ".date('d/m/Y', strtotime($r->due)).'.', '/m/acr?t=corrective_actions&open=', ['sst_manager'], false],
            ['ACR', "select id, code as ref, title as name, responsible_user_id as owner, due_on as due, 'soon' as kind from corrective_actions
                      where deleted_at is null and status in ('open','in_progress') and due_on between current_date and current_date + ".self::days('action_due_notice_days', 3),
                'acr.due_soon', 'normal', 'Acción correctiva por vencer', fn ($r) => "{$r->ref} · {$r->name} vence el ".date('d/m/Y', strtotime($r->due)).'.', '/m/acr?t=corrective_actions&open=', [], false],
            // M-ACC §10: notificación obligatoria por vencer (notify_warning_hours antes) → responsable del evento y Jefe SST.
            ['ACC', "select n.id, e.code as ref, e.description as name, e.reporter_user_id as owner, n.deadline_at as due, 'deadline' as kind from event_notifications n join sst_events e on e.id = n.event_id
                      where n.deleted_at is null and n.status = 'pending' and n.deadline_at between now() and now() + make_interval(hours => ".self::days('notify_warning_hours', 24).')',
                'acc.notification', 'critical', 'Notificación obligatoria por vencer', fn ($r) => "El evento {$r->ref} debe notificarse antes del ".date('d/m/Y H:i', strtotime($r->due)).'.', '/m/acc?t=event_notifications&open=', ['sst_manager'], false],
            // M-ACC §10: notificación vencida → Jefe SST y gerencia, al vencer.
            ['ACC', "select n.id, e.code as ref, e.description as name, null as owner, n.deadline_at as due, 'overdue' as kind from event_notifications n join sst_events e on e.id = n.event_id
                      where n.deleted_at is null and n.status = 'overdue' and n.notified_at is null",
                'acc.notification_overdue', 'critical', 'Notificación obligatoria vencida', fn ($r) => "Venció el plazo para notificar el evento {$r->ref} (".date('d/m/Y H:i', strtotime($r->due)).'): notifícalo cuanto antes.', '/m/acc?t=event_notifications&open=', ['sst_manager', 'management'], false],
            ['IPC', "select id, code as ref, title as name, null as owner, next_review_on as due, 'review' as kind from iperc_matrices
                      where deleted_at is null and status in ('active', 'review_overdue') and next_review_on <= current_date + ".self::days('iperc_review_notice_days', 30),
                'ipc.review', 'normal', 'IPERC por revisar', fn ($r) => "La matriz {$r->ref} · {$r->name} debe revisarse antes del ".date('d/m/Y', strtotime($r->due)).'.', '/m/ipc?t=matrices&matriz=', ['sst_manager'], true],
            ['INS', "select id, code as ref, title as name, inspector_user_id as owner, scheduled_on as due, 'late' as kind from inspections
                      where deleted_at is null and status = 'scheduled' and scheduled_on < current_date",
                'ins.late', 'normal', 'Inspección sin ejecutar', fn ($r) => "{$r->ref} · {$r->name} estaba programada para el ".date('d/m/Y', strtotime($r->due)).'.', '/m/ins?t=inspections&open=', ['sst_manager'], true],
            // M-INS §10: inspección programada próxima → inspector asignado.
            ['INS', "select id, code as ref, title as name, inspector_user_id as owner, scheduled_on as due, 'soon' as kind from inspections
                      where deleted_at is null and status = 'scheduled' and scheduled_on between current_date and current_date + ".self::days('inspection_notice_days', 2),
                'ins.upcoming', 'normal', 'Inspección próxima', fn ($r) => "Tienes la inspección {$r->ref} · {$r->name} el ".date('d/m/Y', strtotime($r->due)).'.', '/m/ins?t=inspections&open=', [], false],
            // M-EMO §10: EMO periódico por vencer → trabajador y personal de salud (emo_due_notice_days); sin datos clínicos.
            ['EMO', "select f.id, w.full_name as ref, '' as name, null as owner, w.id as worker, f.valid_until as due, 'emo' as kind from fitness_status f join workers w on w.id = f.worker_id
                      where f.deleted_at is null and f.is_current and w.status = 'active' and f.valid_until between current_date and current_date + ".self::days('emo_due_notice_days', 30),
                'emo.expiring', 'normal', 'Examen médico por vencer', fn ($r) => "{$r->ref} tiene su examen médico ocupacional por vencer el ".date('d/m/Y', strtotime($r->due)).'.', '/m/emo?t=fitness_status&open=', ['occupational_doctor', 'health_staff'], false,
                fn ($r) => 'Tienes un examen médico ocupacional por vencer el '.date('d/m/Y', strtotime($r->due)).'.'],
            // M-EPP §10: EPP por reponer → trabajador y supervisor (ppe_replace_notice_days).
            ['EPP', "select d.id, w.full_name as ref, i.name as name, null as owner, w.id as worker, d.expires_on as due, 'ppe' as kind from ppe_deliveries d join workers w on w.id = d.worker_id join ppe_items i on i.id = d.ppe_item_id
                      where d.deleted_at is null and d.status = 'delivered' and d.expires_on between current_date and current_date + ".self::days('ppe_replace_notice_days', 15),
                'epp.expiring', 'low', 'EPP por reponer', fn ($r) => "{$r->name} de {$r->ref} debe reponerse el ".date('d/m/Y', strtotime($r->due)).'.', '/m/epp?t=ppe_deliveries&open=', ['sst_supervisor'], false,
                fn ($r) => "Tu {$r->name} debe reponerse el ".date('d/m/Y', strtotime($r->due)).'.'],
            // ── Avisos §10 de las fichas (revisión diaria). Los plazos salen de los parámetros legales (a confirmar por la empresa). ──
            // M-PTR: permiso en ejecución que pasó su horario sin cerrarse.
            ['PTR', "select id, code as ref, work_description as name, requested_by as owner, valid_until as due, 'ptr' as kind from work_permits
                      where deleted_at is null and status in ('active', 'suspended') and valid_until < now()",
                'ptr.not_closed', 'high', 'Permiso de trabajo sin cerrar', fn ($r) => "El permiso {$r->ref} terminó su horario el ".date('d/m/Y H:i', strtotime($r->due)).' y sigue abierto: ciérralo o amplíalo.', '/m/ptr?t=work_permits&open=', ['sst_supervisor'], false],
            // M-PTR §10: permiso próximo a vencer → solicitante.
            ['PTR', "select id, code as ref, work_description as name, requested_by as owner, valid_until as due, 'soon' as kind from work_permits
                      where deleted_at is null and status = 'active' and valid_until between now() and now() + make_interval(mins => ".self::days('permit_expiry_notice_minutes', 60).')',
                'ptr.expiring', 'normal', 'Permiso por vencer', fn ($r) => "El permiso {$r->ref} vence a las ".date('H:i', strtotime($r->due)).': ciérralo o pide ampliación.', '/m/ptr?t=work_permits&open=', [], false],
            // M-INV RN-INV-04: investigación por vencer o vencida.
            ['INV', "select i.id, i.code as ref, '' as name, (select t.user_id from investigation_team t where t.investigation_id = i.id and t.role = 'leader' and t.deleted_at is null limit 1) as owner,
                            (select w.user_id from investigation_team t join workers w on w.id = t.worker_id where t.investigation_id = i.id and t.role = 'leader' and t.deleted_at is null limit 1) as owner2,
                            i.due_on as due, 'inv' as kind from investigations i
                      where i.deleted_at is null and i.status in ('open', 'analysis', 'committee_review') and i.due_on <= current_date + ".self::days('investigation_notice_days', 3),
                'inv.due', 'high', 'Investigación por vencer', fn ($r) => "La investigación {$r->ref} ".(strtotime($r->due) < strtotime('today') ? 'venció el ' : 'vence el ').date('d/m/Y', strtotime($r->due)).'.', '/m/inv?t=investigations&open=', [], true],
            // M-ACC: evento reportado que sigue sin clasificar (event_triage_hours).
            ['ACC', "select id, code as ref, '' as name, reporter_user_id as owner, reported_at as due, 'triage' as kind from sst_events
                      where deleted_at is null and status = 'reported' and reported_at < now() - make_interval(hours => ".self::days('event_triage_hours', 24).')',
                'acc.untriaged', 'high', 'Evento sin clasificar', fn ($r) => "El evento {$r->ref} se reportó el ".date('d/m/Y H:i', strtotime($r->due)).' y sigue sin clasificar.', '/m/acc?t=sst_events&open=', self::SST_ROLES, false],
            // M-COM RN-COM-07: mandato del comité por vencer; acuerdos vencidos.
            ['COM', "select id, name as ref, '' as name, null as owner, period_end_on as due, 'com' as kind from committees
                      where deleted_at is null and status = 'active' and period_end_on <= current_date + ".self::days('term_notice_days', 60),
                'com.term_ending', 'normal', 'Mandato del comité por vencer', fn ($r) => "El mandato de «{$r->ref}» vence el ".date('d/m/Y', strtotime($r->due)).': prepara la elección.', '/m/com?t=committees&open=', ['sst_manager', 'committee_member'], true],
            // M-COM §10: acuerdo por vencer (agreement_due_notice_days) → responsable.
            ['COM', "select a.id, concat('Acuerdo ', a.number) as ref, a.description as name, a.responsible_user_id as owner, a.responsible_worker_id as worker, a.due_on as due, 'agr_soon' as kind from meeting_agreements a
                      where a.deleted_at is null and a.status in ('agreed', 'in_progress') and a.due_on between current_date and current_date + ".self::days('agreement_due_notice_days', 3),
                'com.agreement_due', 'normal', 'Acuerdo del comité por vencer', fn ($r) => "{$r->ref}: «".mb_substr((string) $r->name, 0, 80).'» vence el '.date('d/m/Y', strtotime($r->due)).'.', '/m/com?t=meeting_agreements&open=', [], false],
            // M-COM §10: acuerdo vencido → responsable, al vencer.
            ['COM', "select a.id, concat('Acuerdo ', a.number) as ref, a.description as name, a.responsible_user_id as owner, a.responsible_worker_id as worker, a.due_on as due, 'agr' as kind from meeting_agreements a
                      where a.deleted_at is null and a.status in ('agreed', 'in_progress', 'overdue') and a.due_on < current_date",
                'com.agreement_overdue', 'normal', 'Acuerdo del comité vencido', fn ($r) => "{$r->ref}: «".mb_substr((string) $r->name, 0, 80).'» venció el '.date('d/m/Y', strtotime($r->due)).'.', '/m/com?t=meeting_agreements&open=', [], false],
            // M-COM §10: sesión programada (meeting_notice_days antes) → miembros del comité.
            ['COM', "select m.id, c.name as ref, '' as name, null as owner, cm.worker_id as worker, m.scheduled_at as due, 'meet' as kind
                       from committee_meetings m join committees c on c.id = m.committee_id
                       join committee_members cm on cm.committee_id = c.id and cm.status = 'active' and cm.deleted_at is null
                      where m.deleted_at is null and m.status = 'scheduled' and m.scheduled_at between now() and now() + make_interval(days => ".self::days('meeting_notice_days', 3).')',
                'com.meeting_upcoming', 'normal', 'Sesión del comité', fn ($r) => "Sesión de «{$r->ref}» el ".date('d/m/Y H:i', strtotime($r->due)).'.', '/m/com?t=committee_meetings&open=', [], false,
                fn ($r) => "Tienes sesión de «{$r->ref}» el ".date('d/m/Y H:i', strtotime($r->due)).'.'],
            // M-COM §10: recordatorio de voto (vote_reminder_hours antes del cierre) → electores del padrón que no votaron.
            ['COM', "select e.id, e.title as ref, '' as name, null as owner, r.worker_id as worker, e.voting_closes_at as due, 'vote' as kind
                       from elections e join election_roll r on r.election_id = e.id and not r.excluded and not r.has_voted
                      where e.deleted_at is null and e.status = 'voting_open' and e.voting_closes_at between now() and now() + make_interval(hours => ".self::days('vote_reminder_hours', 24).')',
                'com.vote_reminder', 'normal', 'Aún no votas', fn ($r) => "La votación «{$r->ref}» cierra el ".date('d/m/Y H:i', strtotime($r->due)).'.', '/mi', [], false,
                fn ($r) => "La votación del Comité cierra el ".date('d/m/Y H:i', strtotime($r->due)).': entra a tu app y vota.'],
            // M-AUI: auditoría próxima (audit_notice_days).
            ['AUI', "select id, code as ref, scope as name, null as owner, starts_on as due, 'aui' as kind from audits
                      where deleted_at is null and status = 'planned' and starts_on between current_date and current_date + ".self::days('audit_notice_days', 15),
                'aui.upcoming', 'normal', 'Auditoría próxima', fn ($r) => "La auditoría {$r->ref} empieza el ".date('d/m/Y', strtotime($r->due)).'.', '/m/aui?t=audits&open=', ['sst_manager'], false],
            // M-RVD RN-RVD-04: revisión por la dirección planificada que llega o pasó su fecha.
            ['RVD', "select id, title as ref, '' as name, null as owner, review_on as due, 'rvd' as kind from management_reviews
                      where deleted_at is null and status = 'planned' and review_on <= current_date + ".self::days('management_review_notice_days', 15),
                'rvd.due', 'normal', 'Revisión por la dirección próxima', fn ($r) => "«{$r->ref}» está programada para el ".date('d/m/Y', strtotime($r->due)).'.', '/m/rvd?t=management_reviews&open=', [...self::SST_ROLES, 'management'], true],
            // M-SIM: simulacro próximo (drill_notice_days).
            // M-SIM §10: simulacro programado (drill_notice_days) → brigadistas de la sede y jefes de área; los sorpresa no se avisan.
            ['SIM', "select d.id, d.code as ref, d.title as name, null as owner, bm.worker_id as worker, d.scheduled_at as due, 'sim' as kind from drills d
                       join brigades b on b.site_id = d.site_id and b.status = 'active' and b.deleted_at is null
                       join brigade_members bm on bm.brigade_id = b.id and bm.status = 'active' and bm.deleted_at is null
                      where d.deleted_at is null and d.status = 'planned' and coalesce(d.is_announced, true)
                        and d.scheduled_at between now() and now() + make_interval(days => ".self::days('drill_notice_days', 7).')',
                'sim.upcoming', 'normal', 'Simulacro próximo', fn ($r) => "El simulacro «{$r->name}» está programado para el ".date('d/m/Y H:i', strtotime($r->due)).'.', '/m/sim?t=drills&open=', [], false],
            ['SIM', "select d.id, d.code as ref, d.title as name, null as owner, d.scheduled_at as due, 'sim_lead' as kind from drills d
                      where d.deleted_at is null and d.status = 'planned' and coalesce(d.is_announced, true)
                        and d.scheduled_at between now() and now() + make_interval(days => ".self::days('drill_notice_days', 7).')',
                'sim.upcoming', 'normal', 'Simulacro próximo', fn ($r) => "El simulacro «{$r->name}» está programado para el ".date('d/m/Y H:i', strtotime($r->due)).'.', '/m/sim?t=drills&open=', ['area_lead'], false],
            // M-EPP RN-EPP-08: stock por debajo del mínimo.
            ['EPP', "select i.id, i.name as ref, '' as name, null as owner, current_date as due, 'stock' as kind from ppe_items i
                      where i.deleted_at is null and i.is_active and i.min_stock is not null
                        and (select coalesce(sum(case when m.movement_type in ('in','return','adjustment') then m.quantity else -m.quantity end), 0)
                               from ppe_stock_movements m where m.ppe_item_id = i.id and m.deleted_at is null) < i.min_stock",
                'epp.low_stock', 'normal', 'Stock de EPP bajo', fn ($r) => "El stock de {$r->ref} está por debajo del mínimo: repón.", '/m/epp?t=ppe_items&open=', ['sst_manager'], true],
            // M-EQE RN-EQE-02: mantenimiento o vencimiento de equipos de emergencia.
            ['EQE', "select id, code as ref, equipment_type as name, null as owner, least(next_maintenance_on, expires_on) as due, 'eqe' as kind from emergency_equipment
                      where deleted_at is null and status <> 'retired' and least(next_maintenance_on, expires_on) <= current_date + ".self::days('equipment_due_notice_days', 15),
                'eqe.due', 'normal', 'Equipo de emergencia por revisar', fn ($r) => "El equipo {$r->ref} necesita mantenimiento o vence el ".date('d/m/Y', strtotime($r->due)).'.', '/m/eqe?t=emergency_equipment&open=', ['sst_manager'], true],
            // M-MOC RN-MOC-04: cambio temporal que llega a su fecha de fin.
            ['MOC', "select id, code as ref, title as name, requested_by as owner, temporary_until as due, 'moc' as kind from change_requests
                      where deleted_at is null and is_temporary and status in ('approved', 'implemented') and temporary_until <= current_date + ".self::days('moc_temp_notice_days', 7),
                'moc.temporary_ending', 'normal', 'Cambio temporal por terminar', fn ($r) => "El cambio temporal {$r->ref} termina el ".date('d/m/Y', strtotime($r->due)).': revierte o hazlo permanente.', '/m/moc?t=change_requests&open=', [], false],
            // M-POL RN-POL-08: política vigente con revisión próxima o vencida.
            ['POL', "select id, title as ref, '' as name, null as owner, review_due_on as due, 'pol' as kind from sst_policies
                      where deleted_at is null and status = 'current' and review_due_on <= current_date + ".self::days('policy_review_notice_days', 30),
                'pol.review_due', 'normal', 'Revisión de la política SST', fn ($r) => "La política «{$r->ref}» debe revisarse antes del ".date('d/m/Y', strtotime($r->due)).'.', '/m/pol?t=sst_policies&open=', ['sst_manager'], true],
            // M-DOC §10: revisión del documento por vencer → responsable del documento.
            ['DOC', "select id, code as ref, title as name, owner_user_id as owner, next_review_on as due, 'doc_soon' as kind from documents
                      where deleted_at is null and status = 'active' and next_review_on between current_date and current_date + ".self::days('doc_review_notice_days', 30),
                'doc.review_due', 'normal', 'Revisión de documento por vencer', fn ($r) => "{$r->ref} · {$r->name} debe revisarse antes del ".date('d/m/Y', strtotime($r->due)).'.', '/m/doc?t=documents&open=', [], false],
            // M-DOC RN-DOC-04: documento vigente con la revisión vencida (sigue vigente).
            ['DOC', "select id, code as ref, title as name, owner_user_id as owner, next_review_on as due, 'doc' as kind from documents
                      where deleted_at is null and status = 'active' and next_review_on < current_date",
                'doc.review_overdue', 'normal', 'Documento con revisión vencida', fn ($r) => "{$r->ref} · {$r->name} debía revisarse el ".date('d/m/Y', strtotime($r->due)).'.', '/m/doc?t=documents&open=', [], true],
            // M-SCT: póliza propia por vencer.
            ['SCT', "select id, policy_number as ref, coverage_kind as name, null as owner, ends_on as due, 'sct' as kind from sctr_policies
                      where deleted_at is null and contractor_id is null and status in ('active', 'expiring') and ends_on <= current_date + ".self::days('sctr_policy_notice_days', 15),
                'sct.policy_expiring', 'high', 'Póliza SCTR por vencer', fn ($r) => "La póliza SCTR N.° {$r->ref} vence el ".date('d/m/Y', strtotime($r->due)).'.', '/m/sct?t=sctr_policies&open=', ['hr', 'sst_manager'], false],
            // M-CTR: acreditación de contratista por vencer.
            ['CTR', "select a.id, c.legal_name as ref, '' as name, null as owner, coalesce(a.expires_on, a.ends_on) as due, 'ctr' as kind from contractor_accreditations a
                      join contractors c on c.id = a.contractor_id
                      where a.deleted_at is null and a.status in ('accredited', 'expiring') and coalesce(a.expires_on, a.ends_on) <= current_date + ".self::days('contractor_doc_notice_days', 15),
                'ctr.accreditation_expiring', 'normal', 'Acreditación de contratista por vencer', fn ($r) => "La acreditación de {$r->ref} vence el ".date('d/m/Y', strtotime($r->due)).'.', '/m/ctr?t=contractor_accreditations&open=', self::SST_ROLES, false],
            // M-MAG: campaña de monitoreo próxima.
            ['MAG', "select id, title as ref, '' as name, null as owner, starts_on as due, 'mag' as kind from monitoring_campaigns
                      where deleted_at is null and status = 'planned' and starts_on between current_date and current_date + ".self::days('monitoring_notice_days', 15),
                'mag.upcoming', 'low', 'Monitoreo próximo', fn ($r) => "La campaña «{$r->ref}» empieza el ".date('d/m/Y', strtotime($r->due)).'.', '/m/mag?t=monitoring_campaigns&open=', ['sst_manager'], false],
            // M-CAP §10: convocatoria a sesión (session_notice_days antes) → convocados, sus jefes de área y el capacitador.
            ['CAP', "select s.id, t.title as ref, '' as name, s.instructor_user_id as owner, (select mw.user_id from areas ar join workers mw on mw.id = ar.manager_worker_id where ar.id = w.current_area_id) as owner2, a.worker_id as worker, s.starts_at as due, 'session' as kind
                       from training_sessions s join trainings t on t.id = s.training_id
                       join training_attendances a on a.session_id = s.id and a.deleted_at is null and a.worker_id is not null
                       join workers w on w.id = a.worker_id
                      where s.deleted_at is null and s.status = 'scheduled' and s.starts_at between now() and now() + make_interval(days => ".self::days('session_notice_days', 2).')',
                'cap.session_call', 'normal', 'Convocatoria a capacitación', fn ($r) => "Sesión de «{$r->ref}» el ".date('d/m/Y H:i', strtotime($r->due)).'.', '/m/cap?t=training_sessions&open=', [], false,
                fn ($r) => "Estás convocado a la capacitación «{$r->ref}» el ".date('d/m/Y H:i', strtotime($r->due)).'.'],
            // M-CAP §10: trabajador nuevo sin inducción al cumplirse induction_grace_days desde su ingreso → Jefe SST y RR.HH.
            ['CAP', "select w.id, w.full_name as ref, '' as name, null as owner, w.hire_on as due, 'induction' as kind from workers w
                      where w.deleted_at is null and w.status = 'active' and w.hire_on is not null
                        and w.hire_on + ".self::days('induction_grace_days', 7)." between current_date - 7 and current_date
                        and not exists (select 1 from training_certificates c join trainings t on t.id = c.training_id and t.training_type = 'induction'
                                         where c.worker_id = w.id and c.status in ('validated', 'pending_validation') and c.deleted_at is null)",
                'cap.no_induction', 'normal', 'Trabajador nuevo sin inducción', fn ($r) => "{$r->ref} ingresó el ".date('d/m/Y', strtotime($r->due)).' y aún no tiene su inducción.', '/m/trb?t=workers&open=', ['sst_manager', 'hr'], false],
            // M-CAP §10: certificados por validar (resumen diario) → Jefe SST.
            ['CAP', "select c.id, count(*)::text as ref, '' as name, current_date::text as dk, null as owner, current_date as due, 'to_validate' as kind
                       from companies c join training_certificates t on t.status = 'pending_validation' and t.deleted_at is null
                      where c.id = nullif(current_setting('app.company_id', true), '')::uuid group by c.id",
                'cap.pending_validation', 'low', 'Certificados por validar', fn ($r) => "Hay {$r->ref} certificado(s) por validar.", '/m/cap?t=training_certificates&ref=', ['sst_manager'], false],
            // M-EMO §10: recordatorio de la cita (emo_appointment_reminder_days antes) → trabajador, sin datos clínicos.
            ['EMO', "select o.id, '' as ref, '' as name, null as owner, o.worker_id as worker, o.scheduled_at as due, to_char(o.scheduled_at, 'YYYYMMDD') as dk, 'appt' as kind from medical_exam_orders o
                      where o.deleted_at is null and o.status = 'scheduled' and o.scheduled_at is not null
                        and (o.scheduled_at at time zone 'America/Lima')::date = current_date + ".self::days('emo_appointment_reminder_days', 1),
                'emo.appointment_reminder', 'normal', 'Recordatorio de examen médico', fn ($r) => 'Examen médico ocupacional el '.date('d/m/Y H:i', strtotime($r->due)).'.', '/m/emo?t=medical_exam_orders&open=', [], false,
                fn ($r) => 'Recuerda tu examen médico ocupacional el '.date('d/m/Y H:i', strtotime($r->due)).'.'],
            // M-EQE §10: equipo sin inspección mensual en el último mes → responsable del área.
            ['EQE', "select e.id, e.code as ref, e.equipment_type as name, (select mw.user_id from areas ar join workers mw on mw.id = ar.manager_worker_id where ar.id = e.area_id) as owner, current_date as due, to_char(current_date, 'YYYY-MM') as dk, 'monthly' as kind
                       from emergency_equipment e
                      where e.deleted_at is null and e.status <> 'retired' and coalesce(e.installed_on, e.created_at::date) < current_date - interval '1 month'
                        and not exists (select 1 from equipment_checks c where c.equipment_id = e.id and c.check_type = 'monthly_inspection' and c.deleted_at is null
                                          and c.checked_at > now() - interval '1 month')",
                'eqe.monthly_overdue', 'normal', 'Inspección mensual vencida', fn ($r) => "El equipo {$r->ref} no tiene su inspección mensual.", '/m/eqe?t=emergency_equipment&open=', [], false],
            // M-EQE §10: botiquín con ítems vencidos o faltantes → su responsable (una vez por semana mientras siga así).
            ['EQE', "select k.id, k.code as ref, '' as name, (select w.user_id from workers w where w.id = k.responsible_worker_id) as owner, k.responsible_worker_id as worker, current_date as due, 'kit' as kind
                       from first_aid_kits k
                      where k.deleted_at is null and k.status <> 'retired'
                        and exists (select 1 from first_aid_kit_items i where i.kit_id = k.id and i.deleted_at is null
                                      and (i.expires_on < current_date or coalesce(i.current_quantity, 0) < coalesce(i.required_quantity, 0)))",
                'eqe.kit_items', 'normal', 'Botiquín con ítems vencidos o faltantes', fn ($r) => "El botiquín {$r->ref} tiene ítems vencidos o faltantes: repónlos.", '/m/eqe?t=first_aid_kits&open=', [], true],
            // M-IPC §10: control propuesto vencido → responsable del control.
            ['IPC', "select c.id, c.description as ref, '' as name, c.responsible_user_id as owner, c.due_on as due, 'control' as kind from iperc_controls c
                      where c.deleted_at is null and c.status = 'proposed' and c.due_on < current_date",
                'ipc.control_overdue', 'normal', 'Control del IPERC vencido', fn ($r) => "El control «".mb_substr((string) $r->ref, 0, 100)."» debía implementarse el ".date('d/m/Y', strtotime($r->due)).'.', '/m/ipc?t=iperc_controls&open=', [], false],
            // M-MAG RN-MAG-07: punto sin medición en monitoring_frequency_months → Jefe SST (semanal mientras siga así).
            ['MAG', "select p.id, p.name as ref, '' as name, null as owner, current_date as due, 'point' as kind from monitoring_points p
                      where p.deleted_at is null and p.status = 'active'
                        and not exists (select 1 from measurements m where m.monitoring_point_id = p.id and m.deleted_at is null
                                          and m.measured_at > now() - make_interval(months => ".self::days('monitoring_frequency_months', 12).'))
                        and p.created_at < now() - make_interval(months => '.self::days('monitoring_frequency_months', 12).')',
                'mag.point_unmonitored', 'normal', 'Punto sin monitoreo', fn ($r) => "El punto «{$r->ref}» no tiene medición en el periodo que corresponde.", '/m/mag?t=monitoring_points&open=', ['sst_manager'], true],
            // M-PAS §10: actividad del mes por iniciar (activity_notice_days antes del inicio del mes) → responsable.
            ['PAS', "select a.id, a.title as ref, to_char(date_trunc('month', current_date + ".self::days('activity_notice_days', 5).'), \'MM/YYYY\') as name,
                            a.responsible_user_id as owner, date_trunc(\'month\', current_date + '.self::days('activity_notice_days', 5).")::date as due,
                            to_char(date_trunc('month', current_date + ".self::days('activity_notice_days', 5)."), 'YYYY-MM') as dk, 'pas' as kind
                       from program_activities a join annual_programs p on p.id = a.program_id and p.deleted_at is null and p.status in ('approved', 'in_execution')
                      where a.deleted_at is null and a.status in ('planned', 'in_progress')
                        and p.year = extract(year from date_trunc('month', current_date + ".self::days('activity_notice_days', 5)."))
                        and extract(month from date_trunc('month', current_date + ".self::days('activity_notice_days', 5)."))::int2 = any(a.planned_months)",
                'pas.activity_upcoming', 'normal', 'Actividad del programa este mes', fn ($r) => "La actividad «{$r->ref}» está programada para {$r->name}.", '/m/pas?t=program_activities&open=', [], false],
            // M-PAS §10: actividad del mes que cerró sin ejecutarse → responsable y Jefe SST.
            ['PAS', "select a.id, a.title as ref, to_char(current_date - interval '1 month', 'MM/YYYY') as name, a.responsible_user_id as owner, current_date as due,
                            to_char(current_date - interval '1 month', 'YYYY-MM') as dk, 'pas_late' as kind
                       from program_activities a join annual_programs p on p.id = a.program_id and p.deleted_at is null and p.status in ('approved', 'in_execution')
                      where a.deleted_at is null and a.status in ('planned', 'in_progress', 'overdue')
                        and p.year = extract(year from current_date - interval '1 month')
                        and extract(month from current_date - interval '1 month')::int2 = any(a.planned_months)
                        and not exists (select 1 from activity_executions x where x.activity_id = a.id and x.deleted_at is null
                                          and date_trunc('month', x.executed_on) = date_trunc('month', current_date - interval '1 month'))",
                'pas.activity_overdue', 'normal', 'Actividad del programa vencida', fn ($r) => "La actividad «{$r->ref}» de {$r->name} no se ejecutó.", '/m/pas?t=program_activities&open=', ['sst_manager'], false],
            // M-PAS §10: avance mensual del programa (día program_report_day del mes siguiente) → comité y gerencia, por correo.
            ['PAS', "select p.id, p.title as ref, round(coalesce(p.progress_pct, 0))::text as name, to_char(current_date - interval '1 month', 'YYYY-MM') as dk, null as owner, current_date as due, 'pas_month' as kind
                       from annual_programs p where p.deleted_at is null and p.status in ('approved', 'in_execution')
                        and extract(day from current_date) >= ".self::days('program_report_day', 5),
                'pas.monthly_progress', 'low', 'Avance mensual del programa SST', fn ($r) => "El programa «{$r->ref}» va en {$r->name} % de avance.", '/m/pas?t=annual_programs&open=', ['committee_member', 'management'], false],
            // M-SIM RN-SIM-04: brigada activa sin líder o con menos de min_brigade_members → Jefe SST (al quedar bajo el mínimo).
            ['SIM', "select b.id, b.name as ref, '' as name, null as owner, current_date as due,
                            (select count(*) from brigade_members m where m.brigade_id = b.id and m.status = 'active' and m.deleted_at is null)::text || coalesce(b.leader_worker_id::text, 'sin') as dk, 'brigade' as kind
                       from brigades b where b.deleted_at is null and b.status = 'active'
                        and (b.leader_worker_id is null or (select count(*) from brigade_members m where m.brigade_id = b.id and m.status = 'active' and m.deleted_at is null) < ".max(1, self::days('min_brigade_members', 2)).')',
                'sim.brigade_incomplete', 'normal', 'Brigada incompleta', fn ($r) => "La brigada «{$r->ref}» está sin líder o bajo el mínimo de miembros.", '/m/sim?t=brigades&open=', ['sst_manager'], false],
            // M-TEN §10: trabajadores activos alcanzan el umbral del Comité (committee_threshold_workers) sin modelo de comité.
            ['TEN', "select c.id, (select count(*) from workers w where w.status = 'active' and w.deleted_at is null)::text as ref, '' as name, null as owner, current_date as due, 'ten' as kind
                       from companies c where c.id = nullif(current_setting('app.company_id', true), '')::uuid and coalesce(c.sst_model, '') <> 'committee'
                        and (select count(*) from workers w where w.status = 'active' and w.deleted_at is null) >= ".self::days('committee_threshold_workers', 20),
                'ten.committee_threshold', 'high', 'La empresa necesita Comité SST', fn ($r) => "Ya tienen {$r->ref} trabajadores activos: la ley pide Comité de SST en lugar de supervisor.", '/m/ten', ['sst_manager', 'account_admin'], false],
            // M-TEN §10: sede temporal próxima a cerrar (site_close_notice_days) → Jefe SST.
            ['TEN', "select id, name as ref, '' as name, null as owner, ends_on as due, 'site' as kind from sites
                      where deleted_at is null and is_temporary and status <> 'closed' and ends_on between current_date and current_date + ".self::days('site_close_notice_days', 15),
                'ten.site_closing', 'normal', 'Sede temporal por cerrar', fn ($r) => "La sede «{$r->ref}» cierra el ".date('d/m/Y', strtotime($r->due)).'.', '/m/ten?t=sites&open=', ['sst_manager'], false],
            // M-TRB §10: documento del legajo por vencer (worker_doc_notice_days) → Jefe SST y RR.HH.
            ['TRB', "select d.id, w.full_name as ref, d.title as name, null as owner, d.expires_on as due, 'doc' as kind from worker_documents d join workers w on w.id = d.worker_id and w.status = 'active'
                      where d.deleted_at is null and d.status in ('valid', 'expiring') and d.expires_on between current_date and current_date + ".self::days('worker_doc_notice_days', 30),
                'trb.doc_expiring', 'normal', 'Documento del legajo por vencer', fn ($r) => "«{$r->name}» de {$r->ref} vence el ".date('d/m/Y', strtotime($r->due)).'.', '/m/trb?t=worker_documents&open=', ['sst_manager', 'hr'], false],
            // M-AUI §10: auditoría externa legal pendiente (external_audit_notice_days antes de cumplirse external_audit_years) → Jefe SST y gerencia.
            ['AUI', "select a.id, a.code as ref, '' as name, null as owner, (coalesce(a.ends_on, a.starts_on) + make_interval(years => ".self::days('external_audit_years', 2)."))::date as due, 'external' as kind
                       from (select distinct on (company_id) * from audits where deleted_at is null and audit_type = 'legal_mandatory' and status in ('completed', 'closed')
                              order by company_id, coalesce(ends_on, starts_on) desc) a
                      where (coalesce(a.ends_on, a.starts_on) + make_interval(years => ".self::days('external_audit_years', 2)."))::date <= current_date + ".self::days('external_audit_notice_days', 60)."
                        and not exists (select 1 from audits x where x.deleted_at is null and x.audit_type = 'legal_mandatory' and x.status in ('planned', 'in_progress', 'report_draft'))",
                'aui.external_due', 'normal', 'Auditoría externa legal pendiente', fn ($r) => 'Toca la auditoría externa legal del SG-SST antes del '.date('d/m/Y', strtotime($r->due)).': prográmala.', '/m/aui?t=audits&open=', ['sst_manager', 'management'], true],
            // M-SUN §10: autodiagnóstico periódico cada self_diagnostic_days → Jefe SST.
            ['SUN', "select c.id, coalesce(to_char(max(d.diagnostic_on), 'DD/MM/YYYY'), 'nunca') as ref, '' as name, coalesce(max(d.diagnostic_on)::text, 'none') as dk, null as owner, current_date as due, 'self' as kind
                       from companies c left join self_diagnostics d on d.status = 'completed' and d.deleted_at is null
                      where c.id = nullif(current_setting('app.company_id', true), '')::uuid
                      group by c.id having coalesce(max(d.diagnostic_on), '1900-01-01') <= current_date - ".self::days('self_diagnostic_days', 90),
                'sun.self_diagnostic_due', 'low', 'Toca el autodiagnóstico SUNAFIL', fn ($r) => "El último autodiagnóstico es del {$r->ref}: haz uno nuevo.", '/m/sun', ['sst_manager'], false],
            // M-SCT RN-SCT-01/04 (conciliación): trabajador activo que requiere SCTR (puesto o sede de riesgo) sin cobertura vigente → jefe de área y Jefe SST.
            ['SCT', "select w.id, w.full_name as ref, '' as name, (select mw.user_id from areas ar join workers mw on mw.id = ar.manager_worker_id where ar.id = w.current_area_id) as owner, current_date as due, 'uncovered' as kind from workers w
                       left join positions p on p.id = w.current_position_id left join sites s on s.id = w.current_site_id
                      where w.deleted_at is null and w.status = 'active' and (coalesce(p.requires_sctr, false) or coalesce(s.requires_sctr, false))
                        and not exists (select 1 from sctr_coverages v where v.worker_id = w.id and v.deleted_at is null and v.status <> 'cancelled'
                                          and v.covered_from <= current_date and v.covered_until >= current_date)",
                'sct.worker_uncovered', 'high', 'Trabajador sin SCTR', fn ($r) => "{$r->ref} requiere SCTR y no tiene cobertura vigente.", '/m/sct?t=sctr_coverages&worker=', ['sst_manager'], true],
            // M-USR §10: acceso de auditor externo por vencer (auditor_expiry_notice_days) → Jefe SST.
            ['USR', "select m.id, u.full_name as ref, '' as name, null as owner, m.expires_at as due, 'auditor' as kind from memberships m
                       join roles r on r.id = m.role_id and coalesce(r.base_role_code, r.code) = 'external_auditor' join users u on u.id = m.user_id
                      where m.deleted_at is null and m.status = 'active' and m.expires_at between now() and now() + make_interval(days => ".self::days('auditor_expiry_notice_days', 7).')',
                'usr.auditor_expiring', 'normal', 'Acceso de auditor por vencer', fn ($r) => "El acceso del auditor externo {$r->ref} vence el ".date('d/m/Y', strtotime($r->due)).'.', '/configuracion/usuarios', ['sst_manager'], false],
            // M-FIR §10: solicitud de firma por vencer (signature_expiry_notice_days) → solicitante.
            ['FIR', "select id, coalesce(role_in_document, 'firma') as ref, '' as name, requested_by as owner, expires_at as due, 'fir' as kind from signature_requests
                      where deleted_at is null and status = 'pending' and expires_at between now() and now() + make_interval(days => ".self::days('signature_expiry_notice_days', 2).')',
                'fir.request_expiring', 'normal', 'Solicitud de firma por vencer', fn ($r) => 'Una firma que pediste vence el '.date('d/m/Y H:i', strtotime($r->due)).' sin firmarse.', '/m/fir', [], false],
            // M-FIR §10: recordatorio cada signature_reminder_days → firmante (usuario o trabajador).
            ['FIR', "select id, coalesce(role_in_document, 'firma') as ref, '' as name, signer_user_id as owner, signer_worker_id as worker, created_at as due,
                            ((now()::date - created_at::date) / ".max(1, self::days('signature_reminder_days', 2)).")::text as dk, 'fir_rem' as kind from signature_requests
                      where deleted_at is null and status = 'pending' and created_at < now() - make_interval(days => ".max(1, self::days('signature_reminder_days', 2)).')
                        and (expires_at is null or expires_at > now())',
                'fir.signature_reminder', 'normal', 'Recordatorio: firma pendiente', fn ($r) => 'Tienes una firma pendiente en EscudoSST.', '/inicio', [], false,
                fn ($r) => 'Tienes una firma pendiente: entra a tu app, en «Mis pendientes».'],
            // M-CH5 §10: desde la hora talk_missing_check_hour, área con personal y sin charla del día → a su jefe de área.
            ['CH5', "select a.id, a.name as ref, '' as name, (now() at time zone 'America/Lima')::date::text as dk, mw.user_id as owner, (now() at time zone 'America/Lima')::date as due, 'ch5' as kind from areas a
                      join workers mw on mw.id = a.manager_worker_id and mw.user_id is not null
                      where a.deleted_at is null and a.status = 'active'
                        and extract(hour from now() at time zone 'America/Lima') >= ".self::days('talk_missing_check_hour', 10)."
                        and exists (select 1 from workers w where w.current_area_id = a.id and w.status = 'active' and w.deleted_at is null)
                        and not exists (select 1 from safety_talks t where t.area_id = a.id and t.deleted_at is null and t.status <> 'cancelled'
                                          and t.talk_on = (now() at time zone 'America/Lima')::date)",
                'ch5.area_missing', 'normal', 'Área sin charla hoy', fn ($r) => "El área {$r->ref} aún no registra su charla de 5 minutos de hoy.", '/m/ch5?t=safety_talks&area=', [], false],
            // M-OBS RN-OBS-03: en los primeros días del mes, categoría del mes anterior con % seguro bajo bbs_alert_pct → al Jefe SST.
            ['OBS', "select (array_agg(i.id order by i.id))[1] as id, case i.category when 'ppe' then 'EPP' when 'body_position' then 'Posición del cuerpo' when 'tools' then 'Herramientas' when 'procedures' then 'Procedimientos' when 'housekeeping' then 'Orden y limpieza' when 'communication' then 'Comunicación' else 'Otros' end as ref, round(100.0 * count(*) filter (where i.result = 'safe') / count(*)) as name, to_char(current_date - interval '1 month', 'YYYY-MM') as dk,
                            null as owner, current_date as due, 'obs' as kind
                       from behavior_observation_items i join behavior_observations o on o.id = i.observation_id and o.deleted_at is null
                      where i.deleted_at is null and i.result in ('safe', 'at_risk') and extract(day from current_date) <= 5
                        and o.observed_on >= date_trunc('month', current_date - interval '1 month') and o.observed_on < date_trunc('month', current_date)
                      group by i.category
                     having 100.0 * count(*) filter (where i.result = 'safe') / count(*) < ".self::days('bbs_alert_pct', 80),
                'obs.category_low', 'normal', 'Categoría de conducta bajo el umbral', fn ($r) => "En el mes pasado la categoría «{$r->ref}» tuvo solo {$r->name} % de conductas seguras: plantea una acción.", '/m/obs?t=behavior_observation_items&ref=', ['sst_manager'], false],
            // M-REG §10: pasados register_due_notice_days del cierre del mes, ningún registro generado que cubra ese mes.
            ['REG', "select c.id, to_char(current_date - interval '1 month', 'MM/YYYY') as ref, to_char(current_date - interval '1 month', 'YYYY-MM') as dk, '' as name, null as owner, current_date as due, 'reg' as kind from companies c
                      where c.id = nullif(current_setting('app.company_id', true), '')::uuid
                        and extract(day from current_date) >= ".self::days('register_due_notice_days', 10)."
                        and not exists (select 1 from register_exports e where e.deleted_at is null and e.status = 'ready'
                                          and e.period_to >= (date_trunc('month', current_date) - interval '1 day')::date)",
                'reg.period_missing', 'normal', 'Registro del periodo sin generar', fn ($r) => "Aún no se genera ningún registro obligatorio que cubra {$r->ref}.", '/m/reg?t=register_exports&ref=', ['sst_manager'], false],
            // M-MRL §10: evaluación de un requisito legal por vencer (legal_eval_notice_days antes) → al responsable.
            ['MRL', "select id, coalesce(article, 'requisito') as ref, requirement as name, next_evaluation_on::text as dk, responsible_user_id as owner, next_evaluation_on as due, 'mrl' as kind from legal_requirements
                      where deleted_at is null and status = 'active' and coalesce(applies, true)
                        and next_evaluation_on between current_date and current_date + ".self::days('legal_eval_notice_days', 15),
                'mrl.eval_due', 'normal', 'Requisito legal por evaluar', fn ($r) => "Toca evaluar «".mb_substr((string) $r->name, 0, 120)."» antes del ".date('d/m/Y', strtotime($r->due)).'.', '/m/mrl?t=legal_requirements&open=', [], false],
            // M-LBA §10: brecha con fecha meta próxima (gap_due_notice_days) → al responsable.
            ['LBA', "select id, priority as ref, description as name, due_on::text as dk, responsible_user_id as owner, due_on as due, 'gap' as kind from baseline_gaps
                      where deleted_at is null and status in ('open', 'in_progress') and due_on between current_date and current_date + ".self::days('gap_due_notice_days', 7),
                'lba.gap_due', 'normal', 'Brecha por cerrar', fn ($r) => "La brecha «".mb_substr((string) $r->name, 0, 120)."» tiene fecha meta el ".date('d/m/Y', strtotime($r->due)).'.', '/m/lba?t=baseline_gaps&open=', [], false],
            // M-LBA §10: se acerca la reevaluación de la línea base (baseline_reassessment_months desde la última aprobada).
            ['LBA', "select b.id, b.title as ref, '' as name, null as owner, (b.assessment_on + make_interval(months => ".self::days('baseline_reassessment_months', 12)."))::date as due, 'lba' as kind
                       from (select distinct on (company_id) * from baseline_assessments where deleted_at is null and status = 'approved' order by company_id, assessment_on desc) b
                      where (b.assessment_on + make_interval(months => ".self::days('baseline_reassessment_months', 12)."))::date <= current_date + ".self::days('baseline_reassessment_notice_days', 30),
                'lba.reassessment_due', 'normal', 'Reevaluación de la línea base', fn ($r) => "La línea base «{$r->ref}» debe reevaluarse antes del ".date('d/m/Y', strtotime($r->due)).'.', '/m/lba?t=baseline_assessments&open=', ['sst_manager'], true],
            // M-PLA §10: plan_missing_notice_days antes del nuevo año (o ya en el año) sin Plan Anual aprobado para ese año.
            ['PLA', "select c.id, y.yr::text as ref, y.yr::text as dk, '' as name, null as owner, make_date(y.yr, 1, 1) as due, 'pla' as kind
                       from companies c, (select case when current_date >= make_date(extract(year from current_date)::int + 1, 1, 1) - ".self::days('plan_missing_notice_days', 30)."
                                                 then extract(year from current_date)::int + 1 else extract(year from current_date)::int end as yr) y
                      where c.id = nullif(current_setting('app.company_id', true), '')::uuid
                        and not exists (select 1 from annual_plans p where p.deleted_at is null and p.year = y.yr and p.status in ('approved', 'current', 'closed'))",
                'pla.plan_missing', 'high', 'Año sin Plan Anual aprobado', fn ($r) => "Aún no hay Plan Anual de SST aprobado para {$r->ref}.", '/m/pla?t=annual_plans&ref=', ['sst_manager', 'management'], true],
            // M-PCA §10: tema del programa aprobado para el mes que viene (training_plan_notice_days antes) sin capacitación creada.
            ['PCA', "select i.id, i.topic as ref, to_char(make_date(p.year, i.planned_month, 1), 'MM/YYYY') as name, null as owner, make_date(p.year, i.planned_month, 1) as due, 'pca' as kind
                       from training_program_items i join training_programs p on p.id = i.training_program_id and p.deleted_at is null and p.status in ('approved', 'in_execution')
                      where i.deleted_at is null and i.training_id is null and i.planned_month between 1 and 12
                        and make_date(p.year, i.planned_month, 1) between current_date - 31 and current_date + ".self::days('training_plan_notice_days', 15),
                'pca.topic_without_training', 'normal', 'Tema del programa sin capacitación', fn ($r) => "El tema «{$r->ref}» está programado para {$r->name} y aún no tiene capacitación creada.", '/m/pca?t=training_program_items&open=', ['sst_manager'], true],
            // M-SCT §10: desde el día sctr_period_due_day, mes sin ninguna constancia propia de SCTR cargada → a RR.HH.
            ['SCT', "select c.id, to_char(current_date, 'MM/YYYY') as ref, to_char(current_date, 'YYYY-MM') as dk, '' as name, null as owner, current_date as due, 'sct' as kind from companies c
                      where c.id = nullif(current_setting('app.company_id', true), '')::uuid
                        and extract(day from current_date) >= ".self::days('sctr_period_due_day', 5)."
                        and exists (select 1 from sctr_policies p where p.deleted_at is null and p.contractor_id is null and p.status = 'active')
                        and not exists (select 1 from sctr_coverages v join sctr_policies p on p.id = v.sctr_policy_id and p.contractor_id is null
                                         where v.deleted_at is null and v.worker_id is not null and v.covered_until >= date_trunc('month', current_date)
                                           and v.covered_from < date_trunc('month', current_date) + interval '1 month')",
                'sct.period_missing', 'normal', 'Periodo sin constancia de SCTR', fn ($r) => "Aún no se carga la constancia de SCTR del periodo {$r->ref}.", '/m/sct?t=sctr_coverages&ref=', ['hr'], false],
            // M-PET §10: puesto crítico sin PETS vigente → al Jefe SST (una vez por semana).
            ['PET', "select p.id, p.name as ref, '' as name, null as owner, current_date as due, 'pet' as kind from positions p
                      where p.deleted_at is null and p.status = 'active' and p.is_critical
                        and not exists (select 1 from procedures r join procedure_versions v on v.id = r.current_version_id and v.status in ('approved', 'current')
                                         where r.position_id = p.id and r.deleted_at is null and r.status = 'active')",
                'pet.critical_without_pets', 'normal', 'Tarea crítica sin PETS', fn ($r) => "El puesto crítico «{$r->ref}» no tiene un PETS vigente.", '/m/pet?t=procedures&position=', ['sst_manager'], true],
            // M-ATS §10: ATS sin cierre al fin de la jornada (desde la hora ats_close_check_hour) → al supervisor.
            ['ATS', "select id, code as ref, task as name, supervisor_user_id as owner, performed_on as due, 'ats' as kind from ats_forms
                      where deleted_at is null and status in ('signed', 'in_execution')
                        and (performed_on < (now() at time zone 'America/Lima')::date
                             or (performed_on = (now() at time zone 'America/Lima')::date and extract(hour from now() at time zone 'America/Lima') >= ".self::days('ats_close_check_hour', 18)."))",
                'ats.not_closed', 'normal', 'ATS sin cerrar', fn ($r) => "El ATS {$r->ref} del ".date('d/m/Y', strtotime($r->due)).' sigue abierto: ciérralo.', '/m/ats?t=ats_forms&open=', [], false],
            // M-EST §10: pasado el día man_hours_due_day, el mes anterior sin horas-hombre registradas.
            ['EST', "select c.id, to_char(current_date - interval '1 month', 'MM/YYYY') as ref, to_char(current_date - interval '1 month', 'YYYY-MM') as dk, '' as name, null as owner, current_date as due, 'est' as kind from companies c
                      where c.id = nullif(current_setting('app.company_id', true), '')::uuid
                        and extract(day from current_date) >= ".self::days('man_hours_due_day', 5)."
                        and not exists (select 1 from monthly_statistics m where m.deleted_at is null and coalesce(m.hours_worked, 0) > 0
                                          and m.period_year = extract(year from current_date - interval '1 month') and m.period_month = extract(month from current_date - interval '1 month'))",
                'est.month_missing', 'normal', 'Mes sin horas-hombre', fn ($r) => "Aún no se registran las horas-hombre de {$r->ref}: sin ellas no se calculan los índices del mes.", '/m/est?t=monthly_statistics&open=', ['hr', 'sst_manager'], false],
            // M-EST §10: mes con estadísticas sin cerrar, stats_close_notice_days después del fin de mes → Jefe SST.
            ['EST', "select m.id, to_char(make_date(m.period_year, m.period_month, 1), 'MM/YYYY') as ref, '' as name, null as owner, current_date as due, 'est_close' as kind from monthly_statistics m
                      where m.deleted_at is null and not m.is_closed
                        and (make_date(m.period_year, m.period_month, 1) + interval '1 month')::date + ".self::days('stats_close_notice_days', 10)." <= current_date",
                'est.month_not_closed', 'normal', 'Mes de estadísticas sin cerrar', fn ($r) => "Las estadísticas de {$r->ref} siguen sin cerrar.", '/m/est?t=monthly_statistics&open=', ['sst_manager'], true],
            // M-ATS §10: ATS firmados tardíamente el día anterior (RN-ATS-02) → Jefe SST, en un aviso diario resumido.
            ['ATS', "select c.id, count(distinct p.ats_form_id)::text as ref, '' as name, (current_date - 1)::text as dk, null as owner, current_date - 1 as due, 'ats_late' as kind from companies c
                       join ats_participants p on p.late_signature and p.deleted_at is null and (p.signed_at at time zone 'America/Lima')::date = current_date - 1
                      where c.id = nullif(current_setting('app.company_id', true), '')::uuid group by c.id",
                'ats.late_signature', 'low', 'ATS firmados tarde', fn ($r) => "Ayer se firmaron tarde {$r->ref} ATS (después del inicio del trabajo).", '/m/ats?t=ats_forms&ref=', ['sst_manager'], false],
            // M-PRE §10: línea del presupuesto que llega al budget_line_alert_pct de lo planificado.
            ['PRE', "select l.id, l.description as ref, b.title as name, null as owner, current_date as due, 'pre' as kind from budget_lines l
                      join budgets b on b.id = l.budget_id and b.deleted_at is null and b.status in ('approved', 'in_execution')
                      where l.deleted_at is null and l.planned_amount > 0 and l.executed_amount >= l.planned_amount * ".self::days('budget_line_alert_pct', 80)." / 100.0",
                'pre.line_alert', 'normal', 'Presupuesto casi agotado', fn ($r) => "La línea «{$r->ref}» del presupuesto «{$r->name}» pasó el porcentaje de alerta de lo planificado.", '/m/pre?t=budget_lines&open=', ['sst_manager'], false],
            // M-SUN §10: enlace del inspector por vencer (share_link_notice_hours antes) → al Jefe SST.
            ['SUN', "select id, coalesce(recipient_name, 'inspector') as ref, '' as name, null as owner, expires_at as due, 'sun' as kind from dossier_share_links
                      where deleted_at is null and status = 'active' and expires_at between now() and now() + make_interval(hours => ".self::days('share_link_notice_hours', 24).")",
                'sun.link_expiring', 'low', 'Enlace del inspector por vencer', fn ($r) => "El enlace del expediente para {$r->ref} vence el ".date('d/m/Y H:i', strtotime($r->due)).'.', '/m/sun?t=dossier_share_links&open=', ['sst_manager'], false],
            // M-COM §10: al terminar el mes, comité activo que no registró sesión ordinaria en ese mes → Jefe SST y presidente.
            ['COM', "select c.id, c.name as ref, to_char(current_date - interval '1 month', 'MM/YYYY') as name, to_char(current_date - interval '1 month', 'YYYY-MM') as dk,
                            (select w.user_id from committee_members cm join workers w on w.id = cm.worker_id
                              where cm.committee_id = c.id and cm.role_in_committee = 'president' and cm.status = 'active' and cm.deleted_at is null limit 1) as owner,
                            current_date as due, 'com' as kind from committees c
                      where c.deleted_at is null and c.status = 'active'
                        and not exists (select 1 from committee_meetings m where m.committee_id = c.id and m.deleted_at is null and m.meeting_type = 'ordinary'
                                          and m.status in ('held', 'minutes_draft', 'minutes_signed')
                                          and date_trunc('month', coalesce(m.held_at, m.scheduled_at)) = date_trunc('month', current_date - interval '1 month'))",
                'com.no_monthly_meeting', 'normal', 'Sesión mensual no registrada', fn ($r) => "«{$r->ref}» no registró su sesión ordinaria de {$r->name}.", '/m/com?t=committees&open=', ['sst_manager'], false],
            // M-HOM RN-HOM-05: la calificación del proveedor por vencer se alerta (vendor_notice_days).
            ['HOM', "select e.id, coalesce(e.vendor_name, e.vendor_ruc) as ref, '' as name, e.evaluator_user_id as owner, e.valid_until as due, 'hom' as kind from vendor_evaluations e
                      where e.deleted_at is null and e.status = 'approved' and e.valid_until between current_date and current_date + ".(int) \App\Core\Support\LegalParams::number('vendor_notice_days', 30),
                'hom.expiring', 'normal', 'Homologación de proveedor por vencer', fn ($r) => "La homologación de {$r->ref} vence el ".date('d/m/Y', strtotime($r->due)).'.', '/m/hom?t=vendor_evaluations&open=', self::SST_ROLES, false],
            // M-FOT §10: fotocheck por vencer → trabajador y jefe del área (credential_expiry_notice_days).
            ['FOT', "select c.id, c.credential_number as ref, '' as name, (select mw.user_id from areas ar join workers mw on mw.id = ar.manager_worker_id where ar.id = w.current_area_id) as owner, c.worker_id as worker, c.expires_on as due, 'fot' as kind
                       from credentials c left join workers w on w.id = c.worker_id
                      where c.deleted_at is null and c.status = 'active' and c.expires_on between current_date and current_date + ".self::days('credential_expiry_notice_days', 15),
                'fot.expiring', 'low', 'Fotocheck por vencer', fn ($r) => "El fotocheck {$r->ref} vence el ".date('d/m/Y', strtotime($r->due)).'.', '/m/fot?t=credentials&open=', [], false,
                fn ($r) => 'Tu fotocheck vence el '.date('d/m/Y', strtotime($r->due)).'.'],
            // M-CAP §10: capacitación por vencer → trabajador, jefe del área y Jefe SST (training_expiry_notice_days).
            ['CAP', "select c.id, c.holder_name as ref, c.course_title as name, (select mw.user_id from areas ar join workers mw on mw.id = ar.manager_worker_id where ar.id = w.current_area_id) as owner, c.worker_id as worker, c.expires_on as due, 'cert' as kind
                       from training_certificates c left join workers w on w.id = c.worker_id
                      where c.deleted_at is null and c.status = 'validated' and c.expires_on between current_date and current_date + ".self::days('training_expiry_notice_days', 30),
                'cap.expiring', 'normal', 'Capacitación por vencer', fn ($r) => "La capacitación «{$r->name}» de {$r->ref} vence el ".date('d/m/Y', strtotime($r->due)).'.', '/m/cap?t=training_certificates&open=', ['sst_manager'], false,
                fn ($r) => "Tu capacitación «{$r->name}» vence el ".date('d/m/Y', strtotime($r->due)).'.'],
            // M-CAP §10: capacitación vencida que el puesto exige (sin otra vigente) → trabajador, jefe del área y Jefe SST, al vencer.
            ['CAP', "select c.id, c.holder_name as ref, c.course_title as name, (select mw.user_id from areas ar join workers mw on mw.id = ar.manager_worker_id where ar.id = w.current_area_id) as owner, c.worker_id as worker, c.expires_on as due, 'cert_req' as kind
                       from training_certificates c join workers w on w.id = c.worker_id and w.status = 'active'
                      where c.deleted_at is null and c.status = 'expired'
                        and exists (select 1 from position_training_requirements q where q.position_id = w.current_position_id and q.training_id = c.training_id and q.is_active and q.deleted_at is null)
                        and not exists (select 1 from training_certificates n where n.worker_id = c.worker_id and n.training_id = c.training_id and n.status = 'validated' and n.deleted_at is null)",
                'cap.expired_required', 'high', 'Capacitación vencida exigida por el puesto', fn ($r) => "La capacitación «{$r->name}» de {$r->ref} venció y su puesto la exige.", '/m/cap?t=training_certificates&open=', ['sst_manager'], false,
                fn ($r) => "Tu capacitación «{$r->name}» venció y tu puesto la exige: coordina con SST."],
            // M-FOT §10: fotocheck que pasa a no habilitado → trabajador, jefe del área y Jefe SST (cada vez que se bloquea).
            ['FOT', "select c.id, c.credential_number as ref, '' as name, (select mw.user_id from areas ar join workers mw on mw.id = ar.manager_worker_id where ar.id = w.current_area_id) as owner, c.worker_id as worker, c.last_evaluated_at as due,
                            to_char(c.last_evaluated_at, 'YYYYMMDDHH24MI') as dk, 'blocked' as kind
                       from credentials c left join workers w on w.id = c.worker_id
                      where c.deleted_at is null and c.status = 'blocked' and c.last_evaluated_at > now() - interval '1 day'",
                'fot.blocked', 'normal', 'Fotocheck no habilitado', fn ($r) => "El fotocheck {$r->ref} quedó no habilitado: revisa sus requisitos.", '/m/fot?t=credentials&open=', ['sst_manager'], false,
                fn ($r) => 'Tu fotocheck quedó no habilitado: acércate a SST para ver qué requisito falta.'],
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
        foreach ($checks as $check) {
            [$module, $sql, $event, $priority, $title, $body, $link, $roles, $weekly] = $check;
            $workerBody = $check[9] ?? $body;
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
                $workerUser = isset($r->worker) && $r->worker ? $db->selectOne('select user_id from workers where id = ?', [$r->worker])?->user_id : null;
                $to = array_diff(array_unique(array_filter([...$team, $r->owner, $r->owner2 ?? null, $r->owner3 ?? null])), array_filter([$workerUser]));
                $dedupe = $event.':'.$r->id.(isset($r->dk) ? ':'.$r->dk : '').($weekly ? ':'.$week : '');
                foreach ($to as $user) {
                    // La llave única de la tabla es (empresa, dedupe_key): incluye a la persona para avisar a cada una sin chocar.
                    $key = $dedupe.':'.$user;
                    if ($db->selectOne('select 1 as ok from notifications where company_id = ? and recipient_user_id = ? and dedupe_key in (?, ?) and deleted_at is null', [$company, $user, $key, $dedupe])) {
                        continue;
                    }
                    $db->selectOne(
                        'insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, due_at, dedupe_key)
                         values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) returning id',
                        [$company, $event, $module, $r->id, $user, $title, mb_substr($body($r), 0, 500), $link.$r->id, $priority, $r->due, $key]
                    )->id;
                    $created++;
                }
                // Al trabajador (en su app y por WhatsApp si lo aceptó), con un texto pensado para él.
                if (isset($r->worker) && $r->worker) {
                    $created += $db->affectingStatement(
                        'insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, recipient_worker_id, title, body, link, priority, due_at, dedupe_key)
                         values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) on conflict do nothing',
                        [$company, $event, $module, $r->id, $workerUser, $r->worker, $title, mb_substr($workerBody($r), 0, 500), '/mi', $priority, $r->due, $dedupe.':w:'.$r->worker]
                    );
                }
            }
        }

        if (in_array('PSI', $enabled, true)) {
            $created += self::surveyReminders($db, $company);
        }
        $created += AckCampaigns::daily($db, $company, $enabled);
        $created += $this->extras($db, $company, $enabled);

        if ($digest ?? ((int) now('America/Lima')->format('G') === self::days('digest_hour', 7))) {
            $this->email($db, $company);
        }

        return $created;
    }

    /**
     * M-NOT §10 «Resumen diario de pendientes» (a la hora digest_hour): un correo al Jefe SST y a cada jefe de área con sus
     * avisos de la campana sin leer que aún no salieron por correo; también a quien eligió «resumen diario» en sus
     * preferencias. Respeta el correo desactivado (para todo o por módulo) y deja constancia en notification_deliveries.
     */
    private function email(ConnectionInterface $db, string $company): void
    {
        $rows = $db->select(
            "select n.id, n.recipient_user_id as user_id, lower(coalesce(n.subject_type, split_part(n.event_code, '.', 1))) as module, n.title, n.body, n.link, n.priority
               from notifications n
              where n.company_id = ? and n.deleted_at is null and n.read_at is null and n.dismissed_at is null and n.recipient_user_id is not null
                and n.created_at > now() - interval '7 days'
                and not exists (select 1 from notification_deliveries d where d.notification_id = n.id and d.channel = 'email')
                and (n.recipient_user_id in (select m.user_id from memberships m join roles r on r.id = m.role_id
                                              where m.company_id = n.company_id and m.status = 'active' and m.deleted_at is null and coalesce(r.base_role_code, r.code) in ('sst_manager', 'area_lead'))
                     or n.recipient_user_id in (select w.user_id from areas a join workers w on w.id = a.manager_worker_id where a.deleted_at is null and w.user_id is not null)
                     or n.recipient_user_id in (select p.user_id from user_notification_prefs p where p.company_id = n.company_id and p.channel = 'email'
                                                 and p.digest_mode = 'daily' and p.deleted_at is null))
              order by n.created_at limit 2000",
            [$company]
        );
        if ($rows === []) {
            return;
        }
        $byUser = [];
        foreach ($rows as $r) {
            $byUser[$r->user_id][] = ['id' => $r->id, 'module' => $r->module, 'title' => $r->title, 'body' => (string) $r->body, 'link' => (string) $r->link, 'priority' => $r->priority];
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
