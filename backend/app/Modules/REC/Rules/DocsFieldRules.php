<?php

namespace App\Modules\REC\Rules;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Documentos con versiones (POL política, RIS RISST, PET procedimientos, DOC documentos) y registros de campo
 * (ACT reportes, CH5 charlas, ATS, SIM simulacros, EQE equipos de emergencia, POL objetivos).
 *
 * Versiones: borrador → en revisión → aprobada → vigente (la anterior pasa a obsoleta); número correlativo;
 * vigente u obsoleta no se edita; una sola vigente por documento.
 * Integración con el PASST (RN-PAS-05): un simulacro evaluado o una inspección finalizada vinculados a una
 * actividad del programa registran su ejecución automáticamente.
 */
class DocsFieldRules extends RecordRules
{
    /** tabla de versiones => [columna del documento padre (null = una sola serie por empresa), tabla padre] */
    private const VERSIONED = [
        'sst_policies' => [null, null],
        'risst_versions' => [null, null],
        'procedure_versions' => ['procedure_id', 'procedures'],
        'document_versions' => ['document_id', 'documents'],
        'risk_maps' => ['site_id', null],
    ];

    private const VFLOW = ['draft' => ['in_review', 'approved'], 'in_review' => ['approved', 'draft'], 'approved' => ['current', 'draft'], 'current' => ['obsolete'], 'obsolete' => []];

    public function prepare(string $op, array $res, array &$values, ?object $current, array &$extra): void
    {
        $t = $res['table'];
        if ($t === 'report_points') {
            if ($op === 'create') {
                // Hash provisional (la columna es obligatoria); el definitivo depende del id y se pone después de crear.
                $extra['token_hash'] = ["decode(?, 'hex')", [hash('sha256', Str::random(40))]];
                $values['is_active'] ??= true;
                $values['allow_anonymous'] ??= true;
            }

            return;
        }
        if (isset(self::VERSIONED[$t])) {
            $this->version($t, $op, $values, $current, $extra);

            return;
        }
        match ($t) {
            'substandard_reports' => $this->report($op, $values, $current, $extra),
            'safety_talks' => $this->talk($values, $current),
            'ats_forms' => $this->ats($op, $values, $current, $extra),
            'drills' => $this->drill($op, $values, $current),
            'objective_targets' => $this->target($values, $current, $extra),
            'risst_deliveries' => $this->delivery($values, $current),
            'document_acknowledgements' => $this->ack($values),
            'risk_map_markers' => $this->marker($values, $current),
            default => null,
        };
    }

    public function prepareDelete(array $res, object $current): void
    {
        if ($res['table'] === 'risk_map_markers') {
            $this->marker([], $current);
        }
    }

    /** Pictogramas del mapa: solo se mueven o cambian mientras el mapa está en borrador o revisión (lo aprobado no cambia). */
    private function marker(array $values, ?object $current): void
    {
        $mapId = $this->value($values, $current, 'risk_map_id');
        $map = $mapId ? DB::selectOne('select status from risk_maps where id = ? and deleted_at is null', [$mapId]) : null;
        if (! $map) {
            $this->fail('risk_map_id', 'El mapa de riesgos no existe.');
        }
        if (! in_array($map->status, ['draft', 'in_review'], true)) {
            $this->conflict('El mapa ya está aprobado o vigente: para cambiar pictogramas crea una nueva versión del mapa.');
        }
    }

    public function after(string $op, array $res, string $id, array $values, ?object $current): void
    {
        $t = $res['table'];
        if ($t === 'report_points' && $op === 'create') {
            $token = \App\Modules\ACT\ReportPointController::token(app(\App\Core\Tenancy\CompanyContext::class)->companyId, $id);
            DB::update("update report_points set token_hash = decode(?, 'hex'), token_last4 = ? where id = ?", [hash('sha256', $token), substr($token, -4), $id]);

            return;
        }
        if (isset(self::VERSIONED[$t]) && ($values['status'] ?? null) === 'current') {
            [$col, $parent] = self::VERSIONED[$t];
            $row = DB::selectOne("select * from \"{$t}\" where id = ?", [$id]);
            if ($parent) {
                DB::update("update \"{$parent}\" set current_version_id = ? where id = ?", [$id, $row->{$col}]);
            }
        }
        if ($t === 'equipment_checks') {
            $c = DB::selectOne('select * from equipment_checks where id = ?', [$id]);
            DB::update(
                "update emergency_equipment set last_maintenance_on = greatest(coalesce(last_maintenance_on, ?::date), ?::date),
                        next_maintenance_on = coalesce(?::date, next_maintenance_on),
                        status = case when ? = 'failed' then 'out_of_service' when ? = 'observed' then 'needs_maintenance' when ? = 'ok' and status <> 'retired' then 'operational' else status end
                  where id = ?",
                [substr($c->checked_at, 0, 10), substr($c->checked_at, 0, 10), $c->next_due_on, $c->result, $c->result, $c->result, $c->equipment_id]
            );
        }
        if ($t === 'drills' && ($values['status'] ?? null) === 'evaluated') {
            $this->programExecution('drills', $id, 'SIM');
        }
    }

