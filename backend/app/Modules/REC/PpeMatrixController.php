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
 * EPP (M-EPP):
 *  GET  /epp/matrix                              matriz puesto × EPP obligatorio y cumplimiento por trabajador (RN-EPP-04: todos los EPP
 *                                                obligatorios de su puesto entregados y con fecha de reposición no vencida)
 *  GET  /epp/replacement-requests                solicitudes de reposición hechas desde la app del trabajador
 *  POST /epp/replacement-requests/{id}/resolve   {status: rejected, note}: rechaza con motivo (al entregar el EPP se atiende sola)
 */
class PpeMatrixController extends Controller
{
    public function matrix(): JsonResponse
    {
        $this->can('epp.ppe_deliveries.read');
        $req = DB::select(
            'select r.position_id, p.name as position, r.ppe_item_id, i.name as item, r.quantity, r.is_mandatory
               from ppe_position_requirements r join positions p on p.id = r.position_id and p.deleted_at is null join ppe_items i on i.id = r.ppe_item_id and i.deleted_at is null
              where r.deleted_at is null order by p.name, i.name');
        $byPos = [];
        foreach ($req as $r) {
            $byPos[$r->position_id] ??= ['position_id' => $r->position_id, 'position' => $r->position, 'items' => []];
            $byPos[$r->position_id]['items'][] = ['ppe_item_id' => $r->ppe_item_id, 'item' => $r->item, 'quantity' => (int) $r->quantity, 'mandatory' => (bool) $r->is_mandatory];
        }
        $today = now('America/Lima')->toDateString();
        $workers = [];
        if ($byPos) {
            $rows = DB::select(
                "select w.id, w.full_name, w.current_position_id as position_id, d.ppe_item_id, max(coalesce(d.expires_on, '9999-12-31'::date)) as until
                   from workers w left join ppe_deliveries d on d.worker_id = w.id and d.deleted_at is null and d.status in ('delivered', 'due_for_replacement')
                  where w.deleted_at is null and w.status = 'active' and w.current_position_id = any(?::uuid[])
                  group by w.id, w.full_name, w.current_position_id, d.ppe_item_id", ['{'.implode(',', array_keys($byPos)).'}']);
            foreach ($rows as $r) {
                $workers[$r->id] ??= ['worker_id' => $r->id, 'name' => $r->full_name, 'position_id' => $r->position_id, 'has' => []];
                if ($r->ppe_item_id) {
                    $workers[$r->id]['has'][$r->ppe_item_id] = $r->until;
                }
            }
            foreach ($workers as &$w) {
                $items = array_filter($byPos[$w['position_id']]['items'], fn ($i) => $i['mandatory']);
                $w['items'] = array_values(array_map(fn ($i) => ['ppe_item_id' => $i['ppe_item_id'], 'item' => $i['item'],
                    'state' => ! isset($w['has'][$i['ppe_item_id']]) ? 'missing' : ($w['has'][$i['ppe_item_id']] < $today ? 'expired' : 'ok')], $items));
                $ok = count(array_filter($w['items'], fn ($i) => $i['state'] === 'ok'));
                $w['compliant'] = $ok === count($w['items']);
                $w['pct'] = $w['items'] ? (int) round($ok * 100 / count($w['items'])) : 100;
                unset($w['has']);
            }
            unset($w);
        }
        $list = array_values($workers);

        return response()->json(['data' => ['positions' => array_values($byPos), 'workers' => $list,
            'compliance' => $list ? (int) round(count(array_filter($list, fn ($w) => $w['compliant'])) * 100 / count($list)) : null]]);
    }

    public function requests(): JsonResponse
    {
        $this->can('epp.ppe_deliveries.read');

        return response()->json(['data' => DB::select(
            "select q.id, q.reason, q.notes, q.status, q.created_at, q.resolution_note, q.resolved_at, w.id as worker_id, w.full_name as worker, i.id as ppe_item_id, i.name as item
               from ppe_replacement_requests q join workers w on w.id = q.worker_id join ppe_items i on i.id = q.ppe_item_id
              where q.deleted_at is null order by (q.status = 'pending') desc, q.created_at desc limit 200")]);
    }

    public function resolve(Request $request, string $id): JsonResponse
    {
        $this->can('epp.ppe_deliveries.create');
        $d = $request->validate(['status' => ['required', 'in:rejected'], 'note' => ['required', 'string', 'max:500']], ['note.required' => 'Indica por qué no se repone.']);
        $q = Str::isUuid($id) ? DB::selectOne("select id, status from ppe_replacement_requests where id = ? and deleted_at is null", [$id]) : null;
        if (! $q) {
            throw ApiProblem::notFound('La solicitud no existe.');
        }
        if ($q->status !== 'pending') {
            throw new ApiProblem(409, 'conflict', 'Ya resuelta', 'Esta solicitud ya fue resuelta.');
        }
        DB::update("update ppe_replacement_requests set status = 'rejected', resolution_note = ?, resolved_by = ?, resolved_at = now(), updated_at = now() where id = ?", [$d['note'], (string) auth()->id(), $id]);

        return response()->json(['data' => ['id' => $id, 'status' => 'rejected']]);
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no gestiona el EPP.');
        }
    }
}
