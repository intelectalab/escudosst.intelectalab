<?php

namespace App\Modules\PAS;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\REC\Rules\ProgramRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Programa Anual SST (M-PAS):
 *  GET  /pas/mine                          SCR-PAS-04: mis actividades del mes (soy el responsable) con su ejecución del mes
 *  GET  /pas/pending-validation            ejecuciones registradas por los responsables que esperan la validación del Jefe SST
 *  POST /pas/executions/{id}/validate      el Jefe SST valida una ejecución (RN-PAS-01: solo cuentan las validadas)
 *  GET  /pas/progress?program_id=          SCR-PAS-05: % programado vs. ejecutado por mes y por responsable (parcial con su peso)
 *  POST /pas/programs/{id}/new-version     RN-PAS-03: nueva versión en borrador con las mismas actividades, para agregar o quitar
 */
class ProgramToolsController extends Controller
{
    public function mine(): JsonResponse
    {
        $this->can('pas.activity_executions.create', 'Tu rol no registra ejecuciones del programa.');
        $now = now('America/Lima');
        $m = (int) $now->format('n');
        $rows = DB::select(
            "select a.id, a.code, a.title, a.activity_type, a.planned_months, a.goal_quantity, a.executed_quantity, a.progress_pct, p.title as program, p.year,
                    (select json_agg(json_build_object('id', x.id, 'executed_on', x.executed_on, 'status', x.status, 'validated', x.verified_at is not null) order by x.executed_on)
                       from activity_executions x where x.activity_id = a.id and x.deleted_at is null and coalesce(x.planned_month, extract(month from x.executed_on)::int) = ?) as executions
               from program_activities a join annual_programs p on p.id = a.program_id and p.deleted_at is null and p.status in ('approved', 'in_execution') and p.year = ?
              where a.responsible_user_id = ? and a.deleted_at is null and a.status <> 'cancelled' and ? = any(a.planned_months)
              order by a.code nulls last, a.title", [$m, (int) $now->format('Y'), (string) auth()->id(), $m]);
        foreach ($rows as $r) {
            $r->executions = json_decode((string) $r->executions, true) ?: [];
            $r->planned_months = array_map('intval', str_getcsv(trim((string) $r->planned_months, '{}')) ?: []);
        }

        return response()->json(['data' => ['month' => $m, 'year' => (int) $now->format('Y'), 'activities' => $rows]]);
    }

    public function pending(): JsonResponse
    {
        $this->can('pas.activity_executions.verify', 'Tu rol no valida ejecuciones del programa.');

        return response()->json(['data' => DB::select(
            "select x.id, x.executed_on, x.status, x.description, x.evidence_file_id, x.source_module, a.code, a.title as activity, u.full_name as recorded_by
               from activity_executions x join program_activities a on a.id = x.activity_id left join users u on u.id = x.recorded_by
              where x.deleted_at is null and x.verified_at is null order by x.executed_on")]);
    }

    public function validateExecution(string $id): JsonResponse
    {
        $this->can('pas.activity_executions.verify', 'La ejecución la valida el Jefe SST.');
        $x = Str::isUuid($id) ? DB::selectOne('select id, activity_id, verified_at from activity_executions where id = ? and deleted_at is null', [$id]) : null;
        if (! $x) {
            throw ApiProblem::notFound('La ejecución no existe.');
        }
        if ($x->verified_at) {
            throw new ApiProblem(409, 'conflict', 'Ya validada', 'Esta ejecución ya fue validada.');
        }
        DB::update('update activity_executions set verified_by = ?, verified_at = now(), updated_at = now(), updated_by = ? where id = ?', [(string) auth()->id(), (string) auth()->id(), $id]);
        $ctx = app(CompanyContext::class);
        DB::insert("insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes) values (?, ?, ?, 'user', 'update', 'PAS', 'activity_executions', ?, ?::jsonb)",
            [$ctx->companyId, (string) auth()->id(), $ctx->roleCode, $id, json_encode(['verified_at' => ['from' => null, 'to' => 'validada']])]);
        (new ProgramRules)->recalcActivity($x->activity_id);

        return response()->json(['data' => ['id' => $id, 'validated' => true]]);
    }

    public function progress(Request $request): JsonResponse
    {
        $this->can('pas.annual_programs.read', 'Tu rol no ve el programa anual.');
        $id = (string) $request->query('program_id');
        $p = Str::isUuid($id) ? DB::selectOne('select id, year, title from annual_programs where id = ? and deleted_at is null', [$id])
            : DB::selectOne("select id, year, title from annual_programs where deleted_at is null order by (status in ('approved', 'in_execution')) desc, year desc, version_number desc limit 1");
        if (! $p) {
            return response()->json(['data' => null]);
        }
        $w = (float) LegalParams::number('partial_execution_weight', 0.5);
        $now = now('America/Lima');
        $cut = $p->year < (int) $now->format('Y') ? 12 : ($p->year > (int) $now->format('Y') ? 0 : (int) $now->format('n'));
        // Una celda = actividad × mes programado; vale 1 si tiene una ejecución validada ese mes (el peso de parcial si solo es parcial).
        $cells = DB::select(
            "select a.id, m.month, coalesce(u.full_name, 'Sin responsable') as responsible,
                    coalesce(max(case when x.status = 'partial' then ?::numeric else 1 end) filter (where x.id is not null), 0) as value
               from program_activities a cross join lateral unnest(a.planned_months) as m(month)
               left join users u on u.id = a.responsible_user_id
               left join activity_executions x on x.activity_id = a.id and x.deleted_at is null and x.verified_at is not null
                    and coalesce(x.planned_month, extract(month from x.executed_on)::int) = m.month
              where a.program_id = ? and a.deleted_at is null and a.status <> 'cancelled'
              group by a.id, m.month, u.full_name", [$w, $p->id]);
        $byMonth = [];
        for ($i = 1; $i <= 12; $i++) {
            $c = array_filter($cells, fn ($x) => (int) $x->month === $i);
            $planned = count($c);
            $done = round(array_sum(array_map(fn ($x) => (float) $x->value, $c)), 2);
            $byMonth[] = ['month' => $i, 'planned' => $planned, 'executed' => $done, 'pct' => $planned ? (int) round($done * 100 / $planned) : null];
        }
        $byResp = [];
        foreach ($cells as $c) {
            if ((int) $c->month > $cut) {
                continue;
            }
            $byResp[$c->responsible] ??= ['responsible' => $c->responsible, 'planned' => 0, 'executed' => 0.0];
            $byResp[$c->responsible]['planned']++;
            $byResp[$c->responsible]['executed'] += (float) $c->value;
        }
        $byResp = array_map(fn ($r) => $r + ['pct' => $r['planned'] ? (int) round($r['executed'] * 100 / $r['planned']) : null], array_values($byResp));
        $due = array_filter($cells, fn ($x) => (int) $x->month <= $cut);
        $plannedDue = count($due);
        $doneDue = array_sum(array_map(fn ($x) => (float) $x->value, $due));

        return response()->json(['data' => ['program' => $p, 'cut_month' => $cut, 'partial_weight' => $w,
            'total' => ['planned' => $plannedDue, 'executed' => round($doneDue, 2), 'pct' => $plannedDue ? (int) round($doneDue * 100 / $plannedDue) : null],
            'by_month' => $byMonth, 'by_responsible' => $byResp]]);
    }

    public function newVersion(string $id): JsonResponse
    {
        $this->can('pas.annual_programs.create', 'Tu rol no crea versiones del programa.');
        $p = Str::isUuid($id) ? DB::selectOne('select * from annual_programs where id = ? and deleted_at is null', [$id]) : null;
        if (! $p) {
            throw ApiProblem::notFound('El programa no existe.');
        }
        if (! in_array($p->status, ['approved', 'in_execution'], true)) {
            throw new ApiProblem(409, 'conflict', 'Programa no aprobado', 'Mientras está en borrador, el programa se cambia directamente.');
        }
        if (DB::selectOne("select 1 as ok from annual_programs where previous_program_id = ? and deleted_at is null and status in ('draft', 'committee_review')", [$p->id])) {
            throw new ApiProblem(409, 'conflict', 'Ya hay una versión en trámite', 'Ya existe una nueva versión en borrador de este programa.');
        }
        $uid = (string) auth()->id();

        return DB::transaction(function () use ($p, $uid) {
            $v = (int) DB::selectOne('select coalesce(max(version_number), 0) + 1 as n from annual_programs where year = ? and deleted_at is null', [$p->year])->n;
            $new = DB::selectOne(
                "insert into annual_programs (company_id, year, annual_plan_id, title, status, version_number, previous_program_id, progress_pct, created_by, updated_by)
                 values (?, ?, ?, ?, 'draft', ?, ?, 0, ?, ?) returning id", [$p->company_id, $p->year, $p->annual_plan_id, $p->title, $v, $p->id, $uid, $uid])->id;
            DB::insert(
                "insert into program_activities (company_id, program_id, annual_plan_item_id, objective_id, code, title, description, activity_type, linked_module, responsible_user_id,
                        planned_months, starts_on, ends_on, frequency, goal_quantity, executed_quantity, budget_line_id, status, progress_pct, previous_activity_id, created_by, updated_by)
                 select company_id, ?, annual_plan_item_id, objective_id, code, title, description, activity_type, linked_module, responsible_user_id,
                        planned_months, starts_on, ends_on, frequency, goal_quantity, 0, budget_line_id, 'planned', 0, id, ?, ?
                   from program_activities where program_id = ? and deleted_at is null and status <> 'cancelled'", [$new, $uid, $uid, $p->id]);

            return response()->json(['data' => ['id' => $new, 'version_number' => $v]], 201);
        });
    }

    private function can(string $perm, string $msg): void
    {
        if (! in_array($perm, Permissions::effective(), true)) {
            throw ApiProblem::forbidden($msg);
        }
    }
}
