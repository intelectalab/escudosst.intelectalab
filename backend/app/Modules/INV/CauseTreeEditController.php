<?php

namespace App\Modules\INV;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\REC\RecordsService;
use App\Modules\REC\Registry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Editor visual del árbol de causas (M-INV, SCR-INV-03):
 *  POST   /inv/investigations/{id}/tree/causes                 agrega una causa bajo el evento (sin padre) o bajo otra causa
 *                                                             {parent_cause_id?, cause_level, cause_category?, description}
 *  PATCH  /inv/investigations/{id}/tree/causes/{cause}         cambia descripción, nivel y categoría
 *  POST   /inv/investigations/{id}/tree/causes/{cause}/move    la cuelga de otra causa o del evento y la ubica entre sus
 *                                                             hermanas {parent_cause_id|null, position?} (sin ciclos)
 *  DELETE /inv/investigations/{id}/tree/causes/{cause}         la quita (borrado lógico): sus causas hijas suben a la causa
 *                                                             superior de la quitada y su acción correctiva se conserva en ACR
 * Todo se escribe con el motor de registros: mismos permisos (inv.investigation_causes.*), reglas (EventRules: informe
 * aprobado o cerrado inalterable, RN-INV-05; causa superior de la misma investigación y sin ciclos) y bitácora que la
 * lista «Causas» de la ficha.
 */
class CauseTreeEditController extends Controller
{
    /** RN-INV-05: con el informe aprobado (y después) el árbol no cambia; los hallazgos nuevos van como adenda. */
    public const LOCKED = ['approved', 'actions_in_progress', 'closed'];

    public function __construct(private RecordsService $records) {}

    public function store(Request $request, string $id): JsonResponse
    {
        $res = $this->resource('create');
        $inv = $this->investigation($id);
        $values = ['investigation_id' => $inv->id, 'parent_cause_id' => $this->parent($request), 'cause_level' => $request->input('cause_level'),
            'cause_category' => $request->input('cause_category') ?: null, 'description' => $request->input('description'),
            'sort_order' => $this->nextOrder($inv->id, $this->parent($request))];
        $cause = $this->records->create($res, $values);

        return response()->json(['data' => ['id' => $cause['id']]], 201);
    }

    public function update(Request $request, string $id, string $cause): JsonResponse
    {
        $res = $this->resource('update');
        $inv = $this->investigation($id);
        $this->cause($inv->id, $cause);
        $values = [];
        foreach (['description', 'cause_level', 'cause_category'] as $f) {
            if ($request->exists($f)) {
                $values[$f] = $f === 'cause_category' ? ($request->input($f) ?: null) : $request->input($f);
            }
        }
        if ($values === []) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'No hay cambios que guardar.', ['errors' => ['description' => ['No hay cambios que guardar.']]]);
        }
        $this->records->update($res, $cause, $values);

        return response()->json(['data' => ['id' => $cause]]);
    }

    public function move(Request $request, string $id, string $cause): JsonResponse
    {
        $res = $this->resource('update');
        $inv = $this->investigation($id);
        $row = $this->cause($inv->id, $cause);
        if (! $request->exists('parent_cause_id')) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Elige de qué causa depende (o el evento).', ['errors' => ['parent_cause_id' => ['Elige de qué causa depende (o el evento).']]]);
        }
        $parent = $this->parent($request);
        $position = $request->input('position');
        if ($position !== null && (! is_numeric($position) || (int) $position < 0)) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'La posición no es válida.', ['errors' => ['position' => ['La posición no es válida.']]]);
        }

        DB::transaction(function () use ($res, $inv, $row, $parent, $position) {
            if ($parent !== $row->parent_cause_id) {
                $this->records->update($res, $row->id, ['parent_cause_id' => $parent]);   // EventRules rechaza los ciclos.
            }
            // Orden entre las hermanas: la causa movida entra en la posición pedida (al final si no se indica).
            $siblings = array_column(DB::select(
                'select id from investigation_causes where investigation_id = ? and parent_cause_id is not distinct from ? and id <> ? and deleted_at is null
                  order by sort_order nulls last, created_at', [$inv->id, $parent, $row->id]), 'id');
            $at = $position === null ? count($siblings) : min((int) $position, count($siblings));
            array_splice($siblings, $at, 0, [$row->id]);
            $current = [];
            foreach (DB::select('select id, sort_order from investigation_causes where id = any(?::uuid[])', ['{'.implode(',', $siblings).'}']) as $s) {
                $current[$s->id] = (int) $s->sort_order;
            }
            foreach ($siblings as $i => $sid) {
                if (($current[$sid] ?? null) !== $i + 1) {
                    $this->records->update($res, $sid, ['sort_order' => $i + 1]);
                }
            }
        });

        return response()->json(['data' => ['id' => $row->id]]);
    }

    public function destroy(string $id, string $cause): JsonResponse
    {
        $res = $this->resource('delete');
        $inv = $this->investigation($id);
        $row = $this->cause($inv->id, $cause);
        $children = array_column(DB::select('select id from investigation_causes where parent_cause_id = ? and deleted_at is null order by sort_order nulls last, created_at', [$row->id]), 'id');

        DB::transaction(function () use ($res, $row, $children) {
            // Las causas hijas no se pierden: pasan a depender de la causa superior de la quitada (o del evento).
            foreach ($children as $child) {
                $this->records->update($res, $child, ['parent_cause_id' => $row->parent_cause_id]);
            }
            $this->records->delete($res, $row->id);
        });

        return response()->json(['data' => ['id' => $row->id, 'moved_children' => count($children), 'kept_action' => $row->corrective_action_id]]);
    }

    /** Permiso sobre la tabla de causas (403) antes que el bloqueo por estado (409). */
    private function resource(string $action): array
    {
        $res = Registry::resource('INV', 'investigation_causes');
        $this->records->authorize($res, $action);

        return $res;
    }

    private function investigation(string $id): object
    {
        $inv = Str::isUuid($id) ? DB::selectOne('select id, status from investigations where id = ? and deleted_at is null', [$id]) : null;
        if (! $inv) {
            throw ApiProblem::notFound('La investigación no existe o es de otra empresa.');
        }
        if (in_array($inv->status, self::LOCKED, true)) {
            throw new ApiProblem(409, 'conflict', 'Investigación aprobada', $inv->status === 'closed'
                ? 'La investigación está cerrada: su árbol de causas no cambia.'
                : 'El informe de la investigación está aprobado: su árbol de causas no cambia. Los nuevos hallazgos van como adenda.');
        }

        return $inv;
    }

    private function cause(string $invId, string $cause): object
    {
        $row = Str::isUuid($cause) ? DB::selectOne('select id, parent_cause_id, corrective_action_id from investigation_causes where id = ? and investigation_id = ? and deleted_at is null', [$cause, $invId]) : null;

        return $row ?? throw ApiProblem::notFound('La causa no existe en esta investigación.');
    }

    private function parent(Request $request): ?string
    {
        $p = $request->input('parent_cause_id');
        if ($p === null || $p === '') {
            return null;
        }
        if (! is_string($p) || ! Str::isUuid($p)) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'La causa superior no es válida.', ['errors' => ['parent_cause_id' => ['La causa superior no es válida.']]]);
        }

        return $p;
    }

    private function nextOrder(string $invId, ?string $parent): int
    {
        return (int) DB::selectOne('select coalesce(max(sort_order), 0) + 1 as n from investigation_causes where investigation_id = ? and parent_cause_id is not distinct from ? and deleted_at is null',
            [$invId, $parent])->n;
    }
}
