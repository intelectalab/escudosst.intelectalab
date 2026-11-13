<?php

namespace App\Modules\IPC\Services;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Flujo de la matriz IPERC (docs/modulos/M-IPC.md §7 y §9):
 * borrador → en revisión → vigente (la anterior pasa a obsoleta); en revisión → borrador si se observa.
 */
final class IpercWorkflow
{
    public function __construct(private RiskEvaluator $eval) {}

    /** Todo lo que necesita el editor: matriz, criterios, versiones, filas con controles y mapa de calor. */
    public function workspace(string $matrixId, ?string $versionId): array
    {
        $m = $this->matrix($matrixId);
        $versions = DB::select(
            "select v.id, v.version_number, v.status, v.change_reason, v.participants, v.prepared_at, v.approved_at, v.effective_on, v.obsoleted_at,
                    pu.full_name as prepared_by_name, au.full_name as approved_by_name, v.prepared_by, v.criteria_snapshot
               from iperc_versions v
               left join users pu on pu.id = v.prepared_by
               left join users au on au.id = v.approved_by
              where v.iperc_matrix_id = ? and v.deleted_at is null order by v.version_number desc",
            [$matrixId]
        );
        if ($versionId !== null && ! collect($versions)->contains('id', $versionId)) {
            throw ApiProblem::notFound('Esa versión no es de esta matriz.');
        }
        // Por defecto: la versión en trabajo (borrador o en revisión) y, si no hay, la vigente.
        $selected = $versionId
            ?? collect($versions)->first(fn ($v) => in_array($v->status, ['draft', 'in_review'], true))?->id
            ?? collect($versions)->firstWhere('status', 'current')?->id
            ?? $versions[0]->id;
        $version = collect($versions)->firstWhere('id', $selected);

        // Una versión aprobada se muestra con los criterios con que se aprobó (RN-IPC-11).
        $criteria = $version->criteria_snapshot ? json_decode($version->criteria_snapshot, true) : $this->eval->criteria($m->risk_criteria_id);
        $rows = $this->rows($selected);

        return [
            'matrix' => $m,
            'criteria' => $criteria,
            'versions' => array_map(fn ($v) => [
                'id' => $v->id, 'version_number' => (int) $v->version_number, 'status' => $v->status, 'change_reason' => $v->change_reason,
                'participants' => json_decode((string) $v->participants, true) ?? [], 'prepared_at' => $v->prepared_at,
                'prepared_by_name' => $v->prepared_by_name, 'approved_at' => $v->approved_at, 'approved_by_name' => $v->approved_by_name,
                'effective_on' => $v->effective_on, 'obsoleted_at' => $v->obsoleted_at,
            ], $versions),
            'version_id' => $selected,
            'rows' => $rows,
            'heatmap' => $this->heatmap($rows),
            // Trabajadores activos del puesto de la matriz (cabecera del editor); sin puesto, el mayor «expuestos» de las filas.
            'exposed_workers' => $m->position_id
                ? (int) DB::selectOne("select count(*) as n from workers where current_position_id = ? and deleted_at is null and status in ('active','on_leave')", [$m->position_id])->n
                : (collect($rows)->max('exposed_workers') === null ? null : (int) collect($rows)->max('exposed_workers')),
            'issues' => $version->status === 'draft' ? $this->issues($selected, $criteria, $rows, json_decode((string) $version->participants, true) ?? []) : [],
        ];
    }

    /** Nueva versión en borrador copiando la vigente; la vigente sigue válida hasta aprobar la nueva. */
    public function newVersion(string $matrixId, ?string $reason): array
    {
        $m = $this->matrix($matrixId);
        if (DB::selectOne("select 1 as ok from iperc_versions where iperc_matrix_id = ? and status in ('draft', 'in_review') and deleted_at is null", [$matrixId])) {
            throw new ApiProblem(409, 'conflict', 'Ya hay una versión en trabajo', 'Termina o elimina la versión en borrador o en revisión antes de abrir otra.');
        }
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw $this->invalid('change_reason', 'Indica el motivo: revisión anual, accidente, cambio en el proceso u otro.');
        }
        $source = DB::selectOne("select id from iperc_versions where iperc_matrix_id = ? and status = 'current' and deleted_at is null", [$matrixId])
            ?? DB::selectOne('select id from iperc_versions where iperc_matrix_id = ? and deleted_at is null order by version_number desc limit 1', [$matrixId]);
        $number = (int) DB::selectOne('select coalesce(max(version_number), 0) + 1 as n from iperc_versions where iperc_matrix_id = ?', [$matrixId])->n;
        $user = (string) auth()->id();