    /** RN-PAS-05: el registro vinculado a una actividad del PASST cuenta como su ejecución (una sola vez). */
    public static function programExecutionFor(string $table, string $id, string $module): void
    {
        $r = DB::selectOne("select program_activity_id, title from \"{$table}\" where id = ?", [$id]);
        if (! $r?->program_activity_id || DB::selectOne('select 1 as ok from activity_executions where source_id = ? and deleted_at is null', [$id])) {
            return;
        }
        $st = DB::selectOne('select p.status from program_activities a join annual_programs p on p.id = a.program_id where a.id = ?', [$r->program_activity_id])?->status;
        if (! in_array($st, ['approved', 'in_execution'], true)) {
            return;
        }
        $company = DB::selectOne("select company_id from \"{$table}\" where id = ?", [$id])->company_id;
        DB::insert(
            "insert into activity_executions (company_id, activity_id, executed_on, quantity, description, source_module, source_id, recorded_by, created_by, updated_by)
             values (?, ?, (now() at time zone 'America/Lima')::date, 1, ?, ?, ?, ?, ?, ?)",
            [$company, $r->program_activity_id, mb_substr("Registrado desde {$module}: {$r->title}", 0, 500), $module, $id, (string) auth()->id(), (string) auth()->id(), (string) auth()->id()]
        );
        (new ProgramRules)->after('create', ['table' => 'activity_executions'], DB::selectOne('select id from activity_executions where source_id = ?', [$id])->id, [], null);
    }

    private function programExecution(string $table, string $id, string $module): void
    {
        self::programExecutionFor($table, $id, $module);
    }

    private function version(string $t, string $op, array &$values, ?object $current, array &$extra): void
    {
        [$col] = self::VERSIONED[$t];
        if ($op === 'create') {
            $parent = $col ? ($values[$col] ?? null) : null;
            $values['version_number'] ??= (int) DB::selectOne("select coalesce(max(version_number), 0) + 1 as n from \"{$t}\" where deleted_at is null".($col ? " and {$col} = ?" : ''),
                $col ? [$parent] : [])->n;
            $values['status'] = 'draft';
            if (in_array($t, ['sst_policies', 'risst_versions', 'procedure_versions'], true)) {
                $extra['prepared_by'] = ['?', [(string) auth()->id()]];
            }
            if ($t === 'document_versions') {
                $extra['submitted_by'] = ['?', [(string) auth()->id()]];   // autor desde el inicio: no se aprueba a sí mismo
            }

            return;
        }
        if (in_array($current->status, ['current', 'obsolete'], true) && array_diff(array_keys($values), ['status']) !== []) {
            $this->conflict('La versión ya está vigente u obsoleta: su contenido no cambia. Crea una nueva versión.');
        }
        $to = $values['status'] ?? null;
        if (! $to || $to === $current->status) {
            return;
        }
        if (! in_array($to, self::VFLOW[$current->status] ?? [], true)) {
            $this->fail('status', 'Ese cambio de estado no está permitido.');
        }
        if (in_array($to, ['in_review', 'approved', 'current'], true) && $t !== 'risk_maps'
            && trim(strip_tags((string) $this->value($values, $current, 'content_html'))) === '' && ! $this->value($values, $current, 'file_id')) {
            $this->fail('status', 'La versión está vacía: escribe su contenido o adjunta el documento antes de enviarla o aprobarla.');
        }
        if ($to === 'approved') {
            $author = $current->prepared_by ?? $current->submitted_by ?? null;
            if ($author && $author === (string) auth()->id()) {
                $perm = strtolower((string) (\App\Modules\REC\Registry::data()['resources'][$t]['module'] ?? 'doc')).".{$t}.update";
                $this->fail('status', 'Quien elaboró la versión no puede aprobarla: la aprueba otra persona (gerencia, comité o supervisor).'.\App\Core\Support\Approvers::hint($perm));
            }
            if ($t === 'risst_versions') {
                $model = DB::selectOne('select sst_model from companies where id = ?', [$current->company_id])?->sst_model;
                if ($model === 'committee' && ! $this->value($values, $current, 'committee_meeting_id')) {
                    $this->fail('committee_meeting_id', 'El RISST lo aprueba el Comité SST: indica la sesión de aprobación.');
                }
            }
            $extra['approved_by'] = ['?', [(string) auth()->id()]];
            $extra['approved_at'] = ['now()', []];
        }
        if ($to === 'current') {
            // Una sola vigente: la anterior pasa a obsoleta antes de publicar esta.
            DB::update("update \"{$t}\" set status = 'obsolete'".($t === 'risk_maps' ? '' : ', obsoleted_at = now()')." where id <> ? and status = 'current'".($col ? " and {$col} = ?" : ''),
                $col ? [$current->id, $current->{$col}] : [$current->id]);
            $values['effective_on'] = $this->value($values, $current, 'effective_on') ?? date('Y-m-d');
            if ($t === 'sst_policies') {
                $extra['published_at'] = ['now()', []];
            }
        }
        if ($to === 'in_review' && $t === 'document_versions') {
            $extra['submitted_by'] = ['?', [(string) auth()->id()]];
            $extra['submitted_at'] = ['now()', []];
        }
    }

