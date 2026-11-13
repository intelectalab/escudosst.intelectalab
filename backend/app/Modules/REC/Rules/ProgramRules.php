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
            'training_evaluations' => $this->evaluation($values, $current),
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
        // RN-PAS-06: cada actividad del PASST está ligada a un objetivo SST.
        if (in_array($to, ['committee_review', 'approved'], true) && $table === 'annual_programs') {
            $loose = (int) DB::selectOne("select count(*) as n from program_activities where program_id = ? and deleted_at is null and status <> 'cancelled' and objective_id is null", [$current->id])->n;
            if ($loose > 0) {
                $this->fail('status', "Hay {$loose} actividad(es) sin objetivo SST: vincula cada actividad a su objetivo.");
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
        // RN-PAS-04: toda ejecución tiene evidencia: un archivo o el registro del sistema que la respalda.
        if (! $this->value($values, $current, 'evidence_file_id') && ! $this->value($values, $current, 'source_id')) {
            $this->fail('evidence_file_id', 'Adjunta la evidencia de lo ejecutado (acta, fotos, lista) o vincula el registro de origen.');
        }
        // RN-PAS-08: registra la ejecución el responsable de la actividad o el Jefe SST.
        $owner = DB::selectOne('select responsible_user_id from program_activities where id = ?', [$activity])?->responsible_user_id;
        if ($op === 'create' && $owner && (string) $owner !== (string) auth()->id() && ! in_array(app(\App\Core\Tenancy\CompanyContext::class)->roleCode, ['sst_manager', 'account_admin'], true)) {
            $this->fail('activity_id', 'La ejecución la registra el responsable de la actividad o el Jefe SST.');
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
        // RN-CAP-16: la misma persona no puede estar en dos sesiones que se cruzan en horario.
        $who = $this->value($values, $current, 'worker_id') ? 'worker_id' : 'contractor_worker_id';
        $clash = DB::selectOne(
            "select t.title, o.starts_at from training_attendances a join training_sessions o on o.id = a.session_id and o.status <> 'cancelled' and o.deleted_at is null
               join trainings t on t.id = o.training_id
               join training_sessions s on s.id = ? and s.id <> o.id
              where a.{$who} = ? and a.deleted_at is null and a.id <> ? and o.starts_at < s.ends_at and s.starts_at < o.ends_at limit 1",
            [$this->value($values, $current, 'session_id'), $this->value($values, $current, $who), $current?->id ?? '00000000-0000-0000-0000-000000000000']
        );
        if ($clash) {
            $this->fail($who, 'Esta persona ya está en otra sesión a la misma hora («'.$clash->title.'», '.date('d/m/Y H:i', strtotime($clash->starts_at)).').');
        }
    }

    /** RN-CAP-02: la evaluación final tiene un número máximo de intentos (parámetro training_max_attempts). */
    private function evaluation(array &$values, ?object $current): void
    {
        if ($current !== null || ($values['evaluation_type'] ?? 'post') !== 'post') {
            return;
        }
        $who = ! empty($values['worker_id']) ? 'worker_id' : 'contractor_worker_id';
        $max = (int) LegalParams::number('training_max_attempts', 3);
        $used = (int) DB::selectOne("select count(*) as n from training_evaluations where training_id = ? and {$who} = ? and evaluation_type = 'post' and deleted_at is null",
            [$values['training_id'] ?? null, $values[$who] ?? null])->n;
        if ($max > 0 && $used >= $max) {
            $this->conflict("Ya usó los {$max} intentos de la evaluación final de esta capacitación.");
        }
        if (DB::selectOne("select 1 as ok from training_evaluations where training_id = ? and {$who} = ? and evaluation_type = 'post' and passed and deleted_at is null",
            [$values['training_id'] ?? null, $values[$who] ?? null])) {
            $this->conflict('Ya aprobó la evaluación final de esta capacitación.');
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
        if (($values['status'] ?? null) === 'validated' && $current?->status === 'pending_validation') {
            if (! in_array('cap.training_certificates.approve', \App\Core\Auth\Permissions::effective(), true)) {
                $this->fail('status', 'Solo el Jefe SST valida certificados.');
            }
            $extra['validated_by'] = ['?', [(string) auth()->id()]];
            $extra['validated_at'] = ['now()', []];
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
        // RN-CAP-11: el texto de un certificado validado no se edita; por un error de datos se reemite (uno nuevo que lo reemplaza).
        if ($current && $current->status === 'validated') {
            foreach (['holder_name', 'holder_document_masked', 'course_title', 'issued_on', 'expires_on', 'hours', 'score', 'score_label', 'worker_id', 'training_id'] as $f) {
                if (array_key_exists($f, $values) && (string) $values[$f] !== (string) $current->{$f}) {
                    $this->fail($f, 'El certificado ya está validado: no se corrige su texto. Usa «Reemitir» para emitir uno nuevo que lo reemplace.');
                }
            }
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