        $new = DB::selectOne(
            "insert into iperc_versions (company_id, iperc_matrix_id, version_number, status, change_reason, created_by, updated_by)
             values (?, ?, ?, 'draft', ?, ?, ?) returning id",
            [$m->company_id, $matrixId, $number, mb_substr($reason, 0, 2000), $user, $user]
        )->id;

        // Copia filas y controles (los criterios vigentes de la matriz se aplican al borrador al editar cada fila).
        foreach (DB::select('select * from iperc_rows where iperc_version_id = ? and deleted_at is null order by row_number', [$source->id]) as $r) {
            $rowId = DB::selectOne(
                'insert into iperc_rows (company_id, iperc_version_id, row_number, process, activity, task, is_routine, position_id, hazard_catalog_id,
                    hazard, risk, consequence, exposed_workers, probability_value, probability_detail, severity_value, risk_level, is_significant,
                    residual_probability, residual_severity, residual_level, legal_requirement_id, notes, created_by, updated_by)
                 select company_id, ?, row_number, process, activity, task, is_routine, position_id, hazard_catalog_id, hazard, risk, consequence,
                    exposed_workers, probability_value, probability_detail, severity_value, risk_level, is_significant, residual_probability,
                    residual_severity, residual_level, legal_requirement_id, notes, ?, ? from iperc_rows where id = ? returning id',
                [$new, $user, $user, $r->id]
            )->id;
            DB::insert(
                "insert into iperc_controls (company_id, iperc_row_id, hierarchy, description, is_existing, responsible_user_id, due_on, status,
                    corrective_action_id, ppe_item_id, procedure_id, training_id, created_by, updated_by)
                 select company_id, ?, hierarchy, description, is_existing, responsible_user_id, due_on, status, corrective_action_id, ppe_item_id,
                    procedure_id, training_id, ?, ? from iperc_controls where iperc_row_id = ? and deleted_at is null and status <> 'cancelled'",
                [$rowId, $user, $user, $r->id]
            );
        }
        $this->audit('create', $new, ['version_number' => $number, 'change_reason' => $reason, 'from_version_id' => $source->id]);

        return ['id' => $new, 'version_number' => $number];
    }

    /** Enviar a aprobación: valida las reglas RN-IPC-03, 04, 05 y 07. */
    public function submit(string $versionId, ?array $participants): void
    {
        $v = $this->version($versionId, 'draft', 'Solo se envía una versión en borrador.');
        if ($participants !== null) {
            $participants = $this->cleanParticipants($participants);
            DB::update('update iperc_versions set participants = ?::jsonb where id = ?', [json_encode($participants, JSON_UNESCAPED_UNICODE), $versionId]);
        } else {
            $participants = json_decode((string) $v->participants, true) ?? [];
        }
        $criteria = $this->eval->criteria($v->risk_criteria_id);
        $issues = $this->issues($versionId, $criteria, $this->rows($versionId), $participants);
        if ($issues !== []) {
            throw new ApiProblem(422, 'iperc_incomplete', 'La matriz no está lista para aprobación',
                'Corrige los puntos indicados antes de enviarla.', ['issues' => $issues]);
        }
        DB::update("update iperc_versions set status = 'in_review', prepared_by = ?, prepared_at = now(), updated_by = ? where id = ?",
            [(string) auth()->id(), (string) auth()->id(), $versionId]);
        // M-IPC §10: aviso «enviado a aprobación» a quienes aprueban la matriz.
        $m = DB::selectOne('select m.id, m.code, m.title from iperc_matrices m join iperc_versions v on v.iperc_matrix_id = m.id where v.id = ?', [$versionId]);
        \App\Modules\NOT\Notify::users(\App\Modules\NOT\Notify::roles(['sst_manager', 'sst_supervisor']), 'ipc.submitted', 'IPC', $m->id, 'IPERC enviado a aprobación',
            "La matriz {$m->code} · {$m->title} espera revisión y aprobación.", '/m/ipc?t=iperc_matrices&open='.$m->id, 'normal', 'ipc.submitted:'.$versionId);
        $this->audit('submit', $versionId, ['participants' => count($participants)]);
    }

    /** Observar: vuelve a borrador con el comentario del revisor. */
    public function sendBack(string $versionId, ?string $comment): void
    {
        $this->version($versionId, 'in_review', 'Solo se observa una versión en revisión.');
        $comment = trim((string) $comment);
        if ($comment === '') {
            throw $this->invalid('comment', 'Escribe qué se debe corregir.');
        }
        DB::update("update iperc_versions set status = 'draft', reviewed_by = ?, updated_by = ? where id = ?",
            [(string) auth()->id(), (string) auth()->id(), $versionId]);
        $this->audit('return', $versionId, ['comment' => mb_substr($comment, 0, 2000)]);
    }

    /**
     * Aprobar (RN-IPC-06, 07, 08, 10): la versión queda vigente y congelada con sus criterios; la anterior pasa a obsoleta;
     * próxima revisión = hoy + iperc_review_months; cada control propuesto con responsable y fecha abre una acción correctiva.
     */
    public function approve(string $versionId): array
    {
        $v = $this->version($versionId, 'in_review', 'Solo se aprueba una versión en revisión.');
        if ($v->prepared_by === (string) auth()->id()) {
            throw new ApiProblem(409, 'conflict', 'Aprueba otra persona',
                'Quien elaboró y envió la matriz no puede aprobarla. Debe aprobarla el comité o el supervisor SST (otra persona).'.\App\Core\Support\Approvers::hint('ipc.iperc_versions.update'));
        }
        $criteria = $this->eval->criteria($v->risk_criteria_id);
        $issues = $this->issues($versionId, $criteria, $this->rows($versionId), json_decode((string) $v->participants, true) ?? []);
        if ($issues !== []) {
            throw new ApiProblem(422, 'iperc_incomplete', 'La matriz no está lista para aprobación', 'Hay puntos por corregir: obsérvala para que vuelva a borrador.', ['issues' => $issues]);
        }
        $months = $this->param('iperc_review_months', 12);
        $user = (string) auth()->id();

        DB::update("update iperc_versions set status = 'obsolete', obsoleted_at = now(), updated_by = ?
                     where iperc_matrix_id = ? and status = 'current' and deleted_at is null", [$user, $v->iperc_matrix_id]);
        DB::update(
            "update iperc_versions set status = 'current', approved_by = ?, approved_at = now(), effective_on = (now() at time zone 'America/Lima')::date,
                    criteria_snapshot = ?::jsonb, updated_by = ? where id = ?",
            [$user, json_encode($criteria, JSON_UNESCAPED_UNICODE), $user, $versionId]
        );
        $next = DB::selectOne("select ((now() at time zone 'America/Lima')::date + make_interval(months => ?))::date as d", [$months])->d;
        DB::update('update iperc_matrices set current_version_id = ?, next_review_on = ?, updated_by = ? where id = ?', [$versionId, $next, $user, $v->iperc_matrix_id]);

        \App\Modules\NOT\FichaEvents::ipercApproved($versionId);
        $actions = $this->openActions($v);
        $this->audit('approve', $versionId, ['next_review_on' => $next, 'corrective_actions' => $actions]);

        return ['next_review_on' => $next, 'corrective_actions' => $actions];
    }

    /** Filas con sus controles, ordenadas. */
    public function rows(string $versionId): array
    {
        $rows = DB::select(
            'select r.id, r.row_number, r.process, r.activity, r.task, r.is_routine, r.position_id, p.name as position_name, r.hazard_catalog_id,
                    r.hazard, r.risk, r.consequence, r.exposed_workers, r.probability_value, r.probability_detail, r.severity_value, r.risk_score,
                    r.risk_level, r.is_significant, r.residual_probability, r.residual_severity, r.residual_score, r.residual_level, r.notes, r.ai_assisted
               from iperc_rows r left join positions p on p.id = r.position_id
              where r.iperc_version_id = ? and r.deleted_at is null order by r.row_number',
            [$versionId]
        );
        $controls = collect(DB::select(
            "select c.id, c.iperc_row_id, c.hierarchy, c.description, c.is_existing, c.responsible_user_id, u.full_name as responsible_name, c.due_on,
                    c.status, c.corrective_action_id
               from iperc_controls c join iperc_rows r on r.id = c.iperc_row_id left join users u on u.id = c.responsible_user_id
              where r.iperc_version_id = ? and c.deleted_at is null
              order by array_position(array['elimination','substitution','engineering','administrative','signage','ppe'], c.hierarchy), c.created_at",
            [$versionId]
        ))->groupBy('iperc_row_id');

        return array_map(fn ($r) => [
            ...(array) $r,
            'probability_detail' => json_decode((string) $r->probability_detail, true),
            'is_routine' => (bool) $r->is_routine,
            'is_significant' => (bool) $r->is_significant,
            'controls' => array_map(fn ($c) => [...(array) $c, 'is_existing' => (bool) $c->is_existing], ($controls[$r->id] ?? collect())->values()->all()),
        ], $rows);
    }

    /** Puntos que impiden enviar o aprobar (se muestran también en el editor). */
    private function issues(string $versionId, array $criteria, array $rows, array $participants): array
    {
        $out = [];
        if ($rows === []) {
            $out[] = ['row' => null, 'text' => 'La matriz no tiene filas.'];
        }
        foreach ($rows as $r) {
            $n = "Fila {$r['row_number']}";
            if (! $r['position_id']) {
                $out[] = ['row' => $r['id'], 'text' => "{$n}: indica el puesto expuesto (RN-IPC-05)."];
            }
            if ($r['is_significant']) {
                $strong = array_filter($r['controls'], fn ($c) => $c['hierarchy'] !== 'ppe' && $c['status'] !== 'cancelled');
                if ($strong === [] && trim((string) $r['notes']) === '') {
                    $out[] = ['row' => $r['id'], 'text' => "{$n}: riesgo significativo sin un control de mayor jerarquía que el EPP. Agrégalo o explica en Notas por qué no es factible (RN-IPC-03)."];
                }
            }
            $finalLevel = $r['residual_level'] ?? $r['risk_level'];
            if ($this->eval->isTop($criteria, $finalLevel)) {
                $out[] = ['row' => $r['id'], 'text' => "{$n}: el riesgo sigue siendo «{$this->eval->label($criteria, $finalLevel)}». Agrega controles y registra el riesgo residual (RN-IPC-04)."];
            }
        }
        if ($participants === []) {
            $out[] = ['row' => null, 'text' => 'Registra a los trabajadores que participaron en la elaboración (RN-IPC-07).'];
        }

        return $out;
    }

    private function heatmap(array $rows): array
    {
        $cells = ['initial' => [], 'residual' => []];
        foreach ($rows as $r) {
            $k = $r['probability_value'].'x'.$r['severity_value'];
            $cells['initial'][$k] = ($cells['initial'][$k] ?? 0) + 1;
            if ($r['residual_probability'] !== null) {
                $k = $r['residual_probability'].'x'.$r['residual_severity'];
                $cells['residual'][$k] = ($cells['residual'][$k] ?? 0) + 1;
            }
        }

        return $cells;
    }

    private function openActions(object $v): int
    {
        $controls = DB::select(
            "select c.id, c.hierarchy, c.description, c.responsible_user_id, c.due_on, r.is_significant, r.risk_level, r.id as row_id
               from iperc_controls c join iperc_rows r on r.id = c.iperc_row_id
              where r.iperc_version_id = ? and c.deleted_at is null and r.deleted_at is null and c.status = 'proposed' and c.corrective_action_id is null
                and c.responsible_user_id is not null and c.due_on is not null",
            [$v->id]
        );
        $m = DB::selectOne('select code, site_id, area_id from iperc_matrices where id = ?', [$v->iperc_matrix_id]);
        $user = (string) auth()->id();
        foreach ($controls as $c) {
            $code = 'IPC-'.Str::upper(Str::limit(preg_replace('/[^A-Za-z0-9]/', '', $m->code), 12, '')).'-'.Str::upper(Str::random(5));
            $id = DB::selectOne(
                "insert into corrective_actions (company_id, code, source_type, source_id, action_type, control_hierarchy, title, description,
                    site_id, area_id, responsible_user_id, due_on, priority, status, created_by, updated_by)
                 values (?, ?, 'iperc', ?, 'preventive', ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?, ?) returning id",
                [app(CompanyContext::class)->companyId, $code, $c->row_id, $c->hierarchy, mb_substr($c->description, 0, 200), $c->description,
                    $m->site_id, $m->area_id, $c->responsible_user_id, $c->due_on, $c->is_significant ? 'high' : 'medium', $user, $user]
            )->id;
            DB::update("update iperc_controls set corrective_action_id = ?, status = 'proposed' where id = ?", [$id, $c->id]);
            \App\Modules\REC\Rules\AcrRules::notifyAssigned($id);
        }

        return count($controls);
    }

    private function cleanParticipants(array $list): array
    {
        $out = [];
        foreach ($list as $p) {
            $name = trim((string) (is_array($p) ? ($p['name'] ?? '') : $p));
            if ($name === '') {
                continue;
            }
            $out[] = ['name' => mb_substr($name, 0, 150), 'role' => is_array($p) ? mb_substr(trim((string) ($p['role'] ?? '')), 0, 100) : ''];
        }

        return array_slice($out, 0, 100);
    }

    private function matrix(string $id): object
    {
        if (! Str::isUuid($id)) {
            throw ApiProblem::notFound('La matriz no existe.');
        }
        $m = DB::selectOne(
            'select m.*, s.name as site_name, a.name as area_name, p.name as position_name, c.name as criteria_name
               from iperc_matrices m left join sites s on s.id = m.site_id left join areas a on a.id = m.area_id
               left join positions p on p.id = m.position_id left join risk_criteria c on c.id = m.risk_criteria_id
              where m.id = ? and m.deleted_at is null', [$id]);
        if (! $m) {
            throw ApiProblem::notFound('La matriz no existe o es de otra empresa.');
        }

        return $m;
    }

    private function version(string $id, string $status, string $message): object
    {
        if (! Str::isUuid($id)) {
            throw ApiProblem::notFound('La versión no existe.');
        }
        $v = DB::selectOne('select v.*, m.risk_criteria_id from iperc_versions v join iperc_matrices m on m.id = v.iperc_matrix_id
                             where v.id = ? and v.deleted_at is null', [$id]);
        if (! $v) {
            throw ApiProblem::notFound('La versión no existe o es de otra empresa.');
        }
        if ($v->status !== $status) {
            throw new ApiProblem(409, 'conflict', 'No se puede hacer este cambio', $message);
        }

        return $v;
    }

    private function param(string $key, int $default): int
    {
        $v = DB::selectOne(
            "select value from legal_parameters where key = ? and (company_id is null or company_id = ?) and valid_from <= current_date
                and (valid_to is null or valid_to >= current_date) and deleted_at is null order by company_id nulls last, valid_from desc limit 1",
            [$key, app(CompanyContext::class)->companyId]
        );

        return (int) ($v->value ?? $default);
    }

    private function invalid(string $field, string $message): ApiProblem
    {
        return new ApiProblem(422, 'validation_failed', 'Revisa los datos', $message, ['errors' => [$field => [$message]]]);
    }

    private function audit(string $action, string $versionId, array $changes): void
    {
        $ctx = app(CompanyContext::class);
        DB::insert(
            "insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes)
             values (?, ?, ?, 'user', ?, 'IPC', 'iperc_versions', ?, ?::jsonb)",
            [$ctx->companyId, (string) auth()->id(), $ctx->roleCode, $action, $versionId, json_encode($changes, JSON_UNESCAPED_UNICODE)]
        );
    }
}