    private function report(string $op, array &$values, ?object $current, array &$extra): void
    {
        if ($op === 'create') {
            if (! isset($values['code'])) {
                $n = (int) DB::selectOne('select count(*) + 1 as n from substandard_reports where extract(year from created_at) = extract(year from now())')->n;
                do {
                    $values['code'] = sprintf('REP-%s-%04d', date('Y'), $n++);
                } while (DB::selectOne('select 1 as ok from substandard_reports where code = ? and deleted_at is null', [$values['code']]));
            }
            $values['reported_at'] ??= now()->toIso8601String();
            $values['origin'] ??= 'app';
            $values['status'] = 'received';
            if (empty($values['is_anonymous'])) {
                $extra['reporter_user_id'] = ['?', [(string) auth()->id()]];
            }

            return;
        }
        $to = $values['status'] ?? null;
        $flow = ['received' => ['triaged', 'rejected'], 'triaged' => ['action_assigned', 'closed', 'rejected'], 'action_assigned' => ['closed'], 'rejected' => [], 'closed' => []];
        if ($to && $to !== $current->status && ! in_array($to, $flow[$current->status] ?? [], true)) {
            $this->fail('status', $current->status === 'received' ? 'Primero revisa el reporte (nivel de riesgo) o descártalo con su motivo.' : 'Ese cambio de estado no está permitido.');
        }
        if ($to === 'triaged') {
            if (! $this->value($values, $current, 'risk_level')) {
                $this->fail('risk_level', 'Para revisar el reporte indica su nivel de riesgo.');
            }
            $extra['triaged_by'] = ['?', [(string) auth()->id()]];
            $extra['triaged_at'] = ['now()', []];
        }
        if ($to === 'rejected' && ! $this->value($values, $current, 'rejection_reason')) {
            $this->fail('rejection_reason', 'Para descartar el reporte indica el motivo.');
        }
        if ($to === 'closed') {
            $extra['closed_at'] = ['now()', []];
        }
    }

    private function talk(array &$values, ?object $current): void
    {
        if ($current === null) {
            if (($values['status'] ?? 'scheduled') === 'held') {
                $values['status'] = 'scheduled';   // se marca «realizada» después de registrar la asistencia
            }
            $values['status'] ??= 'scheduled';
        }
        if (($values['status'] ?? null) === 'held' && $current
            && ! DB::selectOne('select 1 as ok from talk_attendances where safety_talk_id = ? and deleted_at is null', [$current->id])) {
            $this->fail('status', 'Registra la asistencia de la charla antes de marcarla como realizada.');
        }
    }

