<?php

namespace App\Modules\PAS;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GET /pas/schedule?year=AAAA[&program_id=] — cronograma del programa anual (SCR-PAS-01): actividades × 12 meses.
 * Cada celda programada queda «ejecutado» (hay ejecución registrada ese mes), «atrasado» (el mes ya terminó sin ejecución,
 * RN-PAS-02) o «programado»; el mes original de una reprogramación queda «reprogramado» (con el mes nuevo y el motivo); una ejecución en un mes no programado se muestra como «ejecutado fuera de lo programado».
 * Avance (RN-PAS-01): actividades-mes ejecutadas / actividades-mes programadas hasta la fecha de corte (mes actual), global
 * y por objetivo SST. Las actividades canceladas no cuentan.
 */
class ScheduleController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $now = DB::selectOne("select extract(year from now() at time zone 'America/Lima')::int as y, extract(month from now() at time zone 'America/Lima')::int as m");
        $programs = DB::select('select id, year, title, status, progress_pct from annual_programs where deleted_at is null order by year desc, created_at desc');
        $pid = $request->query('program_id');
        if ($pid && ! Str::isUuid((string) $pid)) {
            throw ApiProblem::notFound('El programa no existe.');
        }
        $year = (int) ($request->query('year') ?: $now->y);
        $program = $pid
            ? collect($programs)->firstWhere('id', $pid)
            : (collect($programs)->where('year', $year)->sortBy(fn ($p) => array_search($p->status, ['in_execution', 'approved', 'committee_review', 'draft', 'closed'], true))->first());
        if (! $program) {
            return response()->json(['data' => ['programs' => $programs, 'program' => null, 'year' => $year, 'activities' => [], 'objectives' => [], 'progress' => null, 'cutoff_month' => null, 'can_register' => false, 'can_reschedule' => false]]);
        }
        $year = (int) $program->year;
        // Mes de corte: los meses hasta aquí cuentan; un mes anterior al corte sin ejecución está atrasado.
        $cutoff = $year < $now->y ? 12 : ($year > $now->y ? 0 : (int) $now->m);

        $activities = DB::select(
            'select a.id, a.code, a.title, a.activity_type, a.status, a.planned_months, a.goal_quantity, a.executed_quantity, a.progress_pct, a.objective_id,
                    o.title as objective_title, o.code as objective_code, u.full_name as responsible_name, a.responsible_user_id
               from program_activities a left join sst_objectives o on o.id = a.objective_id left join users u on u.id = a.responsible_user_id
              where a.program_id = ? and a.deleted_at is null order by a.code nulls last, a.created_at',
            [$program->id]
        );
        $execs = [];
        foreach (DB::select(
            'select e.id, e.activity_id, e.executed_on, e.quantity, e.description, e.evidence_file_id, e.source_module, e.verified_at, f.original_name as evidence_name
               from activity_executions e join program_activities a on a.id = e.activity_id left join files f on f.id = e.evidence_file_id
              where a.program_id = ? and e.deleted_at is null order by e.executed_on', [$program->id]) as $e) {
            $execs[$e->activity_id][] = $e;
        }
        // Reprogramaciones (RN-PAS-02): el mes original queda marcado «reprogramado» con el mes nuevo y el motivo.
        $resch = [];
        foreach (DB::select(
            'select r.id, r.activity_id, r.from_month, r.to_month, r.reason, r.created_at, u.full_name as created_by_name
               from activity_reschedules r join program_activities a on a.id = r.activity_id left join users u on u.id = r.created_by
              where a.program_id = ? and r.year = ? order by r.created_at', [$program->id, $year]) as $r) {
            $resch[$r->activity_id][] = $r;
        }

        $objectives = [];
        $due = 0;
        $done = 0;
        $out = [];
        foreach ($activities as $a) {
            $planned = array_map('intval', $a->planned_months ? explode(',', trim((string) $a->planned_months, '{}')) : []);
            $list = $execs[$a->id] ?? [];
            $byMonth = [];
            foreach ($list as $e) {
                if ((int) substr($e->executed_on, 0, 4) === $year) {
                    $byMonth[(int) substr($e->executed_on, 5, 2)][] = $e;
                }
            }
            $cells = [];
            for ($m = 1; $m <= 12; $m++) {
                $isPlanned = in_array($m, $planned, true);
                $qty = array_sum(array_map(fn ($e) => (int) $e->quantity, $byMonth[$m] ?? []));
                $moved = collect($resch[$a->id] ?? [])->where('from_month', $m)->last();
                $state = match (true) {
                    $qty > 0 && $isPlanned => 'executed',
                    $qty > 0 => 'extra',
                    ! $isPlanned && $moved !== null => 'rescheduled',
                    ! $isPlanned => null,
                    $a->status === 'cancelled' => 'cancelled',
                    $m < $cutoff => 'overdue',
                    default => 'planned',
                };
                $cells[] = ['month' => $m, 'planned' => $isPlanned, 'quantity' => $qty, 'state' => $state]
                    + ($state === 'rescheduled' ? ['rescheduled_to' => (int) $moved->to_month, 'reschedule_reason' => $moved->reason] : []);
            }
            if ($a->status !== 'cancelled') {
                $key = $a->objective_id ?? 'none';
                $objectives[$key] ??= ['id' => $a->objective_id, 'title' => $a->objective_title ?? 'Sin objetivo vinculado', 'code' => $a->objective_code, 'due' => 0, 'done' => 0, 'activities' => 0];
                $objectives[$key]['activities']++;
                foreach ($cells as $c) {
                    if ($c['planned'] && $c['month'] <= $cutoff) {
                        $due++;
                        $objectives[$key]['due']++;
                        if ($c['state'] === 'executed') {
                            $done++;
                            $objectives[$key]['done']++;
                        }
                    }
                }
            }
            $out[] = [...(array) $a, 'planned_months' => $planned, 'cells' => $cells, 'executions' => $list, 'reschedules' => $resch[$a->id] ?? []];
        }
        $pct = fn (int $d, int $n) => $n > 0 ? (int) round($d * 100 / $n) : null;
        $objectives = array_values(array_map(fn ($o) => $o + ['pct' => $pct($o['done'], $o['due'])], $objectives));
        $count = fn (string $s) => array_sum(array_map(fn ($a) => count(array_filter($a['cells'], fn ($c) => $c['state'] === $s)), $out));

        return response()->json(['data' => [
            'programs' => $programs,
            'program' => $program,
            'year' => $year,
            'cutoff_month' => $cutoff,
            'activities' => $out,
            'objectives' => $objectives,
            'progress' => ['due' => $due, 'done' => $done, 'pct' => $pct($done, $due), 'overdue' => $count('overdue'), 'planned' => $count('planned'), 'executed' => $count('executed'), 'rescheduled' => $count('rescheduled')],
            'can_register' => in_array('pas.activity_executions.create', Permissions::effective(), true) && in_array($program->status, ['approved', 'in_execution'], true),
            'can_reschedule' => in_array('pas.program_activities.update', Permissions::effective(), true) && in_array($program->status, ['approved', 'in_execution'], true),
        ]]);
    }
}
