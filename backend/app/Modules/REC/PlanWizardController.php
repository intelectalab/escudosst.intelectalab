<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Plan Anual SST (M-PLA):
 *  GET  /pla/wizard?year=               SCR-PLA-02: datos para armar el plan (línea base aprobada, política vigente y sus objetivos
 *                                       del año, PASST y programa de capacitación del año, presupuesto) y los planes del año
 *  POST /pla/wizard                     crea el plan en borrador ligado a esos registros, con un componente por objetivo elegido, y
 *                                       vincula el PASST al plan (RN-PLA-02/05/07)
 *  POST /pla/plans/{id}/new-version     RN-PLA-01: nueva versión en borrador a partir de un plan aprobado o vigente (copia sus componentes)
 *  GET  /pla/plans/{id}/indicators      indicadores del plan: componentes, objetivos logrados, avance del PASST y del programa de capacitación
 */
class PlanWizardController extends Controller
{
    public function prefill(Request $request): JsonResponse
    {
        $this->can('pla.annual_plans.read');
        $year = (int) $request->query('year', now('America/Lima')->format('Y'));

        return response()->json(['data' => [
            'year' => $year,
            'baselines' => DB::select("select id, title, assessment_on, score_pct from baseline_assessments where status = 'approved' and deleted_at is null order by assessment_on desc limit 10"),
            'policy' => DB::selectOne("select id, title, version_number from sst_policies where status in ('current', 'review_required') and deleted_at is null order by version_number desc limit 1"),
            'objectives' => DB::select(
                "select o.id, o.code, o.title, o.indicator, o.target_value, o.unit, u.full_name as responsible
                   from sst_objectives o left join users u on u.id = o.responsible_user_id where o.year = ? and o.status <> 'cancelled' and o.deleted_at is null order by o.code", [$year]),
            'programs' => DB::select("select id, title, status, annual_plan_id from annual_programs where year = ? and deleted_at is null and status <> 'closed' order by created_at desc", [$year]),
            'training_programs' => DB::select("select id, title, status from training_programs where year = ? and deleted_at is null and status <> 'closed' order by created_at desc", [$year]),
            'budgets' => DB::select("select id, title, total_planned, currency from budgets where deleted_at is null and (year = ? or year is null) order by created_at desc limit 10", [$year]),
            'plans' => DB::select('select id, title, version_number, status, approved_at from annual_plans where year = ? and deleted_at is null order by version_number desc', [$year]),
        ]]);
    }

    public function create(Request $request, RecordsService $records): JsonResponse
    {
        $this->can('pla.annual_plans.create');
        $d = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'title' => ['required', 'string', 'max:300'],
            'baseline_assessment_id' => ['nullable', 'uuid'], 'policy_id' => ['nullable', 'uuid'], 'annual_program_id' => ['nullable', 'uuid'],
            'training_program_id' => ['nullable', 'uuid'], 'budget_id' => ['nullable', 'uuid'],
            'objectives_summary' => ['nullable', 'string', 'max:5000'], 'resources_text' => ['nullable', 'string', 'max:5000'],
            'objective_ids' => ['array', 'max:100'], 'objective_ids.*' => ['uuid'],
        ], ['title.required' => 'Escribe el título del plan.']);
        $plan = DB::transaction(function () use ($records, $d) {
            $plan = $records->create(Registry::resource('PLA', 'annual_plans'), array_filter([
                'year' => $d['year'], 'title' => $d['title'], 'baseline_assessment_id' => $d['baseline_assessment_id'] ?? null, 'policy_id' => $d['policy_id'] ?? null,
                'training_program_id' => $d['training_program_id'] ?? null, 'budget_id' => $d['budget_id'] ?? null,
                'objectives_summary' => $d['objectives_summary'] ?? null, 'resources_text' => $d['resources_text'] ?? null,
            ], fn ($x) => $x !== null));
            $items = Registry::resource('PLA', 'annual_plan_items');
            $n = 0;
            foreach (DB::select("select id, code, title, indicator, target_value, unit, responsible_user_id from sst_objectives where id = any(?::uuid[]) and year = ? and deleted_at is null order by code",
                ['{'.implode(',', $d['objective_ids'] ?? []).'}', $d['year']]) as $o) {
                $records->create($items, array_filter(['annual_plan_id' => $plan['id'], 'item_type' => 'objective', 'objective_id' => $o->id,
                    'reference_type' => 'sst_objectives', 'reference_id' => $o->id, 'code' => $o->code ?: sprintf('C%02d', $n + 1),
                    'description' => $o->title, 'goal' => $o->target_value !== null ? trim($o->target_value.' '.($o->unit ?? '')) : null, 'indicator' => $o->indicator,
                    'responsible_user_id' => $o->responsible_user_id, 'sort_order' => ++$n], fn ($x) => $x !== null && $x !== ''));
            }
            if (! empty($d['annual_program_id'])) {
                DB::update('update annual_programs set annual_plan_id = ? where id = ? and year = ?', [$plan['id'], $d['annual_program_id'], $d['year']]);
            }
            // M-PLA §5 y flujo 2: los programas vinculados (PASST y capacitación) quedan como componentes de tipo programa.
            foreach ([['annual_programs', $d['annual_program_id'] ?? null], ['training_programs', $d['training_program_id'] ?? null]] as [$table, $pid]) {
                $prog = $pid ? DB::selectOne("select id, title from {$table} where id = ? and year = ? and deleted_at is null", [$pid, $d['year']]) : null;
                if ($prog) {
                    $records->create($items, ['annual_plan_id' => $plan['id'], 'item_type' => 'program', 'reference_type' => $table, 'reference_id' => $prog->id,
                        'code' => sprintf('P%02d', ++$n), 'description' => $prog->title, 'sort_order' => $n]);
                }
            }

            return $plan;
        });

