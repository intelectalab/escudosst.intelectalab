<?php

namespace App\Modules\REC\Rules;

use App\Core\Support\LegalParams;
use Illuminate\Support\Facades\DB;

/**
 * PAS · Programa anual SST, PCA · Programa de capacitación y CAP · Capacitaciones (docs/modulos/M-PAS, M-PCA, M-CAP §8).
 *
 * Programas (annual_programs, training_programs): borrador → revisión del comité → aprobado → en ejecución → cerrado.
 * - RN-PAS-03 / RN-PCA-06: aprobado, su estructura no cambia (no se agregan ni quitan actividades o temas).
 * - RN-PAS-07 / RN-PCA-05: la aprobación exige la sesión del comité si la empresa tiene comité.
 * - RN-PCA-01: al menos min_trainings_per_year temas para enviarlo a aprobación.
 * Ejecución:
 * - RN-PAS-01: el avance de cada actividad = cantidad ejecutada / meta; el del programa, el promedio.
 * - RN-PAS-04: cada ejecución con descripción de la evidencia o un registro vinculado.
 * - RN-PCA-04: un tema queda ejecutado cuando su capacitación tiene una sesión realizada.
 * - RN-CAP-19: una sesión cerrada vuelve inmutables sus asistencias.
 */
class ProgramRules extends RecordRules
{
    private const FLOW = [
        'draft' => ['committee_review', 'approved'],
        'committee_review' => ['approved', 'draft'],
        'approved' => ['in_execution', 'closed'],
        'in_execution' => ['closed'],
        'closed' => [],
    ];

    private const LABEL = ['draft' => 'Borrador', 'committee_review' => 'Revisión del comité', 'approved' => 'Aprobado', 'in_execution' => 'En ejecución', 'closed' => 'Cerrado'];

    public function prepare(string $op, array $res, array &$values, ?object $current, array &$extra): void
    {
        match ($res['table']) {
            'annual_programs', 'training_programs' => $this->program($res['table'], $op, $values, $current, $extra),
            'program_activities' => $this->structure('annual_programs', $this->value($values, $current, 'program_id'), 'actividades'),
            'training_program_items' => $this->structure('training_programs', $this->value($values, $current, 'training_program_id'), 'temas'),
            'activity_executions' => $this->execution($op, $values, $current, $extra),
            'trainings' => $this->training($op, $values),
            'training_sessions' => $this->session($op, $values, $current),
            'training_attendances' => $this->attendance($values, $current),
            'training_certificates' => $this->certificate($op, $values, $current, $extra),
            default => null,
        };
    }

