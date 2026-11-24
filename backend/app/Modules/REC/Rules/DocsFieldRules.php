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
 * Integración con el PASST (RN-PAS-05): un simulacro evaluado, una inspección finalizada o una capacitación realizada,
 * vinculados a una actividad del programa o que cumplen su regla automática, registran su ejecución.
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

    private const VFLOW = ['draft' => ['in_review', 'approved'], 'in_review' => ['approved', 'draft'], 'approved' => ['current', 'draft'], 'current' => ['obsolete', 'review_required'], 'review_required' => ['current', 'obsolete'], 'obsolete' => []];

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
            'talk_attendances' => $this->attendance($values, $current),
            'ats_steps' => $this->atsChild($values, $current, false),
            'ats_participants' => $this->atsChild($values, $current, true),
            'ats_forms' => $this->ats($op, $values, $current, $extra),
            'drills' => $this->drill($op, $values, $current),
            'objective_targets' => $this->target($values, $current, $extra),
            'risst_deliveries' => $this->delivery($values, $current),
            'document_acknowledgements' => $this->ack($values),
            'risk_map_markers' => $this->marker($values, $current),
            'emergency_equipment' => $this->equipment($values, $current, $extra),
            default => null,
        };
    }

    public function prepareDelete(array $res, object $current): void
    {
        if ($res['table'] === 'risk_map_markers') {
            $this->marker([], $current);
        }
        // RN-REG-07 (D.S. 005-2012-TR art. 35): un registro generado se conserva el periodo de su tipo; antes no se elimina.
        if ($res['table'] === 'register_exports' && ! in_array($current->status, ['queued', 'generating', 'failed'], true) && $current->generated_at) {
            $key = ['accidents' => 'records_retention_accidents_years', 'incidents' => 'records_retention_accidents_years',
                'occupational_diseases' => 'records_retention_diseases_years'][$current->register_type] ?? 'records_retention_other_years';
            $years = (int) \App\Core\Support\LegalParams::number($key, 5);
            $until = date('Y-m-d', strtotime(substr((string) ($current->period_to ?: $current->generated_at), 0, 10).' +'.$years.' years'));
            if ($until > date('Y-m-d')) {
                $this->conflict('Este registro se conserva hasta el '.date('d/m/Y', strtotime($until))." ({$years} años según su tipo): no se elimina antes. Si cambió, genera una nueva versión.");
            }
        }
        // RN-DOC-08: un documento que llegó a publicarse se conserva durante el plazo de su categoría (retention_param_key);
        // antes de eso no se borra: se deja obsoleto. Los borradores sí se pueden borrar.
        if (in_array($res['table'], ['documents', 'document_versions'], true)) {
            $docId = $res['table'] === 'documents' ? $current->id : $current->document_id;
            $where = $res['table'] === 'documents' ? 'document_id = ?' : 'id = ?';
            $v = DB::selectOne("select max(coalesce(obsoleted_at, effective_until::timestamptz, now())) as last_valid from document_versions
                                 where {$where} and deleted_at is null and status not in ('draft', 'in_review', 'rejected')", [$res['table'] === 'documents' ? $docId : $current->id]);
            if ($v?->last_valid) {
                $key = DB::selectOne('select c.retention_param_key from documents d left join document_categories c on c.id = d.category_id where d.id = ?', [$docId])?->retention_param_key
                    ?: 'records_retention_other_years';
                $years = (int) \App\Core\Support\LegalParams::number($key, 5);
                $until = date('Y-m-d', strtotime($v->last_valid.' +'.$years.' years'));
                if ($until > date('Y-m-d')) {
                    $this->conflict("Este documento se publicó: se conserva hasta el ".date('d/m/Y', strtotime($until))." ({$years} años). Mientras tanto, déjalo obsoleto en lugar de borrarlo.");
                }
            }
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
        // RN-MAP-01: un marcador de peligro va ligado a una fila del IPERC vigente de la misma sede.
        $type = $this->value($values, $current, 'marker_type');
        $row = $this->value($values, $current, 'iperc_row_id');
        if ($type === 'hazard' && (! $current || array_intersect(['marker_type', 'iperc_row_id'], array_keys($values)) !== [])) {
            if (! $row) {
                $this->fail('iperc_row_id', 'Un pictograma de peligro se liga a una fila del IPERC vigente de la sede: elige la fila.');
            }
            $ok = DB::selectOne(
                "select 1 as ok from iperc_rows r join iperc_versions v on v.id = r.iperc_version_id and v.status = 'current' join iperc_matrices m on m.id = v.iperc_matrix_id
                   join risk_maps mp on mp.id = ? where r.id = ? and r.deleted_at is null and (mp.site_id is null or m.site_id is null or m.site_id = mp.site_id)", [$mapId, $row]);
            if (! $ok) {
                $this->fail('iperc_row_id', 'Esa fila no es de un IPERC vigente de esta sede.');
            }
        }
        // RN-MAP-08: el equipo de emergencia ubicado debe existir en el módulo de equipos.
        if (($eq = $this->value($values, $current, 'emergency_equipment_id')) && ! DB::selectOne('select 1 as ok from emergency_equipment where id = ? and deleted_at is null', [$eq])) {
            $this->fail('emergency_equipment_id', 'Ese equipo de emergencia no existe.');
        }
    }

    /** RN-MAP-02: riesgos significativos del IPERC vigente de la sede del mapa que no tienen marcador ni justificación. */
    public static function unmarkedSignificant(string $mapId): array
    {
        return DB::select(
            "select r.id, r.row_number, coalesce(r.task, r.activity, '') as task, r.hazard, r.risk, m.code as matrix
               from risk_maps mp join iperc_matrices m on m.deleted_at is null and (mp.site_id is null or m.site_id is null or m.site_id = mp.site_id)
               join iperc_versions v on v.id = m.current_version_id and v.status = 'current'
               join iperc_rows r on r.iperc_version_id = v.id and r.deleted_at is null and r.is_significant
              where mp.id = ? and not exists (select 1 from risk_map_markers k where k.risk_map_id = mp.id and k.iperc_row_id = r.id and k.deleted_at is null)
                and coalesce(mp.unmarked_justifications ->> r.id::text, '') = ''
              order by m.code, r.row_number", [$mapId]);
    }

    public function after(string $op, array $res, string $id, array $values, ?object $current): void
    {
        $t = $res['table'];
        if ($t === 'substandard_reports' && $op === 'create') {
            $code = DB::selectOne('select code from substandard_reports where id = ?', [$id])->code;
            \App\Modules\NOT\Notify::newReport(app(\App\Core\Tenancy\CompanyContext::class)->companyId, $code);
        }
        // M-DOC §10: versión enviada a revisión → aviso a quienes la aprueban según la categoría.
        if ($t === 'document_versions' && ($values['status'] ?? null) === 'in_review' && $current?->status !== 'in_review') {
            $d = DB::selectOne("select d.id, d.code, d.title, coalesce(c.requires_approval_by, 'sst') as req_by from document_versions v join documents d on d.id = v.document_id
                                  left join document_categories c on c.id = d.category_id where v.id = ?", [$id]);
            [$roles] = \App\Modules\DOC\ApprovalController::APPROVERS[$d->req_by] ?? \App\Modules\DOC\ApprovalController::APPROVERS['sst'];
            \App\Modules\NOT\Notify::users(\App\Modules\NOT\Notify::roles($roles, 'documents', $d->id), 'doc.review_pending', 'DOC', $d->id, 'Documento por aprobar',
                "{$d->code} · {$d->title} se envió a revisión.", '/m/doc?t=documents&open='.$d->id, 'normal', 'doc.review_pending:'.$id);
        }
        // M-PET RN-PET-03: la versión aprobada deja su capacitación pendiente para los puestos vinculados en CAP.
        if ($t === 'procedure_versions' && in_array($values['status'] ?? null, ['approved', 'current'], true) && ! in_array($current?->status, ['approved', 'current'], true)) {
            \App\Modules\PET\PetStepsController::requireTrainings($id);
        }
        if ($t === 'report_points' && $op === 'create') {
            $token = \App\Modules\ACT\ReportPointController::token(app(\App\Core\Tenancy\CompanyContext::class)->companyId, $id);
            DB::update("update report_points set token_hash = decode(?, 'hex'), token_last4 = ? where id = ?", [hash('sha256', $token), substr($token, -4), $id]);

            return;
        }
        if (isset(self::VERSIONED[$t]) && ($values['status'] ?? null) === 'current' && $current?->status !== 'review_required') {
            [$col, $parent] = self::VERSIONED[$t];
            $row = DB::selectOne("select * from \"{$t}\" where id = ?", [$id]);
            if ($parent) {
                DB::update("update \"{$parent}\" set current_version_id = ? where id = ?", [$id, $row->{$col}]);
                // Un documento con versión vigente queda vigente (deja de ser borrador).
                if ($parent === 'documents') {
                    DB::update("update documents set status = 'active' where id = ? and status = 'draft'", [$row->{$col}]);
                    // RN-DOC-04: la próxima revisión se calcula con la vigencia de la categoría (si la tiene).
                    DB::update("update documents d set next_review_on = ((now() at time zone 'America/Lima')::date + make_interval(months => coalesce(c.default_validity_months, c.review_period_months)))::date
                                  from document_categories c where d.id = ? and c.id = d.category_id and coalesce(c.default_validity_months, c.review_period_months) is not null", [$row->{$col}]);
                }
            }
        }
        if ($t === 'equipment_checks') {
            $c = DB::selectOne('select * from equipment_checks where id = ?', [$id]);
            // RN-EQE-01/02: la recarga, la prueba hidrostática y la inspección mensual mueven sus próximas fechas.
            $on = substr($c->checked_at, 0, 10);
            match ($c->check_type) {
                'recharge' => DB::update('update emergency_equipment set last_recharge_on = ?, next_recharge_on = ?::date + make_interval(months => ?::int) where id = ?',
                    [$on, $on, (int) \App\Core\Support\LegalParams::number('extinguisher_recharge_months', 12), $c->equipment_id]),
                'hydrostatic_test' => DB::update('update emergency_equipment set last_hydrostatic_test_on = ?, next_hydrostatic_test_on = ?::date + make_interval(years => ?::int) where id = ?',
                    [$on, $on, (int) \App\Core\Support\LegalParams::number('hydrostatic_test_years', 5), $c->equipment_id]),
                'monthly_inspection' => DB::update('update emergency_equipment set next_inspection_on = ?::date + ?::int where id = ?',
                    [$on, (int) \App\Core\Support\LegalParams::number('equipment_check_days', 30), $c->equipment_id]),
                default => null,
            };
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
            // RN-SIM-06: un simulacro con observaciones (por mejorar o no satisfactorio) abre su acción correctiva.
            $d = DB::selectOne('select company_id, title, result, evaluation, site_id from drills where id = ?', [$id]);
            if (in_array($d->result, ['needs_improvement', 'unsatisfactory'], true)
                && ! DB::selectOne("select 1 as ok from corrective_actions where source_type = 'drill' and source_id = ? and deleted_at is null", [$id])) {
                $notes = [];
                $ev = json_decode((string) $d->evaluation, true);
                $ev = is_array($ev) ? $ev : [];
                // Evaluación por criterios (SCR-SIM-03): solo lo no cumplido y las observaciones.
                if (array_key_exists('criteria', $ev)) {
                    $ev = array_merge((array) ($ev['not_met'] ?? []), isset($ev['observations']) ? [$ev['observations']] : []);
                }
                array_walk_recursive($ev, function ($v) use (&$notes) {
                    if (is_string($v) && trim($v) !== '') {
                        $notes[] = trim($v);
                    }
                });
                $desc = 'Resultado del simulacro: '.($d->result === 'unsatisfactory' ? 'no satisfactorio' : 'por mejorar').'.'.($notes ? ' Observaciones: '.implode('; ', $notes) : '');
                \App\Modules\ACR\AutoAction::create($d->company_id, 'drill', $id, mb_substr('Corregir lo observado en el simulacro: '.$d->title, 0, 300),
                    mb_substr($desc, 0, 2000), null, null, $d->result === 'unsatisfactory' ? 'high' : 'medium', $d->site_id);
            }
        }
    }

    /**
     * Cómo se lee cada registro origen para la ejecución automática (RN-PAS-05): fecha que cuenta, sede, área y título.
     * tabla => [fecha (Lima), sede, área, título, tiene program_activity_id]
     */
    private const PROGRAM_SOURCES = [
        'inspections' => ["(coalesce(completed_at, scheduled_on::timestamptz) at time zone 'America/Lima')::date", 'site_id', 'area_id', 'title', true],
        'drills' => ["(coalesce(executed_at, scheduled_at) at time zone 'America/Lima')::date", 'site_id', 'null::uuid', 'title', true],
        'training_sessions' => ["(starts_at at time zone 'America/Lima')::date", 'site_id', 'null::uuid', "(select t.title from trainings t where t.id = training_sessions.training_id)", false],
    ];

    /**
     * RN-PAS-05: el registro origen cuenta como ejecución de la actividad del PASST (una sola vez por actividad y registro):
     *  - la actividad vinculada a mano (program_activity_id del registro), y
     *  - las actividades con regla automática «al registrarse en el módulo origen» (SCR-PAS-02): mismo módulo vinculado,
     *    programa aprobado del año del registro, mes del registro entre sus meses programados y, si la actividad tiene
     *    sede o área, la misma sede o área. Si en ese mes ya tiene una ejecución automática, no se suma otra.
     * La ejecución queda pendiente de validación del Jefe SST.
     */
    public static function programExecutionFor(string $table, string $id, string $module): void
    {
        [$dateSql, $siteCol, $areaCol, $titleSql, $linkable] = self::PROGRAM_SOURCES[$table];
        $r = DB::selectOne("select company_id, {$dateSql} as d, {$siteCol} as site_id, {$areaCol} as area_id, {$titleSql} as title"
            .($linkable ? ', program_activity_id' : ', null::uuid as program_activity_id')." from \"{$table}\" where id = ?", [$id]);
        if (! $r) {
            return;
        }
        $date = (string) ($r->d ?? now('America/Lima')->toDateString());
        $month = (int) substr($date, 5, 2);
        $targets = [];
        if ($r->program_activity_id) {
            $targets[] = $r->program_activity_id;
        }
        $auto = DB::select(
            "select a.id from program_activities a join annual_programs p on p.id = a.program_id
              where a.deleted_at is null and p.deleted_at is null and a.status <> 'cancelled' and a.auto_execution_rule = 'on_source_record'
                and a.linked_module = ? and p.status in ('approved', 'in_execution') and p.year = ?
                and ?::smallint = any(a.planned_months)
                and (a.site_id is null or a.site_id = ?) and (a.area_id is null or a.area_id = ?)
                and not exists (select 1 from activity_executions x where x.activity_id = a.id and x.deleted_at is null and x.source_module = ?
                                 and x.source_id is not null and x.planned_month = ?)",
            [$module, (int) substr($date, 0, 4), $month, $r->site_id, $r->area_id, $module, $month]);
        foreach ($auto as $a) {
            $targets[] = $a->id;
        }
        foreach (array_unique($targets) as $activity) {
            if (DB::selectOne('select 1 as ok from activity_executions where activity_id = ? and source_id = ? and deleted_at is null', [$activity, $id])) {
                continue;
            }
            $st = DB::selectOne('select p.status from program_activities a join annual_programs p on p.id = a.program_id where a.id = ?', [$activity])?->status;
            if (! in_array($st, ['approved', 'in_execution'], true)) {
                continue;
            }
            $by = auth()->id() ? (string) auth()->id() : null;
            $exec = DB::selectOne(
                "insert into activity_executions (company_id, activity_id, executed_on, planned_month, quantity, description, source_module, source_id, recorded_by, created_by, updated_by)
                 values (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?) returning id",
                [$r->company_id, $activity, $date, $month, mb_substr("Registrado desde {$module}: ".($r->title ?? ''), 0, 500), $module, $id, $by, $by, $by]
            )->id;
            if ($st === 'approved') {
                DB::update("update annual_programs set status = 'in_execution' from program_activities a where a.id = ? and annual_programs.id = a.program_id", [$activity]);
            }
            (new ProgramRules)->after('create', ['table' => 'activity_executions'], $exec, [], null);
        }
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
        if (in_array($current->status, ['current', 'review_required', 'obsolete'], true) && array_diff(array_keys($values), ['status']) !== []) {
            $this->conflict('La versión ya está vigente u obsoleta: su contenido no cambia. Crea una nueva versión.');
        }
        $to = $values['status'] ?? null;
        if (! $to || $to === $current->status) {
            return;
        }
        if (! in_array($to, self::VFLOW[$current->status] ?? [], true)) {
            $this->fail('status', 'Ese cambio de estado no está permitido.');
        }
        $hasSteps = $t === 'procedure_versions' && (json_decode((string) ($current->steps ?? '[]'), true) ?: []) !== [];
        if (in_array($to, ['in_review', 'approved', 'current'], true) && $t !== 'risk_maps' && ! $hasSteps
            && trim(strip_tags((string) $this->value($values, $current, 'content_html'))) === '' && ! $this->value($values, $current, 'file_id')) {
            $this->fail('status', 'La versión está vacía: escribe su contenido o adjunta el documento antes de enviarla o aprobarla.');
        }
        if ($t === 'risk_maps' && in_array($to, ['in_review', 'approved', 'current'], true) && ($miss = self::unmarkedSignificant($current->id))) {
            $this->fail('status', count($miss).' riesgo(s) significativo(s) del IPERC de la sede no tienen pictograma en el mapa (por ejemplo «'
                .mb_substr(trim($miss[0]->hazard.' – '.$miss[0]->risk, ' –'), 0, 80).'»): ubícalos o justifica por qué no van.');
        }
        // M-PET RN-PET-04: un paso con riesgo significativo debe tener su control antes de revisarse o aprobarse.
        if ($t === 'procedure_versions' && in_array($to, ['in_review', 'approved'], true) && ($msg = \App\Modules\PET\PetStepsController::missingControl($current->id))) {
            $this->fail('status', $msg);
        }
        if ($t === 'sst_policies' && in_array($to, ['in_review', 'approved'], true)) {
            // RN-POL-01: no se envía a revisión ni se aprueba sin todos los principios del art. 23 marcados como incluidos.
            $need = \App\Core\Support\LegalParams::list('pol_policy_principles');
            $raw = $this->value($values, $current, 'principles_checklist');
            for ($i = 0; $i < 2 && is_string($raw); $i++) {
                $raw = json_decode($raw, true);
            }
            $have = is_array($raw) ? $raw : [];
            $missing = array_values(array_diff($need, $have));
            if ($need && $missing) {
                $this->fail('principles_checklist', 'Marca todos los principios del art. 23 que la política incluye. Falta: «'.implode('», «', $missing).'».');
            }
        }
        if ($t === 'risst_versions' && in_array($to, ['in_review', 'approved'], true)) {
            // RN-RIS-02: no se envía ni se aprueba sin todas las secciones mínimas del art. 74 marcadas.
            $need = \App\Core\Support\LegalParams::list('ris_risst_sections');
            $raw = $this->value($values, $current, 'content_outline');
            for ($i = 0; $i < 2 && is_string($raw); $i++) {
                $raw = json_decode($raw, true);
            }
            $missing = array_values(array_diff($need, is_array($raw) ? $raw : []));
            if ($need && $missing) {
                $this->fail('content_outline', 'Marca todas las secciones mínimas del art. 74 que el reglamento incluye. Falta: «'.implode('», «', $missing).'».');
            }
        }
        if ($t === 'sst_policies' && $to === 'approved' && ! in_array('pol.sst_policies.approve', \App\Core\Auth\Permissions::effective(), true)) {
            // RN-POL-02: solo gerencia aprueba la política.
            $this->fail('status', 'La política la aprueba la gerencia (su rol tiene el permiso de aprobar la política).');
        }
        if ($t === 'sst_policies' && $to === 'approved' && request()->attributes->get('pol_otp_verified') !== $current->id) {
            // RN-POL-02: la aprobación exige firma con OTP (POST /pol/policies/{id}/approve).
            $this->fail('status', 'La política se aprueba con el código que llega a tu correo: usa «Aprobar con código».');
        }
        if ($to === 'approved') {
            $author = $current->prepared_by ?? $current->submitted_by ?? null;
            if ($author && $author === (string) auth()->id()) {
                $perm = strtolower((string) (\App\Modules\REC\Registry::data()['resources'][$t]['module'] ?? 'doc')).".{$t}.update";
                $this->fail('status', 'Quien elaboró la versión no puede aprobarla: la aprueba otra persona (gerencia, comité o supervisor).'.\App\Core\Support\Approvers::hint($perm));
            }
            // RN-DOC-03: la categoría del documento decide quién aprueba (también por la API común, no solo la bandeja).
            if ($t === 'document_versions') {
                $req = DB::selectOne("select coalesce(c.requires_approval_by, 'sst') as r from documents d left join document_categories c on c.id = d.category_id where d.id = ?",
                    [$current->document_id])?->r ?? 'sst';
                [$roles, $label] = \App\Modules\DOC\ApprovalController::APPROVERS[$req] ?? \App\Modules\DOC\ApprovalController::APPROVERS['sst'];
                if (! in_array(app(\App\Core\Tenancy\CompanyContext::class)->roleCode, $roles, true)) {
                    $this->fail('status', "Esta versión la aprueba {$label} (según la categoría del documento).");
                }
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
        if ($to === 'current' && $current->status !== 'review_required') {
            // Una sola vigente: la anterior pasa a obsoleta antes de publicar esta.
            DB::update("update \"{$t}\" set status = 'obsolete'".($t === 'risk_maps' ? '' : ', obsoleted_at = now()')." where id <> ? and status in ('current', 'review_required')".($col ? " and {$col} = ?" : ''),
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
                // App del trabajador: el reporte queda ligado a su ficha (Mis reportes, aviso al cerrarse).
                $ctx = app(\App\Core\Tenancy\CompanyContext::class);
                $own = DB::selectOne('select worker_id from memberships where id = ?', [$ctx->membershipId])?->worker_id;
                if ($own && ($ctx->roleCode === 'worker' || empty($values['reporter_worker_id']))) {
                    unset($values['reporter_worker_id']);
                    $extra['reporter_worker_id'] = ['?', [$own]];
                }
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
        // RN-ACT-06: un reporte clasificado como incidente peligroso se escala a ACC (donde corren los plazos de notificación);
        // no se atiende ni se cierra solo con una acción.
        $class = mb_strtolower(trim((string) $this->value($values, $current, 'classification')));
        if (in_array($to, ['action_assigned', 'closed'], true) && ! $current->escalated_event_id
            && ($class === 'dangerous_incident' || str_contains($class, 'incidente peligroso'))) {
            $this->fail('status', 'Este reporte es un incidente peligroso: escálalo a Accidentes e incidentes para que corran sus plazos de notificación.');
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
        // RN-CH5-04: una charla realizada no se modifica (su asistencia es evidencia de capacitación).
        if ($current && $current->status === 'held' && $values !== []) {
            $this->conflict('La charla ya se realizó: no se modifica. Si hubo un error, registra una nueva charla.');
        }
        // RN-CH5-01: se marca «realizada» con al menos un asistente que firmó (o que marcó su asistencia escaneando el QR él mismo).
        if (($values['status'] ?? null) === 'held' && $current
            && ! DB::selectOne("select 1 as ok from talk_attendances where safety_talk_id = ? and deleted_at is null and (signature_id is not null or method = 'qr_scan')", [$current->id])) {
            $this->fail('status', DB::selectOne('select 1 as ok from talk_attendances where safety_talk_id = ? and deleted_at is null', [$current->id])
                ? 'Ningún asistente firmó todavía: registra al menos una firma antes de marcar la charla como realizada.'
                : 'Registra la asistencia de la charla antes de marcarla como realizada.');
        }
    }

    /** RN-CH5-04: la asistencia de una charla realizada o cancelada ya no cambia. */
    private function attendance(array &$values, ?object $current): void
    {
        $talk = $this->value($values, $current, 'safety_talk_id');
        $st = $talk ? DB::selectOne('select status from safety_talks where id = ?', [$talk])?->status : null;
        if (in_array($st, ['held', 'cancelled'], true)) {
            $this->conflict($st === 'held' ? 'La charla ya se realizó: su asistencia no se modifica.' : 'La charla está cancelada.');
        }
    }

    /** RN-ATS-04: un ATS firmado o en ejecución no cambia sus pasos; los participantes que llegan tarde firman con marca de firma tardía. */
    private function atsChild(array &$values, ?object $current, bool $participant): void
    {
        unset($values['late_signature']);   // la marca la pone el sistema
        $ats = $this->value($values, $current, 'ats_form_id');
        $form = $ats ? DB::selectOne('select status, started_at from ats_forms where id = ?', [$ats]) : null;
        $st = $form?->status;
        // RN-ATS-02: firmar después de la hora de inicio del trabajo también es «firma tardía».
        if ($participant && $form?->started_at && ($values['signed_at'] ?? null) && strtotime((string) $values['signed_at']) > strtotime((string) $form->started_at)) {
            $values['late_signature'] = true;
        }
        if (in_array($st, ['closed', 'cancelled'], true)) {
            $this->conflict('El ATS está cerrado: no se modifica.');
        }
        if (in_array($st, ['signed', 'in_execution'], true)) {
            if (! $participant) {
                $this->conflict('El ATS ya fue firmado: sus pasos, peligros y controles no se cambian. Si la tarea cambió, haz un ATS nuevo.');
            }
            if ($current && $current->signed_at && $values !== []) {
                $this->conflict('Esta firma ya está registrada: no se modifica.');
            }
            // RN-ATS-02: quien se suma después de firmado el ATS firma con marca de «firma tardía».
            if ($current === null || ($values['signed_at'] ?? null)) {
                $values['late_signature'] = true;
            }
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
        // RN-ATS-05: «no cerrado» lo pone el sistema al pasar el día; se cierra igual (queda constancia del atraso).
        $flow = ['draft' => ['signed', 'cancelled'], 'signed' => ['in_execution', 'draft', 'cancelled'], 'in_execution' => ['closed'], 'not_closed' => ['closed'], 'closed' => [], 'cancelled' => []];
        if ($current && $to && $to !== $current->status && ! in_array($to, $flow[$current->status] ?? [], true)) {
            $this->fail('status', $current->status === 'draft' ? 'Primero firma el ATS con todos los participantes.' : 'Ese cambio de estado no está permitido.');
        }
        // RN-ATS-07: una tarea de alto riesgo necesita su PETAR vinculado.
        if (in_array($to, ['signed', 'in_execution'], true) && $current && $this->value($values, $current, 'is_high_risk') && ! $this->value($values, $current, 'work_permit_id')) {
            $this->fail('work_permit_id', 'Es una tarea de alto riesgo: vincula su permiso de trabajo (PETAR) antes de firmar el ATS.');
        }
        // RN-ATS-04: firmado o en ejecución, el ATS solo cambia de estado.
        if ($current && in_array($current->status, ['signed', 'in_execution'], true)
            && array_diff(array_keys($values), ['status', 'started_at', 'closed_at', 'work_permit_id']) !== [] && ($to === null || $to === $current->status)) {
            $this->conflict('El ATS ya fue firmado: no se edita. Si la tarea cambió, devuélvelo a borrador (antes de iniciar) o haz un ATS nuevo.');
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
        // RN-ATS-01: lo firma el supervisor responsable y al menos un ejecutante.
        if ($to === 'signed' && $current && ! $this->value($values, $current, 'supervisor_user_id')) {
            $this->fail('supervisor_user_id', 'Indica al supervisor responsable que firma el ATS.');
        }
        if ($to === 'in_execution') {
            $values['started_at'] = $this->value($values, $current, 'started_at') ?? now()->toIso8601String();
        }
        if ($to === 'closed' && $current && $current->status !== 'closed') {
            // SCR-ATS-04: cierre con hora de término, observaciones e incidentes ocurridos; solo esos datos cambian al cerrar.
            if (array_diff(array_keys($values), ['status', 'closed_at', 'closing_notes', 'incident_occurred', 'incident_event_id']) !== []) {
                $this->conflict('Al cerrar el ATS solo se registran la hora de término, las observaciones y si ocurrió un incidente.');
            }
            $end = $values['closed_at'] ?? null;
            if ($end && strtotime((string) $end) > time() + 300) {
                $this->fail('closed_at', 'La hora de término no puede ser futura.');
            }
            if ($end && $current->started_at && strtotime((string) $end) < strtotime((string) $current->started_at)) {
                $this->fail('closed_at', 'La hora de término es anterior al inicio de la tarea.');
            }
            $values['closed_at'] = $end ?: now()->toIso8601String();
            // Flujo §7.4: si ocurrió un evento, se enlaza al registrado en Accidentes e incidentes.
            if (! empty($values['incident_occurred']) && empty($values['incident_event_id'])) {
                $this->fail('incident_event_id', 'Ocurrió un incidente: elige el evento registrado en «Accidentes e incidentes» (regístralo primero si aún no está).');
            }
            if (! empty($values['incident_event_id'])) {
                $values['incident_occurred'] = true;
            } elseif (array_key_exists('incident_occurred', $values) && empty($values['incident_occurred'])) {
                $values['incident_event_id'] = null;
            }
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
        // RN-SIM-05: evaluado (o cancelado) el simulacro ya no se edita.
        if ($current && in_array($current->status, ['evaluated', 'cancelled'], true) && $values !== []) {
            $this->conflict($current->status === 'evaluated' ? 'El simulacro ya fue evaluado: no se modifica.' : 'El simulacro está cancelado.');
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
            if (! $this->value($values, $current, 'report_file_id')) {
                $this->fail('report_file_id', 'Para evaluar el simulacro adjunta su informe: qué salió bien, qué falló y qué se mejora.');
            }
        }
    }

    /** RN-EQE-01/07: próximas recarga y prueba hidrostática desde las últimas; el extintor que pasó su vida útil queda fuera de servicio. */
    private function equipment(array &$values, ?object $current, array &$extra): void
    {
        if ($this->value($values, $current, 'equipment_type') !== 'fire_extinguisher') {
            return;
        }
        if (array_key_exists('last_recharge_on', $values) && $values['last_recharge_on']) {
            $extra['next_recharge_on'] = ['?', [date('Y-m-d', strtotime($values['last_recharge_on'].' +'.(int) \App\Core\Support\LegalParams::number('extinguisher_recharge_months', 12).' months'))]];
        }
        if (array_key_exists('last_hydrostatic_test_on', $values) && $values['last_hydrostatic_test_on']) {
            $extra['next_hydrostatic_test_on'] = ['?', [date('Y-m-d', strtotime($values['last_hydrostatic_test_on'].' +'.(int) \App\Core\Support\LegalParams::number('hydrostatic_test_years', 5).' years'))]];
        }
        $made = $this->value($values, $current, 'manufacture_date');
        if ($made && date('Y-m-d', strtotime($made.' +'.(int) \App\Core\Support\LegalParams::number('extinguisher_max_life_years', 20).' years')) < date('Y-m-d')
            && $this->value($values, $current, 'status') !== 'retired') {
            $values['status'] = 'out_of_service';
        }
    }

    private function target(array &$values, ?object $current, array &$extra): void
    {
        // RN-POL-06: con el objetivo ligado a un indicador, el valor actual sale del último valor guardado del indicador.
        $kpi = ($o = $this->value($values, $current, 'objective_id')) ? DB::selectOne('select indicator_kpi_id from sst_objectives where id = ?', [$o])?->indicator_kpi_id : null;
        if ($kpi) {
            if (array_key_exists('actual_value', $values) && ! app()->bound('pol.kpi_refresh')) {
                $this->fail('actual_value', 'Esta meta está ligada a un indicador: su valor actual se toma del indicador y no se edita a mano.');
            }
            if (! $current && ($last = self::kpiValue($kpi, $this->value($values, $current, 'target_on')))) {
                $values['actual_value'] = $last;
            }
        }
        $target = $this->value($values, $current, 'target_value');
        $actual = $this->value($values, $current, 'actual_value');
        if ($actual !== null && $target !== null && (float) $target != 0.0) {
            $pct = round((float) $actual * 100 / (float) $target, 2);
            $extra['achievement_pct'] = ['?', [$pct]];
            if (! array_key_exists('status', $values)) {
                // RN-POL-07: el umbral ámbar es el parámetro objective_amber_pct.
                $amber = \App\Core\Support\LegalParams::number('objective_amber_pct', 70);
                $values['status'] = $pct >= 100 ? 'achieved' : ($pct >= $amber ? 'on_track' : 'at_risk');
            }
            $values['measured_at'] = $this->value($values, $current, 'measured_at') ?? now()->toIso8601String();
        }
    }

    /** Último valor guardado del indicador (toda la empresa) hasta la fecha de la meta. */
    public static function kpiValue(string $kpi, ?string $until): ?float
    {
        $v = DB::selectOne('select value from kpi_snapshots where kpi_definition_id = ? and site_id is null and area_id is null and deleted_at is null and value is not null
                              and (?::date is null or period_end <= ?::date) order by period_end desc limit 1', [$kpi, $until, $until])?->value;

        return $v === null ? null : (float) $v;
    }

    /** RN-POL-06/07: actualiza las metas ligadas a un indicador con su último valor y su color (lo llama el guardado diario de indicadores). */
    public static function refreshKpiTargets(): int
    {
        $amber = (float) \App\Core\Support\LegalParams::number('objective_amber_pct', 70);
        $n = 0;
        foreach (DB::select("select t.id, t.target_value, t.target_on, o.indicator_kpi_id from objective_targets t join sst_objectives o on o.id = t.objective_id
                              where t.deleted_at is null and o.deleted_at is null and o.indicator_kpi_id is not null and t.status <> 'achieved'") as $t) {
            $v = self::kpiValue($t->indicator_kpi_id, $t->target_on);
            if ($v === null || ! (float) $t->target_value) {
                continue;
            }
            $pct = round($v * 100 / (float) $t->target_value, 2);
            $n += DB::update('update objective_targets set actual_value = ?, achievement_pct = ?, status = ?, measured_at = now(), updated_at = now() where id = ?',
                [$v, $pct, $pct >= 100 ? 'achieved' : ($pct >= $amber ? 'on_track' : 'at_risk'), $t->id]);
        }

        return $n;
    }

    private function delivery(array &$values, ?object $current): void
    {
        $version = $this->value($values, $current, 'risst_version_id');
        if (DB::selectOne('select status from risst_versions where id = ?', [$version])?->status !== 'current') {
            $this->fail('risst_version_id', 'Solo se entrega la versión vigente del RISST.');
        }
        if (! $current && ! $this->value($values, $current, 'worker_id') && ! $this->value($values, $current, 'contractor_worker_id')) {
            $this->fail('worker_id', 'Indica el trabajador que recibe el RISST.');
        }
        // RN-RIS-04: al publicar el RISST cada trabajador queda con una entrega pendiente; registrar la entrega la reemplaza.
        if (! $current && ($worker = $this->value($values, $current, 'worker_id'))) {
            DB::update("update risst_deliveries set deleted_at = now() where risst_version_id = ? and worker_id = ? and status = 'pending' and deleted_at is null", [$version, $worker]);
        }
        // RN-RIS-07: una entrega firmada no cambia; un error se anula con motivo y se hace una nueva entrega.
        if ($current && in_array($current->status, ['signed', 'voided'], true)) {
            if ($current->status === 'voided' || array_diff(array_keys($values), ['status', 'void_reason']) !== [] || ($values['status'] ?? null) !== 'voided') {
                $this->conflict($current->status === 'voided' ? 'La entrega está anulada.' : 'La entrega ya está firmada: no se modifica. Si hubo un error, anúlala con el motivo y registra otra.');
            }
        }
        if (($values['status'] ?? null) === 'voided') {
            if (! trim((string) $this->value($values, $current, 'void_reason'))) {
                $this->fail('void_reason', 'Indica por qué se anula la entrega.');
            }

            return;
        }
        // RN-RIS-05: la entrega física se respalda con el cargo firmado escaneado.
        if ($this->value($values, $current, 'method') === 'physical_signed' && ! $this->value($values, $current, 'acknowledgement_file_id')) {
            $this->fail('acknowledgement_file_id', 'Adjunta el cargo de entrega firmado por el trabajador (escaneado o foto).');
        }
        $values['delivered_on'] = $this->value($values, $current, 'delivered_on') ?? date('Y-m-d');
        $values['status'] = $this->value($values, $current, 'status') ?? 'delivered';
        if ($this->value($values, $current, 'method') === 'physical_signed' && $values['status'] === 'delivered') {
            $values['status'] = 'signed';
        }
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