    private function ats(string $op, array &$values, ?object $current, array &$extra): void
    {
        if ($op === 'create' && ! isset($values['code'])) {
            $n = (int) DB::selectOne('select count(*) + 1 as n from ats_forms where extract(year from created_at) = extract(year from now())')->n;
            do {
                $values['code'] = sprintf('ATS-%s-%04d', date('Y'), $n++);
            } while (DB::selectOne('select 1 as ok from ats_forms where code = ? and deleted_at is null', [$values['code']]));
        }
        $to = $values['status'] ?? null;
        $flow = ['draft' => ['signed', 'cancelled'], 'signed' => ['in_execution', 'draft', 'cancelled'], 'in_execution' => ['closed'], 'closed' => [], 'cancelled' => []];
        if ($current && $to && $to !== $current->status && ! in_array($to, $flow[$current->status] ?? [], true)) {
            $this->fail('status', $current->status === 'draft' ? 'Primero firma el ATS con todos los participantes.' : 'Ese cambio de estado no está permitido.');
        }
        if ($to === 'signed' && $current) {
            $unsigned = (int) DB::selectOne('select count(*) as n from ats_participants where ats_form_id = ? and deleted_at is null and signed_at is null', [$current->id])->n;
            if ($unsigned > 0) {
                $this->fail('status', "Faltan firmar {$unsigned} participante(s): cada trabajador firma que conoce los peligros y controles de la tarea.");
            }
        }
        if (in_array($to, ['signed', 'in_execution'], true) && $current) {
            if (! DB::selectOne('select 1 as ok from ats_steps where ats_form_id = ? and deleted_at is null', [$current->id])) {
                $this->fail('status', 'Registra los pasos de la tarea con sus peligros y controles antes de firmar el ATS.');
            }
            if (! DB::selectOne('select 1 as ok from ats_participants where ats_form_id = ? and deleted_at is null', [$current->id])) {
                $this->fail('status', 'Registra a los trabajadores que participan antes de firmar el ATS.');
            }
        }
        if ($to === 'in_execution') {
            $values['started_at'] = $this->value($values, $current, 'started_at') ?? now()->toIso8601String();
        }
        if ($to === 'closed') {
            $values['closed_at'] = now()->toIso8601String();
        }
        if ($current && in_array($current->status, ['closed', 'cancelled'], true) && $values !== []) {
            $this->conflict('El ATS está cerrado: no se modifica.');
        }
    }

    private function drill(string $op, array &$values, ?object $current): void
    {
        if ($op === 'create' && ! isset($values['code'])) {
            $n = (int) DB::selectOne('select count(*) + 1 as n from drills where extract(year from created_at) = extract(year from now())')->n;
            do {
                $values['code'] = sprintf('SIM-%s-%04d', date('Y'), $n++);
            } while (DB::selectOne('select 1 as ok from drills where code = ? and deleted_at is null', [$values['code']]));
        }
        $to = $values['status'] ?? null;
        if ($to === 'executed') {
            $values['executed_at'] = $this->value($values, $current, 'executed_at') ?? now()->toIso8601String();
        }
        if ($to === 'evaluated') {
            if (! $this->value($values, $current, 'executed_at')) {
                $this->fail('executed_at', 'Registra cuándo se ejecutó el simulacro.');
            }
            if (! $this->value($values, $current, 'result')) {
                $this->fail('result', 'Para evaluar el simulacro indica el resultado.');
            }
        }
    }

    private function target(array &$values, ?object $current, array &$extra): void
    {
        $target = $this->value($values, $current, 'target_value');
        $actual = $this->value($values, $current, 'actual_value');
        if ($actual !== null && $target !== null && (float) $target != 0.0) {
            $pct = round((float) $actual * 100 / (float) $target, 2);
            $extra['achievement_pct'] = ['?', [$pct]];
            if (! array_key_exists('status', $values)) {
                $values['status'] = $pct >= 100 ? 'achieved' : ($pct >= 70 ? 'on_track' : 'at_risk');
            }
            $values['measured_at'] = $this->value($values, $current, 'measured_at') ?? now()->toIso8601String();
        }
    }

    private function delivery(array &$values, ?object $current): void
    {
        $version = $this->value($values, $current, 'risst_version_id');
        if (DB::selectOne('select status from risst_versions where id = ?', [$version])?->status !== 'current') {
            $this->fail('risst_version_id', 'Solo se entrega la versión vigente del RISST.');
        }
        $values['delivered_on'] = $this->value($values, $current, 'delivered_on') ?? date('Y-m-d');
        $values['status'] = $this->value($values, $current, 'status') ?? 'delivered';
    }

    private function ack(array &$values): void
    {
        $version = $values['document_version_id'] ?? null;
        if ($version && DB::selectOne('select status from document_versions where id = ?', [$version])?->status !== 'current') {
            $this->fail('document_version_id', 'Solo se toma conocimiento de la versión vigente del documento.');
        }
        $values['acknowledged_at'] ??= now()->toIso8601String();
    }
}
