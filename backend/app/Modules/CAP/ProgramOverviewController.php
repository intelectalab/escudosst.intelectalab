<?php

namespace App\Modules\CAP;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /pca/overview?program_id= — M-PCA SCR-PCA-01/03: vista anual del programa de capacitación (temas × meses con su estado:
 * ejecutado si su capacitación tiene una sesión realizada (RN-PCA-04), programado, pendiente o no ejecutado si su mes ya cerró
 * sin capacitación (RN-PCA-08)), el cumplimiento de cada tema (RN-PCA-03: trabajadores objetivo con asistencia aprobada /
 * trabajadores objetivo activos) y la matriz puesto × tema con el número de trabajadores.
 */
class ProgramOverviewController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! in_array('pca.training_programs.read', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no ve el programa de capacitación.');
        }
        $programs = DB::select("select id, year, title, status from training_programs where deleted_at is null order by year desc, created_at desc limit 20");
        $id = $request->query('program_id');
        $p = $id ? collect($programs)->firstWhere('id', $id) : (collect($programs)->first(fn ($x) => in_array($x->status, ['approved', 'in_execution'], true)) ?? ($programs[0] ?? null));
        if (! $p) {
            return response()->json(['data' => ['programs' => [], 'program' => null, 'items' => [], 'positions' => []]]);
        }
        $now = now('America/Lima');
        $items = DB::select(
            "select i.id, i.topic, i.training_type, i.is_mandatory_legal, i.target_audience, i.position_id, i.planned_month, i.original_month, i.reschedule_reason, i.status,
                    i.training_id, t.title as training, ps.name as position,
                    exists (select 1 from training_sessions s where s.training_id = i.training_id and s.status = 'held' and s.deleted_at is null) as held
               from training_program_items i left join trainings t on t.id = i.training_id left join positions ps on ps.id = i.position_id
              where i.training_program_id = ? and i.deleted_at is null and i.status <> 'cancelled' order by i.planned_month nulls last, i.topic", [$p->id]);
        foreach ($items as $it) {
            $closed = $it->planned_month && ($p->year < (int) $now->format('Y') || ($p->year == (int) $now->format('Y') && $it->planned_month < (int) $now->format('n')));
            $it->state = $it->held || $it->status === 'done' ? 'executed' : ($it->training_id ? ($closed ? 'not_executed' : 'scheduled') : ($closed ? 'not_executed' : 'pending'));
            // RN-PCA-03: objetivo = trabajadores activos del puesto (o toda la empresa si el tema no tiene puesto).
            $target = $it->position_id
                ? (int) DB::selectOne("select count(*) as n from workers where current_position_id = ? and status = 'active' and deleted_at is null", [$it->position_id])->n
                : (int) DB::selectOne("select count(*) as n from workers where status = 'active' and deleted_at is null")->n;
            $done = $it->training_id ? (int) DB::selectOne(
                "select count(distinct a.worker_id) as n from training_attendances a join training_sessions s on s.id = a.session_id and s.status = 'held' and s.deleted_at is null
                   join workers w on w.id = a.worker_id and w.status = 'active' and w.deleted_at is null
                  where s.training_id = ? and a.attended and a.deleted_at is null".($it->position_id ? ' and w.current_position_id = ?' : ''),
                $it->position_id ? [$it->training_id, $it->position_id] : [$it->training_id])->n : 0;
            $it->target = $target;
            $it->done = $done;
            $it->pct = $target ? (int) round($done * 100 / $target) : null;
            $it->held = (bool) $it->held;
        }
        $den = array_sum(array_column($items, 'target'));
        $num = array_sum(array_column($items, 'done'));
        $positions = DB::select(
            "select ps.id, ps.name, (select count(*) from workers w where w.current_position_id = ps.id and w.status = 'active' and w.deleted_at is null) as workers
               from positions ps where ps.id in (select position_id from training_program_items where training_program_id = ? and deleted_at is null and position_id is not null)
              order by ps.name", [$p->id]);

        return response()->json(['data' => [
            'programs' => $programs,
            'program' => (array) $p + ['pct' => $den ? (int) round($num * 100 / $den) : null],
            'items' => $items,
            'positions' => $positions,
        ]]);
    }
}
