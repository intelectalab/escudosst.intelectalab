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
            'program_activities' => $this->activity($values, $current),
            'training_program_items' => $this->programItem($op, $values, $current, $extra),
            'activity_executions' => $this->execution($op, $values, $current, $extra),
            'trainings' => $this->training($op, $values),
            'training_sessions' => $this->session($op, $values, $current, $extra),
            'position_training_requirements' => $this->matrixVersion($op, $values, $current, $extra),
            'training_attendances' => $this->attendance($values, $current),
            'training_certificates' => $this->certificate($op, $values, $current, $extra),
            'training_evaluations' => $this->evaluation($values, $current),
            default => null,
        };
    }

    /** Módulos origen que registran la ejecución automática de una actividad (RN-PAS-05). */
    public const AUTO_SOURCES = ['INS' => 'inspección finalizada', 'SIM' => 'simulacro evaluado', 'CAP' => 'capacitación realizada',
        'EMO' => 'examen médico ocupacional realizado', 'MAG' => 'medición de agentes aprobada'];

    private function activity(array $values, ?object $current): void
    {
        $this->structure('annual_programs', $this->value($values, $current, 'program_id'), 'actividades');
        // SCR-PAS-02 / RN-PAS-05: la regla automática necesita el módulo origen que la dispara.
        if ($this->value($values, $current, 'auto_execution_rule') === 'on_source_record'
            && ! isset(self::AUTO_SOURCES[strtoupper(trim((string) $this->value($values, $current, 'linked_module')))])) {
            $this->fail('linked_module', 'La ejecución automática necesita el módulo vinculado: '.implode(', ', array_map(fn ($k, $v) => "{$k} ({$v})", array_keys(self::AUTO_SOURCES), self::AUTO_SOURCES)).'.');
        }
        // La área elegida debe ser de la sede de la actividad.
        $site = $this->value($values, $current, 'site_id');
        $area = $this->value($values, $current, 'area_id');
        if ($site && $area && ! DB::selectOne('select 1 as ok from areas where id = ? and site_id = ? and deleted_at is null', [$area, $site])) {
            $this->fail('area_id', 'El área no pertenece a la sede de la actividad.');
        }
    }

    public function after(string $op, array $res, string $id, array $values, ?object $current): void
    {
        if ($res['table'] === 'annual_programs' && ($values['status'] ?? null) === 'approved' && $current?->status !== 'approved') {
            self::supersede($id);
        }
        if ($res['table'] === 'training_programs' && ($values['status'] ?? null) === 'approved' && $current?->status !== 'approved') {
            self::syncCredentialRequirements($id);
        }
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
            // M-PAS RN-PAS-05: la capacitación realizada cuenta para la actividad del PASST con regla automática.
            DocsFieldRules::programExecutionFor('training_sessions', $id, 'CAP');
        }
        if ($res['table'] === 'trainings' && ($values['training_program_item_id'] ?? null)) {
            DB::update("update training_program_items set training_id = ?, status = case when status = 'planned' then 'scheduled' else status end where id = ?",
                [$id, $values['training_program_item_id']]);
            if ($p = DB::selectOne("select p.id from training_program_items i join training_programs p on p.id = i.training_program_id where i.id = ? and p.status in ('approved', 'in_execution')", [$values['training_program_item_id']])) {
                self::syncCredentialRequirements($p->id);
            }
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
                                             and cardinality(target_positions) = 0 and coalesce(target_audience, '') = ''", [$current->id])->n;
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
            // RN-PCA-05: sin comité, basta la firma del supervisor SST en el programa.
            if ($model !== 'committee' && $table === 'training_programs' && ! DB::selectOne(
                "select 1 as ok from signatures s join memberships m on m.user_id = s.signer_user_id and m.company_id = s.company_id and m.status = 'active' and m.deleted_at is null
                   join roles r on r.id = m.role_id where s.subject_type = 'training_programs' and s.subject_id = ? and ".\App\Modules\FIR\SignatureService::valid('s')." and coalesce(r.base_role_code, r.code) = 'sst_supervisor'", [$current->id])) {
                $this->fail('status', 'Sin Comité SST, el programa se aprueba con la firma del Supervisor SST: pídele que lo firme en la pestaña «Firmas».');
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

    /**
     * Tema del programa de capacitación: con el programa aprobado no cambia (RN-PCA-06), salvo reprogramar dentro del año, con
     * motivo, un tema de un mes ya cerrado que no se ejecutó (RN-PCA-08).
     */
    private function programItem(string $op, array &$values, ?object $current, array &$extra): void
    {
        $programId = $this->value($values, $current, 'training_program_id');
        $p = $programId ? DB::selectOne('select status, year from training_programs where id = ?', [$programId]) : null;
        $reschedule = $op === 'update' && $p && in_array($p->status, ['approved', 'in_execution'], true)
            && array_diff(array_keys($values), ['planned_month', 'reschedule_reason']) === [] && array_key_exists('planned_month', $values);
        if (! $reschedule) {
            $this->structure('training_programs', $programId, 'temas');

            return;
        }
        $now = now('America/Lima');
        $closed = $current->planned_month && ($p->year < (int) $now->format('Y') || ($p->year == (int) $now->format('Y') && $current->planned_month < (int) $now->format('n')));
        $held = $current->training_id && DB::selectOne("select 1 as ok from training_sessions where training_id = ? and status = 'held' and deleted_at is null", [$current->training_id]);
        if (! $closed || $held || $current->status === 'done') {
            $this->conflict('Solo se reprograma un tema de un mes ya cerrado que no se ejecutó; el programa aprobado no cambia de otra forma.');
        }
        if (! trim((string) ($values['reschedule_reason'] ?? ''))) {
            $this->fail('reschedule_reason', 'Indica el motivo de la reprogramación.');
        }
        if ((int) $values['planned_month'] < (int) $now->format('n') && $p->year == (int) $now->format('Y')) {
            $this->fail('planned_month', 'Reprograma el tema a este mes o a uno siguiente del mismo año.');
        }
        if ($current->original_month === null) {
            $extra['original_month'] = ['?', [$current->planned_month]];
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
        if ($current && $current->verified_at && $values !== []) {
            $this->conflict('La ejecución ya fue validada: no se modifica. Si hubo un error, archívala y registra otra.');
        }
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
            $values['status'] ??= 'executed';
            $values['planned_month'] ??= (int) date('n', strtotime((string) ($this->value($values, $current, 'executed_on') ?? date('Y-m-d'))));
            // RN-PAS-01: solo cuentan las ejecuciones validadas. Si la registra quien valida (Jefe SST), queda validada; si la registra
            // el responsable, espera la validación del Jefe SST.
            if (in_array('pas.activity_executions.verify', \App\Core\Auth\Permissions::effective(), true)) {
                $extra['verified_by'] = ['?', [(string) auth()->id()]];
                $extra['verified_at'] = ['now()', []];
            }
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

    private function session(string $op, array &$values, ?object $current, array &$extra): void
    {
        if ($current && $current->status === 'cancelled' && $values !== []) {
            $this->conflict('La sesión está anulada.');
        }
        // RN-CAP-17: una sesión fuera del horario de trabajo de la sede (sus turnos activos) queda marcada y se registra con advertencia.
        if (array_intersect(array_keys($values), ['starts_at', 'ends_at', 'site_id']) || $op === 'create') {
            $start = $this->value($values, $current, 'starts_at');
            $end = $this->value($values, $current, 'ends_at') ?: $start;
            $site = $this->value($values, $current, 'site_id');
            $extra['out_of_hours'] = [self::outOfHours($start, $end, $site) ? 'true' : 'false', []];
        }
        if (($values['status'] ?? null) === 'held'
            && ! DB::selectOne('select 1 as ok from training_attendances where session_id = ? and attended and deleted_at is null', [$current?->id])) {
            $this->fail('status', 'Registra al menos una asistencia antes de marcar la sesión como realizada.');
        }
    }


    /** RN-CAP-17: ¿la sesión cae fuera de todos los turnos activos de la sede (o de la empresa)? Sin turnos registrados no se marca. */
    public static function outOfHours(?string $start, ?string $end, ?string $site): bool
    {
        if (! $start) {
            return false;
        }
        $shifts = DB::select("select start_time, end_time from work_shifts where deleted_at is null and coalesce(status, 'active') = 'active'
                                 and start_time is not null and end_time is not null".($site ? ' and (site_id = ? or site_id is null)' : ''), $site ? [$site] : []);
        if (! $shifts) {
            return false;
        }
        $t = DB::selectOne("select ((?::timestamptz) at time zone 'America/Lima')::time as a, ((?::timestamptz) at time zone 'America/Lima')::time as b,
                                   ((?::timestamptz) at time zone 'America/Lima')::date = ((?::timestamptz) at time zone 'America/Lima')::date as same_day", [$start, $end, $start, $end]);
        foreach ($shifts as $sh) {
            $in = fn (string $x) => $sh->start_time <= $sh->end_time ? ($x >= $sh->start_time && $x <= $sh->end_time) : ($x >= $sh->start_time || $x <= $sh->end_time);
            if ($in($t->a) && $in($t->b) && ($t->same_day || $sh->start_time > $sh->end_time)) {
                return false;
            }
        }

        return true;
    }

    /**
     * RN-CAP-06: la matriz por cargo no se pisa. Si cambia lo que exige (tema, curso, nivel, renovación o plazo), la versión anterior
     * se guarda archivada con su fin de vigencia (ayer) y la fila actual rige desde hoy; así el cumplimiento se puede ver a una fecha.
     */
    private function matrixVersion(string $op, array &$values, ?object $current, array &$extra): void
    {
        if ($op !== 'update' || ! $current) {
            return;
        }
        $keys = ['position_id', 'training_id', 'course_id', 'requirement_level', 'renewal_months', 'due_within_days', 'applies_to_contractors', 'is_active'];
        $changed = array_filter($keys, fn ($k) => array_key_exists($k, $values) && (string) $values[$k] !== (string) ($current->{$k} ?? ''));
        $today = DB::selectOne("select (now() at time zone 'America/Lima')::date as d")->d;
        if (! $changed || (string) ($current->effective_from ?? substr((string) $current->created_at, 0, 10)) >= $today) {
            return;
        }
        $cols = array_column(DB::select("select column_name from information_schema.columns where table_schema = 'public' and table_name = 'position_training_requirements'
                                          and column_name not in ('id', 'effective_to', 'deleted_at', 'created_at', 'updated_at')"), 'column_name');
        $list = implode(', ', array_map(fn ($c) => '"'.$c.'"', $cols));
        DB::insert("insert into position_training_requirements ({$list}, effective_to, deleted_at, created_at, updated_at)
                    select {$list}, (?::date - 1), now(), created_at, now() from position_training_requirements where id = ?", [$today, $current->id]);
        $extra['effective_from'] = ['?::date', [$today]];
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

    /**
     * RN-PAS-03: al aprobarse una nueva versión, la anterior se cierra y las ejecuciones de cada actividad pasan a su actividad de la
     * nueva versión (las actividades quitadas conservan sus ejecuciones en la versión anterior).
     */
    public static function supersede(string $programId): void
    {
        $p = DB::selectOne('select previous_program_id from annual_programs where id = ?', [$programId]);
        if (! $p?->previous_program_id) {
            return;
        }
        DB::update('update activity_executions x set activity_id = a.id from program_activities a
                     where a.program_id = ? and a.previous_activity_id = x.activity_id and a.deleted_at is null and x.deleted_at is null', [$programId]);
        DB::update("update annual_programs set status = 'closed', closed_at = now() where id = ? and status <> 'closed'", [$p->previous_program_id]);
        foreach (DB::select('select id from program_activities where program_id in (?, ?) and deleted_at is null', [$programId, $p->previous_program_id]) as $a) {
            (new self)->recalcActivity($a->id);
        }
    }

    /**
     * RN-PCA-07: cada tema obligatorio con puestos objetivo y capacitación pasa a requisito (bloqueante) del fotocheck de los trabajadores de
     * esos puestos, en cada tipo de fotocheck activo para trabajadores. No duplica los que ya existen.
     */
    public static function syncCredentialRequirements(string $programId): int
    {
        $n = 0;
        $types = DB::select("select id from credential_types where deleted_at is null and coalesce(is_active, true) and applies_to in ('workers', 'both')");
        // Un requisito por cada puesto objetivo del tema (M-PCA target_positions).
        $items = DB::select("select i.id, i.company_id, i.topic, p.position_id, i.training_id from training_program_items i cross join lateral unnest(i.target_positions) as p(position_id)
                              where i.training_program_id = ? and i.deleted_at is null and i.status <> 'cancelled' and i.is_mandatory_legal and i.training_id is not null", [$programId]);
        foreach ($types as $t) {
            foreach ($items as $i) {
                $n += DB::affectingStatement(
                    "insert into credential_requirements (company_id, credential_type_id, requirement_type, training_id, position_id, description, is_blocking, training_program_item_id, created_by, updated_by)
                     select ?, ?, 'training', ?, ?, ?, true, ?, ?, ? where not exists (select 1 from credential_requirements where credential_type_id = ? and training_id = ? and position_id = ? and deleted_at is null)",
                    [$i->company_id, $t->id, $i->training_id, $i->position_id, 'Tema obligatorio del programa de capacitación: '.$i->topic, $i->id, auth()->id(), auth()->id(), $t->id, $i->training_id, $i->position_id]);
            }
        }

        return $n;
    }

    public function recalcActivity(string $activityId): void
    {
        DB::update(
            "update program_activities a set executed_quantity = x.q,
                    progress_pct = least(100, round(x.q * 100.0 / greatest(coalesce(a.goal_quantity, 1), 1), 2)),
                    status = case when a.status = 'cancelled' then a.status when x.q >= greatest(coalesce(a.goal_quantity, 1), 1) then 'done'
                                  when x.q > 0 then 'in_progress' else a.status end
               from (select coalesce(sum(coalesce(quantity, 1) * case when status = 'partial' then ?::numeric else 1 end), 0) as q
                       from activity_executions where activity_id = ? and deleted_at is null and verified_at is not null) x
              where a.id = ?",
            [(float) LegalParams::number('partial_execution_weight', 0.5), $activityId, $activityId]
        );
        DB::update(
            "update annual_programs p set progress_pct = coalesce((select round(avg(progress_pct), 2) from program_activities
                    where program_id = p.id and deleted_at is null and status <> 'cancelled'), 0)
              where p.id = (select program_id from program_activities where id = ?)",
            [$activityId]
        );
    }
}