        return response()->json(['data' => ['id' => $plan['id']]], 201);
    }

    public function newVersion(string $id, RecordsService $records): JsonResponse
    {
        $this->can('pla.annual_plans.create');
        $p = $this->plan($id);
        if (! in_array($p->status, ['approved', 'current'], true)) {
            throw new ApiProblem(409, 'conflict', 'No corresponde', 'La nueva versión se crea a partir de un plan aprobado o vigente.');
        }
        if (DB::selectOne("select 1 as ok from annual_plans where year = ? and status in ('draft', 'in_review') and deleted_at is null", [$p->year])) {
            throw new ApiProblem(409, 'conflict', 'Ya hay una versión en trámite', 'Ya hay una versión de ese año en borrador o en revisión.');
        }
        $new = DB::transaction(function () use ($records, $p) {
            $new = $records->create(Registry::resource('PLA', 'annual_plans'), array_filter([
                'year' => $p->year, 'title' => $p->title, 'baseline_assessment_id' => $p->baseline_assessment_id, 'policy_id' => $p->policy_id, 'training_program_id' => $p->training_program_id,
                'budget_id' => $p->budget_id, 'objectives_summary' => $p->objectives_summary, 'resources_text' => $p->resources_text,
            ], fn ($x) => $x !== null));
            DB::update('update annual_plans set previous_plan_id = ? where id = ?', [$p->id, $new['id']]);
            $items = Registry::resource('PLA', 'annual_plan_items');
            foreach (DB::select('select * from annual_plan_items where annual_plan_id = ? and deleted_at is null order by sort_order', [$p->id]) as $i) {
                $ref = $i->reference_type === 'annual_programs' && $i->reference_id ? self::latestProgram($i->reference_id) : $i->reference_id;
                $records->create($items, array_filter(['annual_plan_id' => $new['id'], 'item_type' => $i->item_type, 'objective_id' => $i->objective_id,
                    'reference_type' => $i->reference_type, 'reference_id' => $ref, 'code' => $i->code, 'description' => $i->description,
                    'goal' => $i->goal, 'indicator' => $i->indicator, 'resources' => $i->resources, 'responsible_user_id' => $i->responsible_user_id, 'starts_on' => $i->starts_on,
                    'ends_on' => $i->ends_on, 'sort_order' => $i->sort_order], fn ($x) => $x !== null && $x !== ''));
            }

            return $new;
        });

        return response()->json(['data' => ['id' => $new['id']]], 201);
    }

    public function indicators(string $id): JsonResponse
    {
        $this->can('pla.annual_plans.read');
        $p = $this->plan($id);
        $obj = DB::selectOne(
            "select count(distinct o.id) as total, count(distinct o.id) filter (where o.status = 'achieved') as achieved
               from annual_plan_items i join sst_objectives o on o.id = i.objective_id where i.annual_plan_id = ? and i.deleted_at is null", [$id]);
        $pas = DB::selectOne('select title, progress_pct, status from annual_programs where annual_plan_id = ? and deleted_at is null order by created_at desc limit 1', [$id])
            ?? DB::selectOne("select title, progress_pct, status from annual_programs where year = ? and deleted_at is null and status <> 'closed' order by created_at desc limit 1", [$p->year]);

        return response()->json(['data' => [
            'items' => (int) DB::selectOne('select count(*) as n from annual_plan_items where annual_plan_id = ? and deleted_at is null', [$id])->n,
            'objectives' => ['total' => (int) $obj->total, 'achieved' => (int) $obj->achieved],
            'passt' => $pas ? ['title' => $pas->title, 'progress_pct' => $pas->progress_pct === null ? null : (float) $pas->progress_pct, 'status' => $pas->status] : null,
            'training_program' => $p->training_program_id ? DB::selectOne('select title, status from training_programs where id = ?', [$p->training_program_id]) : null,
            'version_number' => (int) $p->version_number,
        ]]);
    }

    /**
     * RN-PLA-07: registros que puede vincular un componente del plan, del mismo año y sin versiones obsoletas
     * (objetivos no anulados; programas no cerrados ni reemplazados por una versión nueva). Sin «type», los tipos posibles.
     */
    public function referenceOptions(Request $request, string $id): JsonResponse
    {
        $this->can('pla.annual_plans.read');
        $p = $this->plan($id);
        $types = ['sst_objectives' => 'Objetivo SST', 'annual_programs' => 'Programa Anual SST (PASST)', 'training_programs' => 'Programa de capacitación'];
        $type = (string) $request->query('type', '');
        if ($type === '') {
            return response()->json(['data' => collect($types)->map(fn ($label, $t) => ['type' => $t, 'label' => $label])->values()]);
        }
        $rows = match ($type) {
            'sst_objectives' => DB::select("select id, concat_ws(' · ', code, title) as label from sst_objectives where year = ? and status <> 'cancelled' and deleted_at is null order by code", [$p->year]),
            'annual_programs' => DB::select("select a.id, concat(a.title, ' · versión ', a.version_number) as label from annual_programs a where a.year = ? and a.status <> 'closed' and a.deleted_at is null
                                              and not exists (select 1 from annual_programs n where n.previous_program_id = a.id and n.deleted_at is null) order by a.created_at desc", [$p->year]),
            'training_programs' => DB::select("select id, title as label from training_programs where year = ? and status <> 'closed' and deleted_at is null order by created_at desc", [$p->year]),
            default => throw ApiProblem::notFound('Ese tipo de registro no se vincula al plan.'),
        };

        return response()->json(['data' => $rows]);
    }

    /** M-PLA §11 y criterio 4: Plan Anual en PDF (política, objetivos, programas vinculados y acta). */
    public function pdf(string $id): \Illuminate\Http\Response
    {
        $this->can('pla.annual_plans.read');
        $this->plan($id);
        [$name, $bytes] = AnnualPlanDocs::pdf($id);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'"']);
    }

    /** RN-PLA-07: una nueva versión del plan apunta a la versión vigente del PASST (si se rehízo, la más reciente). */
    public static function latestProgram(string $id): string
    {
        for ($i = 0; $i < 20; $i++) {
            $next = DB::selectOne('select id from annual_programs where previous_program_id = ? and deleted_at is null order by created_at desc limit 1', [$id])?->id;
            if (! $next) {
                return $id;
            }
            $id = $next;
        }

        return $id;
    }

    private function plan(string $id): object
    {
        return (Str::isUuid($id) ? DB::selectOne('select * from annual_plans where id = ? and deleted_at is null', [$id]) : null) ?? throw ApiProblem::notFound('El plan no existe.');
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede hacer esto con el Plan Anual.');
        }
    }
}
