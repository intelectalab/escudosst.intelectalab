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
        'under_investigation' => ['closed'],
        'closed' => [],
        'discarded' => [],
    ];

    public function prepare(string $op, array $res, array &$values, ?object $current, array &$extra): void
    {
        match ($res['table']) {
            'sst_events' => $this->event($op, $values, $current, $extra),
            'event_notifications' => $this->notification($values, $current, $extra),
            'investigations' => $this->investigation($op, $values, $current, $extra),
            default => null,
        };
    }

    public function after(string $op, array $res, string $id, array $values, ?object $current): void
    {
        if ($res['table'] === 'sst_events' && ($op === 'create' || array_key_exists('event_type', $values) || array_key_exists('occurred_at', $values))) {
            $this->obligations($id);
        }
        if ($res['table'] === 'event_people') {
            $event = DB::selectOne('select event_id from event_people where id = ?', [$id])->event_id;
            DB::update("update sst_events set lost_days = (select coalesce(sum(lost_days), 0) from event_people where event_id = ? and deleted_at is null),
                        injured_count = (select count(*) from event_people where event_id = ? and deleted_at is null and person_role in ('injured','affected','ill'))
                        where id = ?", [$event, $event, $event]);
        }
        if ($res['table'] === 'event_notifications' && in_array($values['status'] ?? null, ['sent', 'acknowledged'], true)) {
            $n = DB::selectOne('select event_id from event_notifications where id = ?', [$id]);
            DB::update("update sst_events set status = 'notified' where id = ? and status in ('reported','registered')
                         and not exists (select 1 from event_notifications where event_id = ? and status = 'pending' and deleted_at is null)", [$n->event_id, $n->event_id]);
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
                if (DB::selectOne("select 1 as ok from event_notifications where event_id = ? and status = 'pending' and deleted_at is null", [$current->id])) {
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
        $to = $values['status'] ?? null;
        if (in_array($to, ['sent', 'acknowledged'], true)) {
            $at = $this->value($values, $current, 'notified_at') ?? now()->toIso8601String();
            $values['notified_at'] = $at;
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
            if (in_array($to, ['approved', 'actions_in_progress', 'closed'], true)
                && ! DB::selectOne('select 1 as ok from investigation_causes where investigation_id = ? and deleted_at is null', [$current->id])) {
                $this->fail('status', 'Registra las causas (inmediatas y básicas) antes de aprobar la investigación.');
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
        switch ($type) {
            case 'fatal_accident':
                return [$at->modify('+'.(int) LegalParams::number('fatal_accident_notification_hours', 24).' hours')->format(DATE_ATOM), 'fatal_accident_notification_hours'];
            case 'dangerous_incident':
                return [$at->modify('+'.(int) LegalParams::number('dangerous_incident_notification_hours', 24).' hours')->format(DATE_ATOM), 'dangerous_incident_notification_hours'];
            case 'occupational_disease':
                return [$this->businessDays($at, (int) LegalParams::number('occupational_disease_notification_business_days', 5))->setTime(23, 59)->format(DATE_ATOM),
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
