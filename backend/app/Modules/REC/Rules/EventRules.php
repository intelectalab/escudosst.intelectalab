<?php

namespace App\Modules\REC\Rules;

use App\Core\Support\LegalParams;
use Illuminate\Support\Facades\DB;

/**
 * ACC · Accidentes, incidentes y enfermedades ocupacionales, e INV · Investigación (docs/modulos/M-ACC.md y M-INV.md §8).
 * - RN-ACC-11: código correlativo ACC-AAAA-NNNN.
 * - RN-ACC-01/02: el plazo de notificación sale de legal_parameters según el tipo de evento (hora de Lima; días hábiles sin feriados).
 * - RN-ACC-03: una notificación registrada después del plazo queda «fuera de plazo» para siempre.
 * - RN-ACC-05: mortal, incapacitante e incidente peligroso abren su investigación; el evento no se cierra sin investigación cerrada.
 * - RN-ACC-06: un accidente necesita al menos un afectado para cerrarse.
 * - RN-ACC-07: los días perdidos del evento son la suma de los de sus afectados.
 * - INV: la investigación no se aprueba sin causas; el evento pasa a «en investigación».
 */
class EventRules extends RecordRules
{
    private const NEEDS_INVESTIGATION = ['fatal_accident', 'disabling_accident', 'dangerous_incident'];

    private const FLOW = [
        'reported' => ['registered', 'notified', 'under_investigation', 'closed', 'discarded'],
        'registered' => ['notified', 'under_investigation', 'closed', 'discarded'],
        'notified' => ['under_investigation', 'closed'],
        'under_investigation' => ['closed', 'discarded'],
        'closed' => [],
        'discarded' => [],
    ];

    /** Personas que cuentan como afectadas (los testigos e involucrados no suman días perdidos). */
    private const AFFECTED = "('injured','affected','ill')";

    private const INV_FLOW = [
        'open' => ['analysis', 'committee_review', 'approved'],
        'analysis' => ['committee_review', 'approved', 'open'],
        'committee_review' => ['approved', 'analysis'],
        'approved' => ['actions_in_progress', 'closed'],
        'actions_in_progress' => ['closed'],
        'closed' => [],
    ];

    public function prepare(string $op, array $res, array &$values, ?object $current, array &$extra): void
    {
        match ($res['table']) {
            'sst_events' => $this->event($op, $values, $current, $extra),
            'event_people' => $this->person($values, $current),
            'event_notifications' => $this->notification($values, $current, $extra),
            'investigations' => $this->investigation($op, $values, $current, $extra),
            'investigation_causes', 'investigation_team' => $this->investigationChild($values, $current),
            default => null,
        };
    }

