<?php

namespace App\Modules\REC;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Informes imprimibles de planificación (§11 de las fichas):
 *  GET /reports/sst_policies/{id}         M-POL: política con aprobación y firmas, y la matriz de objetivos y metas con su avance
 *  GET /reports/annual_plans/{id}         M-PLA: Plan Anual con objetivos, actividades y sesión de aprobación del comité
 *  GET /reports/annual_programs/{id}      M-PAS: Programa Anual con cronograma por mes y avance ejecutado vs. programado
 *  GET /reports/baseline_assessments/{id} M-LBA: línea base por lineamiento, brechas y plan de cierre, con firmas
 *  GET /reports/legal_matrix              M-MRL: matriz de requisitos legales con su último resultado
 */
class PlanningReportsController extends FieldReportsController
{
    public function policy(string $id): JsonResponse
    {
        $this->can('pol.sst_policies.read');
        $p = $this->row('sst_policies', $id, 'La política no existe o es de otra empresa.');
        $objectives = DB::select(
            "select o.id, o.code, o.title, o.indicator, o.baseline_value, o.target_value, o.unit, o.status, u.full_name as responsible
               from sst_objectives o left join users u on u.id = o.responsible_user_id
              where o.deleted_at is null and (o.policy_id = ? or (o.policy_id is null and o.year = extract(year from now()))) order by o.code", [$id]);
        foreach ($objectives as $o) {
            $o->targets = DB::select('select period_label, target_on, target_value, actual_value, achievement_pct, status from objective_targets where objective_id = ? and deleted_at is null order by target_on', [$o->id]);
            unset($o->id);
        }

        return response()->json(['data' => [
            'company' => $this->company(),
            'policy' => ['title' => $p->title, 'version_number' => $p->version_number, 'status' => $p->status, 'content_html' => $p->content_html, 'effective_on' => $p->effective_on,
                'review_due_on' => $p->review_due_on, 'approved_at' => $p->approved_at, 'approved_by' => $this->person($p->approved_by, null), 'prepared_by' => $this->person($p->prepared_by, null)],
            'objectives' => $objectives,
            'signatures' => $this->signatures('sst_policies', $id),
        ]]);
    }

    public function plan(string $id): JsonResponse
    {
        $this->can('pla.annual_plans.read');
        $p = $this->row('annual_plans', $id, 'El plan no existe o es de otra empresa.');
        $items = DB::select(
            "select i.code, i.description, i.goal, i.indicator, i.resources, i.starts_on, i.ends_on, u.full_name as responsible, o.code as objective_code, o.title as objective
               from annual_plan_items i left join users u on u.id = i.responsible_user_id left join sst_objectives o on o.id = i.objective_id
              where i.annual_plan_id = ? and i.deleted_at is null order by o.code nulls last, i.sort_order, i.code", [$id]);
        $meeting = $p->committee_meeting_id ? DB::selectOne('select number, meeting_type, held_at, scheduled_at from committee_meetings where id = ?', [$p->committee_meeting_id]) : null;

        return response()->json(['data' => [
            'company' => $this->company(),
            'plan' => ['year' => $p->year, 'title' => $p->title, 'status' => $p->status, 'objectives_summary' => $p->objectives_summary, 'resources_text' => $p->resources_text,
                'approved_at' => $p->approved_at, 'approved_by' => $this->person($p->approved_by, null),
                'budget' => $p->budget_id ? DB::selectOne('select title, total_planned as total_amount, currency from budgets where id = ?', [$p->budget_id]) : null, 'meeting' => $meeting],
            'items' => $items,
            'signatures' => $this->signatures('annual_plans', $id),
        ]]);
    }

    public function program(string $id): JsonResponse
    {
        $this->can('pas.annual_programs.read');
        $p = $this->row('annual_programs', $id, 'El programa no existe o es de otra empresa.');
        $acts = DB::select(
            "select a.code, a.title, a.activity_type, a.planned_months, a.goal_quantity, a.executed_quantity, a.status, a.progress_pct, u.full_name as responsible,
                    (select array_agg(distinct extract(month from x.executed_on)::int) from activity_executions x where x.activity_id = a.id and x.deleted_at is null) as executed_months
               from program_activities a left join users u on u.id = a.responsible_user_id
              where a.program_id = ? and a.deleted_at is null and a.status <> 'cancelled' order by a.code", [$id]);
        foreach ($acts as $a) {
            $a->planned_months = array_map('intval', str_getcsv(trim((string) $a->planned_months, '{}')) ?: []);
            $a->executed_months = $a->executed_months ? array_map('intval', str_getcsv(trim((string) $a->executed_months, '{}'))) : [];
            $a->progress_pct = $a->progress_pct === null ? null : (float) $a->progress_pct;
        }
        $planned = array_sum(array_map(fn ($a) => count(array_filter($a->planned_months)), $acts));
        $done = array_sum(array_map(fn ($a) => count(array_intersect(array_filter($a->planned_months), $a->executed_months)), $acts));
        $meeting = $p->committee_meeting_id ? DB::selectOne('select number, meeting_type, held_at, scheduled_at from committee_meetings where id = ?', [$p->committee_meeting_id]) : null;

        return response()->json(['data' => [
            'company' => $this->company(),
            'program' => ['year' => $p->year, 'title' => $p->title, 'status' => $p->status, 'progress_pct' => $p->progress_pct === null ? null : (float) $p->progress_pct,
                'approved_at' => $p->approved_at, 'approved_by' => $this->person($p->approved_by, null), 'meeting' => $meeting],
            'activities' => $acts,
            'totals' => ['planned' => $planned, 'executed' => $done, 'pct' => $planned ? (int) round($done * 100 / $planned) : null],
            'signatures' => $this->signatures('annual_programs', $id),
        ]]);
    }

    public function baseline(string $id): JsonResponse
    {
        $this->can('lba.baseline_assessments.read');
        $b = $this->row('baseline_assessments', $id, 'El diagnóstico no existe o es de otra empresa.');
        $lineaments = DB::select(
            "select lineament_code, lineament_name, count(*) as total, count(*) filter (where result = 'compliant') as compliant,
                    count(*) filter (where result = 'partial') as partial, count(*) filter (where result = 'non_compliant') as non_compliant
               from baseline_items where assessment_id = ? and deleted_at is null group by lineament_code, lineament_name order by lineament_code", [$id]);
        $gaps = DB::select(
            "select g.description, g.priority, g.due_on, g.status, u.full_name as responsible, c.code as action_code
               from baseline_gaps g left join users u on u.id = g.responsible_user_id left join corrective_actions c on c.id = g.corrective_action_id
              where g.assessment_id = ? and g.deleted_at is null order by g.due_on nulls last", [$id]);

        return response()->json(['data' => [
            'company' => $this->company(),
            'assessment' => ['title' => $b->title, 'reference_norm' => $b->reference_norm, 'assessment_on' => $b->assessment_on, 'status' => $b->status, 'is_initial' => (bool) $b->is_initial,
                'score_pct' => $b->score_pct === null ? null : (float) $b->score_pct, 'total_items' => (int) $b->total_items, 'compliant_items' => (int) $b->compliant_items,
                'evaluator' => $b->evaluator_name ?: $this->person($b->evaluator_user_id, null), 'site' => $this->name('sites', $b->site_id),
                'approved_at' => $b->approved_at, 'approved_by' => $this->person($b->approved_by, null)],
            'lineaments' => $lineaments,
            'gaps' => $gaps,
            'signatures' => $this->signatures('baseline_assessments', $id),
        ]]);
    }

    public function legalMatrix(): JsonResponse
    {
        $this->can('mrl.legal_requirements.read');
        $rows = DB::select(
            "select n.norm_type, n.number, n.title as norm, r.article, r.requirement, r.applies, r.frequency, r.next_evaluation_on, r.status, u.full_name as responsible,
                    s.name as site, e.result as last_result, e.evaluated_on as last_evaluated_on
               from legal_requirements r join legal_norms n on n.id = r.legal_norm_id
               left join users u on u.id = r.responsible_user_id left join sites s on s.id = r.site_id
               left join lateral (select result, evaluated_on from requirement_evaluations x where x.legal_requirement_id = r.id and x.deleted_at is null
                                    order by x.evaluated_on desc limit 1) e on true
              where r.deleted_at is null and r.status <> 'repealed' order by n.norm_type, n.number, r.article");
        $count = array_count_values(array_map(fn ($r) => $r->last_result ?? 'not_evaluated', $rows));

        return response()->json(['data' => ['company' => $this->company(), 'rows' => $rows, 'counts' => $count, 'generated_at' => now('America/Lima')->toIso8601String()]]);
    }
}
