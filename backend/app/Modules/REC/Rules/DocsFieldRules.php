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
            'talk_attendances' => $this->attendance($values, $current),
            'ats_steps' => $this->atsChild($values, $current, false),
            'ats_participants' => $this->atsChild($values, $current, true),
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
        if ($t === 'substandard_reports' && $op === 'create') {
            $code = DB::selectOne('select code from substandard_reports where id = ?', [$id])->code;
            \App\Modules\NOT\Notify::newReport(app(\App\Core\Tenancy\CompanyContext::class)->companyId, $code);
        }
        // M-DOC §10: versión enviada a revisión → aviso a quienes la aprueban según la categoría.
        if ($t === 'document_versions' && ($values['status'] ?? null) === 'in_review' && $current?->status !== 'in_review') {
            $d = DB::selectOne("select d.id, d.code, d.title, coalesce(c.requires_approval_by, 'sst') as req_by from document_versions v join documents d on d.id = v.document_id
                                  left join document_categories c on c.id = d.category_id where v.id = ?", [$id]);
            [$roles] = \App\Modules\DOC\ApprovalController::APPROVERS[$d->req_by] ?? \App\Modules\DOC\ApprovalController::APPROVERS['sst'];
            \App\Modules\NOT\Notify::users(\App\Modules\NOT\Notify::roles($roles), 'doc.review_pending', 'DOC', $d->id, 'Documento por aprobar',
                "{$d->code} · {$d->title} se envió a revisión.", '/m/doc?t=documents&open='.$d->id, 'normal', 'doc.review_pending:'.$id);
        }
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
        // RN-CH5-01: se marca «realizada» con al menos un asistente que firmó (o registrado por QR o lista física).
        if (($values['status'] ?? null) === 'held' && $current
            && ! DB::selectOne("select 1 as ok from talk_attendances where safety_talk_id = ? and deleted_at is null and (signature_id is not null or method <> 'signature')", [$current->id])) {
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
        $st = $ats ? DB::selectOne('select status from ats_forms where id = ?', [$ats])?->status : null;
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
        $flow = ['draft' => ['signed', 'cancelled'], 'signed' => ['in_execution', 'draft', 'cancelled'], 'in_execution' => ['closed'], 'closed' => [], 'cancelled' => []];
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