    public function prepareDelete(array $res, object $current): void
    {
        if (in_array($res['table'], ['event_people', 'event_notifications'], true)) {
            $this->eventOpen($current->event_id);
        }
        if (in_array($res['table'], ['investigation_causes', 'investigation_team'], true)) {
            $this->investigationOpen($current->investigation_id);
        }
        if ($res['table'] === 'event_people') {
            // Al quitar a una persona se recalculan los días perdidos y los afectados del evento.
            DB::update('update sst_events set lost_days = (select coalesce(sum(greatest(lost_days, 0)), 0) from event_people where event_id = ? and id <> ? and deleted_at is null and person_role in '.self::AFFECTED.'),
                        injured_count = (select count(*) from event_people where event_id = ? and id <> ? and deleted_at is null and person_role in '.self::AFFECTED.')
                        where id = ?', [$current->event_id, $current->id, $current->event_id, $current->id, $current->event_id]);
        }
    }

    /** Un evento cerrado o descartado no cambia (ni sus personas ni sus notificaciones): cambiaría las estadísticas y los registros. */
    private function eventOpen(?string $eventId): void
    {
        $st = $eventId ? DB::selectOne('select status from sst_events where id = ?', [$eventId])?->status : null;
        if (in_array($st, ['closed', 'discarded'], true)) {
            $this->conflict('El evento está '.($st === 'closed' ? 'cerrado' : 'descartado').': no se agregan ni cambian sus datos.');
        }
    }

    private function investigationOpen(?string $invId): void
    {
        $st = $invId ? DB::selectOne('select status from investigations where id = ?', [$invId])?->status : null;
        if ($st === 'closed') {
            $this->conflict('La investigación está cerrada: no se agregan ni cambian sus causas ni su equipo.');
        }
        if (in_array($st, ['approved', 'actions_in_progress'], true)) {
            $this->conflict('El informe de la investigación está aprobado: no se cambian sus causas ni su equipo. Si hace falta, agrega una adenda.');
        }
    }

    private function person(array &$values, ?object $current): void
    {
        $eventId = $this->value($values, $current, 'event_id');
        $this->eventOpen($eventId);
        if (($d = $values['lost_days'] ?? null) !== null && (int) $d < 0) {
            $this->fail('lost_days', 'Los días perdidos no pueden ser negativos.');
        }
        // RN-ACC-06: datos de la lesión obligatorios para cada persona lesionada en un accidente (registro R.M. 050).
        $type = $eventId ? DB::selectOne('select event_type from sst_events where id = ?', [$eventId])?->event_type : null;
        if (str_contains((string) $type, 'accident') && $this->value($values, $current, 'person_role') === 'injured') {
            foreach (['injury_type' => 'Indica el tipo de lesión.', 'body_part' => 'Indica la parte del cuerpo lesionada.', 'injury_agent' => 'Indica el agente que causó la lesión.'] as $f => $m) {
                if (trim((string) ($this->value($values, $current, $f) ?? '')) === '') {
                    $this->fail($f, $m);
                }
            }
        }
    }

    private function investigationChild(array &$values, ?object $current): void
    {
        // Las acciones de las causas se asignan también con el informe aprobado (es lo que se pide al aprobar).
        if ($current && array_keys($values) === ['corrective_action_id']) {
            return;
        }
        $this->investigationOpen($this->value($values, $current, 'investigation_id'));
    }

    public function after(string $op, array $res, string $id, array $values, ?object $current): void
    {
        // M-INV §10: informe aprobado → aviso al comité y a gerencia.
        if ($res['table'] === 'investigations' && ($values['status'] ?? null) === 'approved' && $current?->status !== 'approved') {
            $i = DB::selectOne('select i.id, i.code, e.code as event_code from investigations i join sst_events e on e.id = i.event_id where i.id = ?', [$id]);
            \App\Modules\NOT\Notify::users(\App\Modules\NOT\Notify::roles(['committee_member', 'management', 'sst_manager']), 'inv.approved', 'INV', $id, 'Informe de investigación aprobado',
                "Se aprobó la investigación {$i->code} del evento {$i->event_code}: revisa sus causas y acciones.", '/m/inv?t=investigations&open='.$id, 'normal', 'inv.approved:'.$id);
        }
        if ($res['table'] === 'sst_events' && ($op === 'create' || array_key_exists('event_type', $values) || array_key_exists('occurred_at', $values))) {
            $this->obligations($id);
        }
        if ($res['table'] === 'sst_events' && ($op === 'create' || (array_key_exists('event_type', $values) && $values['event_type'] !== ($current?->event_type ?? null)))) {
            $this->notifyRegistered($id);
        }
        if ($res['table'] === 'event_people') {
            $event = DB::selectOne('select event_id from event_people where id = ?', [$id])->event_id;
            DB::update('update sst_events set lost_days = (select coalesce(sum(greatest(lost_days, 0)), 0) from event_people where event_id = ? and deleted_at is null and person_role in '.self::AFFECTED.'),
                        injured_count = (select count(*) from event_people where event_id = ? and deleted_at is null and person_role in '.self::AFFECTED.')
                        where id = ?', [$event, $event, $event]);
        }
        if ($res['table'] === 'event_notifications' && in_array($values['status'] ?? null, ['sent', 'acknowledged'], true)) {
            $n = DB::selectOne('select event_id from event_notifications where id = ?', [$id]);
            DB::update("update sst_events set status = 'notified' where id = ? and status in ('reported','registered')
                         and not exists (select 1 from event_notifications where event_id = ? and status = 'pending' and deleted_at is null)", [$n->event_id, $n->event_id]);
            DB::update('update sst_events set notification_overdue = exists (select 1 from event_notifications where event_id = ? and notification_overdue and deleted_at is null) where id = ?',
                [$n->event_id, $n->event_id]);
        }
        // Evento descartado: su investigación automática (sin causas) se archiva y las notificaciones pendientes dejan de exigirse.
        if ($res['table'] === 'sst_events' && ($values['status'] ?? null) === 'discarded') {
            DB::update("update investigations set deleted_at = now() where event_id = ? and deleted_at is null
                          and not exists (select 1 from investigation_causes c where c.investigation_id = investigations.id and c.deleted_at is null)", [$id]);
            DB::update("update event_notifications set status = 'not_required', notes = concat_ws(' ', notes, '(evento descartado)') where event_id = ? and status = 'pending' and deleted_at is null", [$id]);
        }
        if ($res['table'] === 'investigations' && $op === 'create') {
            DB::update("update sst_events set status = 'under_investigation' where id = (select event_id from investigations where id = ?) and status not in ('closed','discarded')", [$id]);
        }
    }

    private function event(string $op, array &$values, ?object $current, array &$extra): void
    {
        if ($op === 'create') {
            if (! isset($values['code'])) {
                $n = (int) DB::selectOne('select count(*) + 1 as n from sst_events where extract(year from created_at) = extract(year from now())')->n;
                do {
                    $values['code'] = sprintf('ACC-%s-%04d', date('Y'), $n++);
                } while (DB::selectOne('select 1 as ok from sst_events where code = ? and deleted_at is null', [$values['code']]));
            }
            $values['reported_at'] ??= now()->toIso8601String();
            $values['status'] = 'reported';
            $extra['reporter_user_id'] = ['?', [(string) auth()->id()]];
        }
        if (($occ = $values['occurred_at'] ?? null) && strtotime($occ) > time() + 300) {
            $this->fail('occurred_at', 'La fecha del evento no puede ser futura.');
        }
        $to = $values['status'] ?? null;
        if ($op === 'update' && $to && $to !== $current->status) {
            if (! in_array($to, self::FLOW[$current->status] ?? [], true)) {
                $this->fail('status', 'Ese cambio de estado no está permitido.');
            }
            if ($to === 'discarded' && ! $this->value($values, $current, 'discarded_reason')) {
                $this->fail('discarded_reason', 'Para descartar el evento indica el motivo.');
            }
            if ($to === 'discarded' && DB::selectOne("select 1 as ok from investigation_causes c join investigations i on i.id = c.investigation_id
                    where i.event_id = ? and i.deleted_at is null and c.deleted_at is null", [$current->id])) {
                $this->fail('status', 'La investigación ya tiene causas registradas: no se puede descartar el evento. Si no corresponde, ciérrala con sus conclusiones.');
            }
            if ($to === 'closed') {
                $type = $this->value($values, $current, 'event_type');
                if (in_array($type, self::NEEDS_INVESTIGATION, true)
                    && ! DB::selectOne("select 1 as ok from investigations where event_id = ? and status = 'closed' and deleted_at is null", [$current->id])) {
                    $this->fail('status', 'Este evento exige investigación: ciérrala antes de cerrar el evento.');
                }
                if (str_contains((string) $type, 'accident')
                    && ! DB::selectOne("select 1 as ok from event_people where event_id = ? and person_role in ('injured','affected') and deleted_at is null", [$current->id])) {
                    $this->fail('status', 'Registra al menos una persona afectada antes de cerrar el accidente.');
                }
                if (DB::selectOne("select 1 as ok from event_notifications where event_id = ? and status in ('pending', 'overdue') and deleted_at is null", [$current->id])) {
                    $this->fail('status', 'Hay notificaciones obligatorias pendientes.');
                }
                $extra['closed_at'] = ['now()', []];
            }
        }
        if ($current && in_array($current->status, ['closed', 'discarded'], true) && $values !== []) {
            $this->conflict('El evento está cerrado: no se modifica.');
        }
    }

    private function notification(array &$values, ?object $current, array &$extra): void
    {
        $eventId = $this->value($values, $current, 'event_id');
        $this->eventOpen($eventId);
        $to = $values['status'] ?? null;
        if (in_array($to, ['sent', 'acknowledged'], true)) {
            $at = $this->value($values, $current, 'notified_at') ?? now()->toIso8601String();
            $values['notified_at'] = $at;
            $occurred = DB::selectOne('select occurred_at from sst_events where id = ?', [$eventId])?->occurred_at;
            if ($occurred && strtotime($at) < strtotime($occurred)) {
                $this->fail('notified_at', 'La fecha de notificación no puede ser anterior al evento.');
            }
            if (strtotime($at) > time() + 300) {
                $this->fail('notified_at', 'La fecha de notificación no puede ser futura.');
            }
            if (! $this->value($values, $current, 'notification_number') && ! $this->value($values, $current, 'receipt_file_id')) {
                $this->fail('notification_number', 'Indica el número de la notificación o adjunta la constancia: es la prueba de que se notificó.');
            }
            $deadline = $this->value($values, $current, 'deadline_at');
            $extra['notified_by'] = ['?', [(string) auth()->id()]];
            $extra['notification_overdue'] = ['?::boolean', [$deadline && strtotime($at) > strtotime($deadline) ? 'true' : 'false']];
        }
        if ($current && $current->notification_overdue && array_key_exists('notified_at', $values) && $values['notified_at'] !== $current->notified_at) {
            $this->conflict('La notificación ya quedó fuera de plazo: su fecha no se cambia.');
        }
    }

    private function investigation(string $op, array &$values, ?object $current, array &$extra): void
    {
        if ($op === 'create') {
            if (! isset($values['code'])) {
                $n = (int) DB::selectOne('select count(*) + 1 as n from investigations where extract(year from created_at) = extract(year from now())')->n;
                do {
                    $values['code'] = sprintf('INV-%s-%04d', date('Y'), $n++);
                } while (DB::selectOne('select 1 as ok from investigations where code = ? and deleted_at is null', [$values['code']]));
            }
            $values['started_on'] ??= date('Y-m-d');
            $values['due_on'] ??= date('Y-m-d', strtotime('+'.(int) LegalParams::number('investigation_due_days', 10).' days'));
        }
        $to = $values['status'] ?? null;
        if ($to && $current && $to !== $current->status) {
            if (! in_array($to, self::INV_FLOW[$current->status] ?? [], true)) {
                $this->fail('status', $current->status === 'closed' ? 'La investigación ya está cerrada.' : 'Ese cambio de estado no está permitido: apruébala antes de cerrarla.');
            }
            if ($to === 'approved') {
                $levels = array_column(DB::select('select distinct cause_level from investigation_causes where investigation_id = ? and deleted_at is null', [$current->id]), 'cause_level');
                if (! in_array('immediate', $levels, true) || ! array_intersect(['basic', 'root', 'lack_of_control'], $levels)) {
                    $this->fail('status', 'Registra al menos una causa inmediata y una causa básica (o raíz) antes de aprobar la investigación.');
                }
                if (! DB::selectOne("select 1 as ok from investigation_team where investigation_id = ? and role = 'leader' and deleted_at is null", [$current->id])) {
                    $this->fail('status', 'Indica quién lidera la investigación en el equipo investigador.');
                }
                if (trim((string) $this->value($values, $current, 'conclusions')) === '') {
                    $this->fail('conclusions', 'Escribe las conclusiones de la investigación antes de aprobarla.');
                }
                // RN-INV-02: cada causa básica o raíz lleva su acción correctiva.
                if (DB::selectOne("select 1 as ok from investigation_causes where investigation_id = ? and deleted_at is null
                                    and cause_level in ('basic', 'root', 'lack_of_control') and corrective_action_id is null", [$current->id])) {
                    $this->fail('status', 'Cada causa básica o raíz debe tener su acción correctiva antes de aprobar la investigación.');
                }
                // RN-INV-03: el equipo incluye al representante del comité (o al supervisor SST).
                if (! DB::selectOne("select 1 as ok from investigation_team where investigation_id = ? and role = 'committee_representative' and deleted_at is null", [$current->id])) {
                    $model = DB::selectOne('select sst_model from companies where id = ?', [$current->company_id])?->sst_model;
                    $this->fail('status', $model === 'supervisor'
                        ? 'Agrega al supervisor de SST al equipo investigador (rol «Representante del comité»).'
                        : 'Agrega a un miembro del comité de SST al equipo investigador (rol «Representante del comité»).');
                }
                // RN-INV-06: se identifica una causa de gestión (factores del trabajo, sistema de gestión o falta de control) o se justifica.
                if (! DB::selectOne("select 1 as ok from investigation_causes where investigation_id = ? and deleted_at is null
                                      and (cause_category in ('job_factor', 'management_system') or cause_level = 'lack_of_control')", [$current->id])
                    && trim((string) $this->value($values, $current, 'management_cause_justification')) === '') {
                    $this->fail('management_cause_justification', 'Identifica una causa de gestión (factor del trabajo, sistema de gestión o falta de control) o justifica por qué no la hay.');
                }
            }
            if ($to === 'approved') {
                $extra['approved_by'] = ['?', [(string) auth()->id()]];
                $extra['approved_at'] = ['now()', []];
            }
            if ($to === 'closed' && DB::selectOne("select 1 as ok from investigation_causes c join corrective_actions a on a.id = c.corrective_action_id
                    where c.investigation_id = ? and c.deleted_at is null and a.status not in ('verified','cancelled')", [$current->id])) {
                $this->fail('status', 'Hay acciones correctivas de las causas sin verificar.');
            }
        }
        // RN-INV-05: aprobado el informe, su contenido no cambia; las precisiones van como adenda (se agregan, no se reemplazan).
        if ($current && in_array($current->status, ['approved', 'actions_in_progress', 'closed'], true)) {
            $locked = array_diff(array_keys($values), ['status', 'addenda']);
            if ($locked !== []) {
                $this->conflict('El informe de la investigación está aprobado: no se modifica. Agrega una adenda con la precisión.');
            }
            if (array_key_exists('addenda', $values)) {
                $old = json_decode((string) ($current->addenda ?? '[]'), true) ?: [];
                $new = is_array($values['addenda']) ? $values['addenda'] : (json_decode((string) $values['addenda'], true) ?: []);
                if (count($new) < count($old) || array_slice($new, 0, count($old)) != $old) {
                    $this->conflict('Las adendas anteriores no se cambian ni se borran: solo se agregan nuevas.');
                }
            }
        }
    }

    /**
     * Aviso al registrar el evento (M-ACC §10, RN-ACC-04): un accidente mortal o incidente peligroso avisa de inmediato,
     * con prioridad crítica (sin horas de silencio), al Jefe SST, la gerencia y el comité SST; un accidente incapacitante,
     * con prioridad alta, al Jefe SST y la gerencia; los demás, al Jefe SST. En todos, también al jefe del área del evento.
     * No se avisa a quien lo registró. La clave incluye el tipo: si el evento se reclasifica a más grave, se vuelve a avisar.
     */
    private function notifyRegistered(string $id): void
    {
        $e = DB::selectOne('select e.id, e.company_id, e.code, e.event_type, e.occurred_at, e.area_id, s.name as site, a.name as area,
                                   (select w.user_id from workers w where w.id = a.manager_worker_id and w.deleted_at is null) as area_lead
                              from sst_events e left join sites s on s.id = e.site_id left join areas a on a.id = e.area_id where e.id = ?', [$id]);
        [$roles, $priority, $label] = match ($e->event_type) {
            'fatal_accident' => [['sst_manager', 'management', 'committee_member'], 'critical', 'Accidente mortal'],
            'dangerous_incident' => [['sst_manager', 'management', 'committee_member'], 'critical', 'Incidente peligroso'],
            'disabling_accident' => [['sst_manager', 'management'], 'high', 'Accidente incapacitante'],
            'occupational_disease' => [['sst_manager'], 'high', 'Enfermedad ocupacional'],
            'minor_accident' => [['sst_manager'], 'normal', 'Accidente leve'],
            default => [['sst_manager'], 'normal', 'Incidente'],
        };
        $users = array_column(DB::select(
            "select distinct m.user_id from memberships m join roles r on r.id = m.role_id
              where m.company_id = ? and m.status = 'active' and m.deleted_at is null and coalesce(r.base_role_code, r.code) = any(?::text[])",
            [$e->company_id, '{'.implode(',', $roles).'}']), 'user_id');
        if ($e->area_lead) {
            $users[] = $e->area_lead;
        }
        $me = (string) auth()->id();
        $where = trim(implode(' · ', array_filter([$e->site, $e->area])));
        $body = "{$label} {$e->code} el ".date('d/m/Y H:i', strtotime($e->occurred_at)).($where !== '' ? " en {$where}" : '').'.'
            .($priority === 'critical' ? ' Atiende la notificación obligatoria y la investigación.' : '');
        foreach (array_unique(array_filter($users, fn ($u) => (string) $u !== $me)) as $u) {
            DB::insert(
                "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, dedupe_key)
                 values (?, 'acc.event_registered', 'sst_events', ?, ?, ?, ?, ?, ?, ?) on conflict do nothing",
                [$e->company_id, $e->id, $u, "{$label} registrado: {$e->code}", mb_substr($body, 0, 500), "/m/acc?t=sst_events&open={$e->id}", $priority,
                    "acc_event:{$e->id}:{$e->event_type}:{$u}"]
            );
        }
    }

    /** Plazo de notificación y apertura de la investigación según el tipo de evento. */
    private function obligations(string $id): void
    {
        $e = DB::selectOne('select * from sst_events where id = ?', [$id]);
        [$deadline, $key] = $this->deadline($e->event_type, $e->occurred_at);
        DB::update('update sst_events set notification_deadline_at = ? where id = ?', [$deadline, $id]);
        if ($deadline && ! DB::selectOne("select 1 as ok from event_notifications where event_id = ? and notification_type = 'initial' and deleted_at is null", [$id])) {
            DB::insert(
                "insert into event_notifications (company_id, event_id, authority, notification_type, legal_parameter_key, deadline_at, status, notes, created_by, updated_by)
                 values (?, ?, 'mtpe_sat', 'initial', ?, ?, 'pending', ?, ?, ?)",
                [$e->company_id, $id, $key, $deadline, $key === 'nonfatal_accident_notification_rule' ? 'La notifica el centro médico asistencial; verifica que se haya hecho.' : null,
                    (string) auth()->id(), (string) auth()->id()]
            );
        }
        if (in_array($e->event_type, self::NEEDS_INVESTIGATION, true) && ! DB::selectOne('select 1 as ok from investigations where event_id = ? and deleted_at is null', [$id])) {
            $n = (int) DB::selectOne('select count(*) + 1 as n from investigations where extract(year from created_at) = extract(year from now())')->n;
            do {
                $code = sprintf('INV-%s-%04d', date('Y'), $n++);
            } while (DB::selectOne('select 1 as ok from investigations where code = ? and deleted_at is null', [$code]));
            DB::insert(
                "insert into investigations (company_id, event_id, code, method, started_on, due_on, status, created_by, updated_by)
                 values (?, ?, ?, 'cause_tree', current_date, current_date + ?::int, 'open', ?, ?)",
                [$e->company_id, $id, $code, (int) LegalParams::number('investigation_due_days', 10), (string) auth()->id(), (string) auth()->id()]
            );
            DB::update("update sst_events set status = 'under_investigation' where id = ? and status = 'reported'", [$id]);
        }
    }

    /** @return array{0: ?string, 1: ?string} [plazo ISO, clave del parámetro] */
    private function deadline(string $type, string $occurred): array
    {
        $tz = new \DateTimeZone('America/Lima');
        $at = new \DateTimeImmutable($occurred);
        $at = $at->setTimezone($tz);
        $on = $at->format('Y-m-d');
        switch ($type) {
            case 'fatal_accident':
                return [$at->modify('+'.(int) LegalParams::number('fatal_accident_notification_hours', 24, $on).' hours')->format(DATE_ATOM), 'fatal_accident_notification_hours'];
            case 'dangerous_incident':
                return [$at->modify('+'.(int) LegalParams::number('dangerous_incident_notification_hours', 24, $on).' hours')->format(DATE_ATOM), 'dangerous_incident_notification_hours'];
            case 'occupational_disease':
                return [$this->businessDays($at, (int) LegalParams::number('occupational_disease_notification_business_days', 5, $on))->setTime(23, 59)->format(DATE_ATOM),
                    'occupational_disease_notification_business_days'];
            case 'disabling_accident':
            case 'minor_accident':
                // Último día hábil del mes siguiente (regla del parámetro nonfatal_accident_notification_rule).
                $d = $at->modify('first day of next month')->modify('last day of this month');
                while (! $this->isBusiness($d)) {
                    $d = $d->modify('-1 day');
                }

                return [$d->setTime(23, 59)->format(DATE_ATOM), 'nonfatal_accident_notification_rule'];
            default:
                return [null, null];
        }
    }

    private function businessDays(\DateTimeImmutable $from, int $days): \DateTimeImmutable
    {
        $d = $from;
        while ($days > 0) {
            $d = $d->modify('+1 day');
            if ($this->isBusiness($d)) {
                $days--;
            }
        }

        return $d;
    }

    private function isBusiness(\DateTimeImmutable $d): bool
    {
        if ((int) $d->format('N') >= 6) {
            return false;
        }

        return ! DB::selectOne("select 1 as ok from holidays where holiday_on = ? and is_non_working and scope = 'national' and deleted_at is null", [$d->format('Y-m-d')]);
    }
}