    public function after(string $op, array $res, string $id, array $values, ?object $current): void
    {
        if ($res['table'] === 'activity_executions') {
            $this->recalcActivity(DB::selectOne('select activity_id from activity_executions where id = ?', [$id])->activity_id);
        }
        if ($res['table'] === 'program_activities' && ($op === 'create' || array_key_exists('goal_quantity', $values))) {
            $this->recalcActivity($id);
        }
        if ($res['table'] === 'training_sessions' && ($values['status'] ?? null) === 'held') {
            $s = DB::selectOne('select training_id from training_sessions where id = ?', [$id]);
            DB::update("update trainings set status = 'completed' where id = ? and status in ('draft','scheduled','in_progress')", [$s->training_id]);
            // RN-PCA-04: el tema del programa queda ejecutado.
            DB::update("update training_program_items set status = 'done' where training_id = ? and status <> 'cancelled'", [$s->training_id]);
            DB::update("update training_program_items i set status = 'done' from trainings t
                         where t.id = ? and t.training_program_item_id = i.id and i.status <> 'cancelled'", [$s->training_id]);
        }
        if ($res['table'] === 'trainings' && ($values['training_program_item_id'] ?? null)) {
            DB::update("update training_program_items set training_id = ?, status = case when status = 'planned' then 'scheduled' else status end where id = ?",
                [$id, $values['training_program_item_id']]);
        }
    }

    public function prepareDelete(array $res, object $current): void
    {
        if ($res['table'] === 'program_activities') {
            $this->structure('annual_programs', $current->program_id, 'actividades');
        }
        if ($res['table'] === 'training_program_items') {
            $this->structure('training_programs', $current->training_program_id, 'temas');
        }
        if ($res['table'] === 'training_attendances') {
            $this->sessionOpen($current->session_id);
        }
        if (in_array($res['table'], ['annual_programs', 'training_programs'], true) && $current->status !== 'draft') {
            $this->conflict('Solo se archiva un programa en borrador.');
        }
    }

    private function program(string $table, string $op, array &$values, ?object $current, array &$extra): void
    {
        if ($op === 'create') {
            $values['status'] = 'draft';

            return;
        }
        $to = $values['status'] ?? null;
        if ($to === null || $to === $current->status) {
            if ($current->status === 'closed' && $values !== []) {
                $this->conflict('El programa está cerrado: no se modifica.');
            }

            return;
        }
        if (! in_array($to, self::FLOW[$current->status] ?? [], true)) {
            $this->fail('status', 'No se puede pasar de «'.self::LABEL[$current->status].'» a «'.(self::LABEL[$to] ?? $to).'».');
        }
        $items = $table === 'annual_programs'
            ? (int) DB::selectOne("select count(*) as n from program_activities where program_id = ? and deleted_at is null and status <> 'cancelled'", [$current->id])->n
            : (int) DB::selectOne("select count(*) as n from training_program_items where training_program_id = ? and deleted_at is null and status <> 'cancelled'", [$current->id])->n;
        if (in_array($to, ['committee_review', 'approved'], true)) {
            if ($items === 0) {
                $this->fail('status', 'El programa no tiene '.($table === 'annual_programs' ? 'actividades' : 'temas').'.');
            }
            if ($table === 'training_programs') {
                $min = (int) LegalParams::number('min_trainings_per_year', 4);
                if ($items < $min) {
                    $this->fail('status', "El programa debe tener al menos {$min} capacitaciones al año (Ley 29783, art. 35). Tiene {$items}.");
                }
                $noTarget = DB::selectOne("select count(*) as n from training_program_items where training_program_id = ? and deleted_at is null and is_mandatory_legal
                                             and position_id is null and coalesce(target_audience, '') = ''", [$current->id])->n;
                if ($noTarget > 0) {
                    $this->fail('status', "Hay {$noTarget} tema(s) obligatorio(s) sin puesto ni público objetivo.");
                }
            }
        }
        if ($to === 'approved') {
            $model = DB::selectOne('select sst_model from companies where id = ?', [$current->company_id])?->sst_model;
            if ($model === 'committee' && ! $this->value($values, $current, 'committee_meeting_id')) {
                $this->fail('committee_meeting_id', 'La empresa tiene Comité SST: indica la sesión en que se aprobó el programa.');
            }
            $extra['approved_at'] = ['now()', []];
            if ($table === 'annual_programs') {
                $extra['approved_by'] = ['?', [(string) auth()->id()]];
            }
        }
        if ($to === 'closed' && $table === 'annual_programs') {
            $extra['closed_at'] = ['now()', []];
        }
    }

    /** RN-PAS-03 / RN-PCA-06: con el programa aprobado no se agregan ni quitan actividades o temas. */
    private function structure(string $table, ?string $programId, string $what): void
    {
        $st = $programId ? DB::selectOne("select status from \"{$table}\" where id = ?", [$programId])?->status : null;
        if ($st !== null && $st !== 'draft' && $st !== 'committee_review') {
            $this->conflict("El programa ya está aprobado: sus {$what} no cambian. Si hace falta, se aprueba una nueva versión.");
        }
    }

    private function execution(string $op, array &$values, ?object $current, array &$extra): void
    {
        $activity = $this->value($values, $current, 'activity_id');
        $st = DB::selectOne('select p.status from program_activities a join annual_programs p on p.id = a.program_id where a.id = ?', [$activity])?->status;
        if (! in_array($st, ['approved', 'in_execution'], true)) {
            $this->conflict('Las ejecuciones se registran cuando el programa está aprobado.');
        }
        if (! $this->value($values, $current, 'description') && ! $this->value($values, $current, 'source_id')) {
            $this->fail('description', 'Describe la evidencia de lo ejecutado (o vincula el registro de origen).');
        }
        if (($q = $this->value($values, $current, 'quantity')) !== null && (float) $q <= 0) {
            $this->fail('quantity', 'La cantidad ejecutada debe ser mayor que cero.');
        }
        if ($op === 'create') {
            $extra['recorded_by'] = ['?', [(string) auth()->id()]];
            if ($st === 'approved') {
                DB::update("update annual_programs set status = 'in_execution' from program_activities a where a.id = ? and annual_programs.id = a.program_id", [$activity]);
            }
        }
    }

    private function training(string $op, array &$values): void
    {
        if ($op === 'create' && ! isset($values['code'])) {
            $n = (int) DB::selectOne('select count(*) + 1 as n from trainings where extract(year from created_at) = extract(year from now())')->n;
            do {
                $values['code'] = sprintf('CAP-%s-%04d', date('Y'), $n++);
            } while (DB::selectOne('select 1 as ok from trainings where code = ? and deleted_at is null', [$values['code']]));
        }
    }

    private function session(string $op, array &$values, ?object $current): void
    {
        if ($current && $current->status === 'cancelled' && $values !== []) {
            $this->conflict('La sesión está anulada.');
        }
        if (($values['status'] ?? null) === 'held'
            && ! DB::selectOne('select 1 as ok from training_attendances where session_id = ? and attended and deleted_at is null', [$current?->id])) {
            $this->fail('status', 'Registra al menos una asistencia antes de marcar la sesión como realizada.');
        }
    }

    private function attendance(array &$values, ?object $current): void
    {
        $this->sessionOpen($this->value($values, $current, 'session_id'));
        if (! $this->value($values, $current, 'worker_id') && ! $this->value($values, $current, 'contractor_worker_id')) {
            $this->fail('worker_id', 'Elige al trabajador asistente.');
        }
        if ($current === null && ! array_key_exists('attended', $values)) {
            $values['attended'] = true;
        }
    }

    private function sessionOpen(?string $session): void
    {
        $st = $session ? DB::selectOne('select status from training_sessions where id = ?', [$session])?->status : null;
        if ($st === 'cancelled') {
            $this->conflict('La sesión está anulada: no admite asistencias.');
        }
        if ($st === 'held') {
            $this->conflict('La sesión ya se marcó como realizada: su lista de asistencia quedó cerrada (RN-CAP-19).');
        }
    }

    private function certificate(string $op, array &$values, ?object $current, array &$extra): void
    {
        if ($op === 'create') {
            $this->conflict('Los certificados se emiten desde la capacitación con «Emitir certificados».');
        }
        if (($values['status'] ?? null) === 'voided') {
            if (! $this->value($values, $current, 'void_reason')) {
                $this->fail('void_reason', 'Para anular el certificado indica el motivo.');
            }
            $extra['voided_by'] = ['?', [(string) auth()->id()]];
            $extra['voided_at'] = ['now()', []];
        }
        if ($current && $current->status === 'voided' && $values !== []) {
            $this->conflict('El certificado está anulado: no se modifica.');
        }
    }

    private function recalcActivity(string $activityId): void
    {
        DB::update(
            "update program_activities a set executed_quantity = x.q,
                    progress_pct = least(100, round(x.q * 100.0 / greatest(coalesce(a.goal_quantity, 1), 1), 2)),
                    status = case when a.status = 'cancelled' then a.status when x.q >= greatest(coalesce(a.goal_quantity, 1), 1) then 'done'
                                  when x.q > 0 then 'in_progress' else a.status end
               from (select coalesce(sum(quantity), 0) as q from activity_executions where activity_id = ? and deleted_at is null) x
              where a.id = ?",
            [$activityId, $activityId]
        );
        DB::update(
            "update annual_programs p set progress_pct = coalesce((select round(avg(progress_pct), 2) from program_activities
                    where program_id = p.id and deleted_at is null and status <> 'cancelled'), 0)
              where p.id = (select program_id from program_activities where id = ?)",
            [$activityId]
        );
    }
}
