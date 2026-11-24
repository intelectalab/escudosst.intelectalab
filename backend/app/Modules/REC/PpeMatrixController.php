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
 *  GET  /epp/stock                               saldo por sede de cada EPP frente a su mínimo
 *  GET  /epp/kardex.xlsx                         kárdex con saldo por movimiento y consumo por sede, área e ítem
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
                  where w.deleted_at is null and w.status = 'active' and w.current_position_id = any(?::uuid[])".\App\Core\Auth\Scope::andSql('w.current_site_id', 'w.current_area_id')."
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

    /** GET /epp/stock — SCR-EPP-01: saldo de cada EPP por sede frente a su mínimo (RN-EPP-08). */
    public function stock(): JsonResponse
    {
        $this->can('epp.ppe_stock_movements.read');
        $rows = DB::select(
            "select i.id as item_id, i.code, i.name as item, i.min_stock, m.site_id, s.name as site,
                    sum(case when m.movement_type in ('in','return','adjustment') then m.quantity else -m.quantity end) as balance
               from ppe_stock_movements m join ppe_items i on i.id = m.ppe_item_id and i.deleted_at is null left join sites s on s.id = m.site_id
              where m.deleted_at is null".\App\Core\Auth\Scope::andSql('m.site_id', null).' group by i.id, i.code, i.name, i.min_stock, m.site_id, s.name order by i.name, s.name nulls first');

        return response()->json(['data' => array_map(fn ($r) => ['item_id' => $r->item_id, 'code' => $r->code, 'item' => $r->item, 'site_id' => $r->site_id,
            'site' => $r->site ?? 'Sin sede (almacén general)', 'balance' => (float) $r->balance, 'min_stock' => $r->min_stock === null ? null : (float) $r->min_stock,
            'low' => $r->min_stock !== null && (float) $r->balance < (float) $r->min_stock], $rows)]);
    }

    /**
     * GET /epp/kardex.xlsx?from=&to= — SCR-EPP-04 y §11: kárdex por sede e ítem con saldo después de cada movimiento, y el
     * consumo (entregas) del periodo por sede, área e ítem.
     */
    public function kardex(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $this->can('epp.ppe_stock_movements.read');
        $from = (string) ($request->query('from') ?: now('America/Lima')->startOfYear()->toDateString());
        $to = (string) ($request->query('to') ?: now('America/Lima')->toDateString());
        $types = ['in' => 'Ingreso', 'out' => 'Entrega', 'return' => 'Devolución', 'disposal' => 'Baja', 'adjustment' => 'Ajuste', 'transfer' => 'Traslado'];
        $mov = DB::select(
            "select * from (
               select m.moved_at, s.name as site, i.name as item, m.size, m.movement_type, m.quantity, m.reference, m.supplier_name,
                      sum(case when m.movement_type in ('in','return','adjustment') then m.quantity else -m.quantity end)
                        over (partition by m.ppe_item_id, m.site_id order by m.moved_at, m.created_at rows unbounded preceding) as balance
                 from ppe_stock_movements m join ppe_items i on i.id = m.ppe_item_id left join sites s on s.id = m.site_id where m.deleted_at is null".\App\Core\Auth\Scope::andSql('m.site_id', null).") x
              where (moved_at at time zone 'America/Lima')::date between ? and ? order by site nulls first, item, moved_at", [$from, $to]);
        $out = [['Kárdex de EPP'], ["Del {$from} al {$to}. Saldo: existencias después de cada movimiento."], [],
            ['Sede', 'EPP', 'Talla', 'Fecha', 'Movimiento', 'Cantidad', 'Saldo', 'Referencia']];
        foreach ($mov as $m) {
            $out[] = [$m->site ?? 'Sin sede', $m->item, $m->size, date('d/m/Y H:i', strtotime($m->moved_at)), $types[$m->movement_type] ?? $m->movement_type,
                (float) $m->quantity, (float) $m->balance, trim(($m->reference ?? '').($m->supplier_name ? ' · '.$m->supplier_name : ''), ' ·')];
        }
        if ($mov === []) {
            $out[] = ['Sin movimientos en el periodo'];
        }
        $out[] = [];
        $out[] = ['Consumo (entregas) por sede, área e ítem'];
        $out[] = ['Sede', 'Área', 'EPP', 'Unidades entregadas', 'Trabajadores'];
        $use = DB::select(
            "select s.name as site, a.name as area, i.name as item, sum(d.quantity) as units, count(distinct d.worker_id) as workers
               from ppe_deliveries d join ppe_items i on i.id = d.ppe_item_id left join workers w on w.id = d.worker_id
               left join sites s on s.id = coalesce(d.site_id, w.current_site_id) left join areas a on a.id = w.current_area_id
              where d.deleted_at is null and d.status = 'delivered' and (d.delivered_at at time zone 'America/Lima')::date between ? and ?".\App\Core\Auth\Scope::andSql('coalesce(d.site_id, w.current_site_id)', null)."
              group by 1, 2, 3 order by 1 nulls first, 2 nulls first, 4 desc", [$from, $to]);
        foreach ($use as $u) {
            $out[] = [$u->site ?? 'Sin sede', $u->area ?? 'Sin área', $u->item, (float) $u->units, (int) $u->workers];
        }
        if ($use === []) {
            $out[] = ['Sin entregas en el periodo'];
        }

        return response(\App\Core\Support\Xlsx::build('Kárdex', $out, 4), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"kardex-epp-{$from}-{$to}.xlsx\"",
        ]);
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no gestiona el EPP.');
        }
    }
}
