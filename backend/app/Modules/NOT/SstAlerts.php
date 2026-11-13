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

    private static function days(string $key, int $default): int
    {
        return max(0, (int) \App\Core\Support\LegalParams::number($key, $default));
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
            // ── Avisos §10 de las fichas (revisión diaria). Los plazos salen de los parámetros legales (a confirmar por la empresa). ──
            // M-PTR: permiso en ejecución que pasó su horario sin cerrarse.
            ['PTR', "select id, code as ref, work_description as name, requested_by as owner, valid_until as due, 'ptr' as kind from work_permits
                      where deleted_at is null and status in ('active', 'suspended') and valid_until < now()",
                'ptr.not_closed', 'high', 'Permiso de trabajo sin cerrar', fn ($r) => "El permiso {$r->ref} terminó su horario el ".date('d/m/Y H:i', strtotime($r->due)).' y sigue abierto: ciérralo o amplíalo.', '/m/ptr?t=work_permits&open=', self::SST_ROLES, false],
            // M-INV RN-INV-04: investigación por vencer o vencida.
            ['INV', "select i.id, i.code as ref, '' as name, null as owner, i.due_on as due, 'inv' as kind from investigations i
                      where i.deleted_at is null and i.status in ('open', 'analysis', 'committee_review') and i.due_on <= current_date + ".self::days('investigation_notice_days', 3),
                'inv.due', 'high', 'Investigación por vencer', fn ($r) => "La investigación {$r->ref} ".(strtotime($r->due) < strtotime('today') ? 'venció el ' : 'vence el ').date('d/m/Y', strtotime($r->due)).'.', '/m/inv?t=investigations&open=', self::SST_ROLES, true],
            // M-ACC: evento reportado que sigue sin clasificar (event_triage_hours).
            ['ACC', "select id, code as ref, '' as name, reporter_user_id as owner, reported_at as due, 'triage' as kind from sst_events
                      where deleted_at is null and status = 'reported' and reported_at < now() - make_interval(hours => ".self::days('event_triage_hours', 24).')',
                'acc.untriaged', 'high', 'Evento sin clasificar', fn ($r) => "El evento {$r->ref} se reportó el ".date('d/m/Y H:i', strtotime($r->due)).' y sigue sin clasificar.', '/m/acc?t=sst_events&open=', self::SST_ROLES, false],
            // M-COM RN-COM-07: mandato del comité por vencer; acuerdos vencidos.
            ['COM', "select id, name as ref, '' as name, null as owner, period_end_on as due, 'com' as kind from committees
                      where deleted_at is null and status = 'active' and period_end_on <= current_date + ".self::days('committee_term_notice_days', 60),
                'com.term_ending', 'normal', 'Mandato del comité por vencer', fn ($r) => "El mandato de «{$r->ref}» vence el ".date('d/m/Y', strtotime($r->due)).': prepara la elección.', '/m/com?t=committees&open=', self::SST_ROLES, true],
            ['COM', "select a.id, concat('Acuerdo ', a.number) as ref, a.description as name, a.responsible_user_id as owner, a.due_on as due, 'agr' as kind from meeting_agreements a
                      where a.deleted_at is null and a.status in ('agreed', 'in_progress', 'overdue') and a.due_on < current_date",
                'com.agreement_overdue', 'normal', 'Acuerdo del comité vencido', fn ($r) => "{$r->ref}: «".mb_substr((string) $r->name, 0, 80).'» venció el '.date('d/m/Y', strtotime($r->due)).'.', '/m/com?t=meeting_agreements&open=', self::SST_ROLES, true],
            // M-AUI: auditoría próxima (audit_notice_days).
            ['AUI', "select id, code as ref, scope as name, null as owner, starts_on as due, 'aui' as kind from audits
                      where deleted_at is null and status = 'planned' and starts_on between current_date and current_date + ".self::days('audit_notice_days', 15),
                'aui.upcoming', 'normal', 'Auditoría próxima', fn ($r) => "La auditoría {$r->ref} empieza el ".date('d/m/Y', strtotime($r->due)).'.', '/m/aui?t=audits&open=', self::SST_ROLES, false],
            // M-RVD RN-RVD-04: revisión por la dirección planificada que llega o pasó su fecha.
            ['RVD', "select id, title as ref, '' as name, null as owner, review_on as due, 'rvd' as kind from management_reviews
                      where deleted_at is null and status = 'planned' and review_on <= current_date + ".self::days('management_review_notice_days', 15),
                'rvd.due', 'normal', 'Revisión por la dirección próxima', fn ($r) => "«{$r->ref}» está programada para el ".date('d/m/Y', strtotime($r->due)).'.', '/m/rvd?t=management_reviews&open=', [...self::SST_ROLES, 'management'], true],
            // M-SIM: simulacro próximo (drill_notice_days).
            ['SIM', "select id, code as ref, title as name, null as owner, scheduled_at as due, 'sim' as kind from drills
                      where deleted_at is null and status = 'planned' and scheduled_at between now() and now() + make_interval(days => ".self::days('drill_notice_days', 7).')',
                'sim.upcoming', 'normal', 'Simulacro próximo', fn ($r) => "El simulacro «{$r->name}» está programado para el ".date('d/m/Y H:i', strtotime($r->due)).'.', '/m/sim?t=drills&open=', self::SST_ROLES, false],
            // M-EPP RN-EPP-08: stock por debajo del mínimo.
            ['EPP', "select i.id, i.name as ref, '' as name, null as owner, current_date as due, 'stock' as kind from ppe_items i
                      where i.deleted_at is null and i.is_active and i.min_stock is not null
                        and (select coalesce(sum(case when m.movement_type in ('in','return','adjustment') then m.quantity else -m.quantity end), 0)
                               from ppe_stock_movements m where m.ppe_item_id = i.id and m.deleted_at is null) < i.min_stock",
                'epp.low_stock', 'normal', 'Stock de EPP bajo', fn ($r) => "El stock de {$r->ref} está por debajo del mínimo: repón.", '/m/epp?t=ppe_items&open=', self::SST_ROLES, true],
            // M-EQE RN-EQE-02: mantenimiento o vencimiento de equipos de emergencia.
            ['EQE', "select id, code as ref, equipment_type as name, null as owner, least(next_maintenance_on, expires_on) as due, 'eqe' as kind from emergency_equipment
                      where deleted_at is null and status <> 'retired' and least(next_maintenance_on, expires_on) <= current_date + ".self::days('equipment_notice_days', 15),
                'eqe.due', 'normal', 'Equipo de emergencia por revisar', fn ($r) => "El equipo {$r->ref} necesita mantenimiento o vence el ".date('d/m/Y', strtotime($r->due)).'.', '/m/eqe?t=emergency_equipment&open=', self::SST_ROLES, true],
            // M-MOC RN-MOC-04: cambio temporal que llega a su fecha de fin.
            ['MOC', "select id, code as ref, title as name, requested_by as owner, temporary_until as due, 'moc' as kind from change_requests
                      where deleted_at is null and is_temporary and status in ('approved', 'implemented') and temporary_until <= current_date + 7",
                'moc.temporary_ending', 'normal', 'Cambio temporal por terminar', fn ($r) => "El cambio temporal {$r->ref} termina el ".date('d/m/Y', strtotime($r->due)).': revierte o hazlo permanente.', '/m/moc?t=change_requests&open=', self::SST_ROLES, false],
            // M-POL RN-POL-08: política vigente con revisión próxima o vencida.
            ['POL', "select id, title as ref, '' as name, null as owner, review_due_on as due, 'pol' as kind from sst_policies
                      where deleted_at is null and status = 'current' and review_due_on <= current_date + 30",
                'pol.review_due', 'normal', 'Revisión de la política SST', fn ($r) => "La política «{$r->ref}» debe revisarse antes del ".date('d/m/Y', strtotime($r->due)).'.', '/m/pol?t=sst_policies&open=', [...self::SST_ROLES, 'management'], true],
            // M-DOC RN-DOC-04: documento vigente con la revisión vencida (sigue vigente).
            ['DOC', "select id, code as ref, title as name, owner_user_id as owner, next_review_on as due, 'doc' as kind from documents
                      where deleted_at is null and status = 'active' and next_review_on < current_date",
                'doc.review_overdue', 'normal', 'Documento con revisión vencida', fn ($r) => "{$r->ref} · {$r->name} debía revisarse el ".date('d/m/Y', strtotime($r->due)).'.', '/m/doc?t=documents&open=', [], true],
            // M-SCT: póliza propia por vencer.
            ['SCT', "select id, policy_number as ref, coverage_kind as name, null as owner, ends_on as due, 'sct' as kind from sctr_policies
                      where deleted_at is null and contractor_id is null and status in ('active', 'expiring') and ends_on <= current_date + ".self::days('sctr_policy_notice_days', 15),
                'sct.policy_expiring', 'high', 'Póliza SCTR por vencer', fn ($r) => "La póliza SCTR N.° {$r->ref} vence el ".date('d/m/Y', strtotime($r->due)).'.', '/m/sct?t=sctr_policies&open=', self::SST_ROLES, false],
            // M-CTR: acreditación de contratista por vencer.
            ['CTR', "select a.id, c.legal_name as ref, '' as name, null as owner, coalesce(a.expires_on, a.ends_on) as due, 'ctr' as kind from contractor_accreditations a
                      join contractors c on c.id = a.contractor_id
                      where a.deleted_at is null and a.status in ('accredited', 'expiring') and coalesce(a.expires_on, a.ends_on) <= current_date + ".self::days('contractor_doc_notice_days', 15),
                'ctr.accreditation_expiring', 'normal', 'Acreditación de contratista por vencer', fn ($r) => "La acreditación de {$r->ref} vence el ".date('d/m/Y', strtotime($r->due)).'.', '/m/ctr?t=contractor_accreditations&open=', self::SST_ROLES, false],
            // M-MAG: campaña de monitoreo próxima.
            ['MAG', "select id, title as ref, '' as name, responsible_user_id as owner, starts_on as due, 'mag' as kind from monitoring_campaigns
                      where deleted_at is null and status = 'planned' and starts_on between current_date and current_date + 15",
                'mag.upcoming', 'low', 'Monitoreo próximo', fn ($r) => "La campaña «{$r->ref}» empieza el ".date('d/m/Y', strtotime($r->due)).'.', '/m/mag?t=monitoring_campaigns&open=', self::SST_ROLES, false],
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
                $dedupe = $event.':'.$r->id.(isset($r->dk) ? ':'.$r->dk : '').($weekly ? ':'.$week : '');
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

        if (in_array('PSI', $enabled, true)) {
            $created += self::surveyReminders($db, $company);
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
