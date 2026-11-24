<?php

namespace App\Modules\INV;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\REC\RecordsService;
use App\Modules\REC\Registry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GET /inv/investigations/{id}/tree — datos del árbol de causas de una investigación (M-INV, SCR-INV-03): el evento
 * (raíz del árbol), las causas con su causa padre, nivel, categoría y acción correctiva, el equipo investigador con su rol
 * y si el usuario puede editar el árbol (permisos y estado). Las ediciones: CauseTreeEditController.
 */
class CauseTreeController extends Controller
{
    public function __invoke(string $id, RecordsService $records): JsonResponse
    {
        $inv = DB::selectOne(
            'select i.id, i.code, i.method, i.status, e.code as event_code, e.event_type, e.description as event_description
               from investigations i left join sst_events e on e.id = i.event_id
              where i.id = ? and i.deleted_at is null', [$id]);
        if (! $inv) {
            throw ApiProblem::notFound('La investigación no existe o es de otra empresa.');
        }
        $causes = DB::select(
            'select c.id, c.parent_cause_id, c.cause_level, c.cause_category, c.description, c.sort_order, c.corrective_action_id,
                    a.code as action_code, a.status as action_status
               from investigation_causes c left join corrective_actions a on a.id = c.corrective_action_id and a.deleted_at is null
              where c.investigation_id = ? and c.deleted_at is null
              order by c.sort_order nulls last, c.created_at', [$id]);
        $team = DB::select(
            "select t.id, coalesce(w.full_name, u.full_name, t.external_name, 'Sin nombre') as name, t.role
               from investigation_team t left join workers w on w.id = t.worker_id left join users u on u.id = t.user_id
              where t.investigation_id = ? and t.deleted_at is null
              order by array_position(array['leader','committee_representative','worker_representative','member','expert','witness'], t.role), 2", [$id]);

        return response()->json(['data' => [
            'investigation' => $inv,
            'causes' => $causes,
            'team' => $team,
            // Lo que el usuario puede hacer en el editor visual: según sus permisos y, RN-INV-05, solo antes de aprobar el informe.
            'locked' => in_array($inv->status, CauseTreeEditController::LOCKED, true),
            'can' => array_intersect_key($records->abilities(Registry::resource('INV', 'investigation_causes')), array_flip(['create', 'update', 'delete']))
                + ['action' => in_array('acr.corrective_actions.create', Permissions::effective(), true)],
        ]]);
    }
}
