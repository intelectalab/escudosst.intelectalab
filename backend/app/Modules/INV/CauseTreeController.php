<?php

namespace App\Modules\INV;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GET /inv/investigations/{id}/tree — datos del árbol de causas de una investigación (M-INV, SCR-INV-03): el evento
 * (raíz del árbol), las causas con su causa padre, nivel y categoría, y el equipo investigador con su rol.
 */
class CauseTreeController extends Controller
{
    public function __invoke(string $id): JsonResponse
    {
        $inv = DB::selectOne(
            'select i.id, i.code, i.method, i.status, e.code as event_code, e.event_type, e.description as event_description
               from investigations i left join sst_events e on e.id = i.event_id
              where i.id = ? and i.deleted_at is null', [$id]);
        if (! $inv) {
            throw ApiProblem::notFound('La investigación no existe o es de otra empresa.');
        }
        $causes = DB::select(
            'select id, parent_cause_id, cause_level, cause_category, description, sort_order, corrective_action_id
               from investigation_causes where investigation_id = ? and deleted_at is null
              order by sort_order nulls last, created_at', [$id]);
        $team = DB::select(
            "select t.id, coalesce(w.full_name, u.full_name, t.external_name, 'Sin nombre') as name, t.role
               from investigation_team t left join workers w on w.id = t.worker_id left join users u on u.id = t.user_id
              where t.investigation_id = ? and t.deleted_at is null
              order by array_position(array['leader','committee_representative','worker_representative','member','expert','witness'], t.role), 2", [$id]);

        return response()->json(['data' => [
            'investigation' => $inv,
            'causes' => $causes,
            'team' => $team,
        ]]);
    }
}
