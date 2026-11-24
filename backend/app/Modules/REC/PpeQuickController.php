<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Modules\FIR\AssistedSignature;
use App\Modules\ING\GateController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Entregar EPP en campo (M-EPP SCR-EPP-03):
 *  GET  /epp/quick/lookup?code=   busca al trabajador por DNI o por el QR (o número) de su fotocheck; devuelve sus EPP exigidos por
 *                                 puesto con su estado (vigente, por vencer según ppe_replace_notice_days, vencido, faltante), las tallas y
 *                                 el stock de su sede
 *  POST /epp/quick/deliver        {worker_id, delivery_reason, items: [{ppe_item_id, quantity, size?}], assisted, dni + pin | image}
 *                                 registra cada entrega con las reglas de siempre (stock, vida útil, capacitación de uso) y la firma del
 *                                 trabajador ahí mismo (firma asistida, RN-EPP-02 / RN-FIR-05): queda «entregada».
 */
final class PpeQuickController
{
    public function lookup(Request $request): JsonResponse
    {
        $this->can('epp.ppe_deliveries.create');
        $code = trim((string) $request->query('code'));
        if ($code === '') {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Escribe el DNI o escanea el fotocheck.', ['errors' => ['code' => ['Escribe el DNI o escanea el fotocheck.']]]);
        }
        $who = GateController::resolveCode($code);
        if (! $who || $who['type'] !== 'worker') {
            throw ApiProblem::notFound($who ? 'Es personal de un contratista: su EPP lo entrega su empresa.' : 'No encontramos a un trabajador con ese DNI o fotocheck.');
        }
        $w = DB::selectOne(
            "select w.id, w.full_name, w.document_last4, w.status, w.current_site_id as site_id, w.current_position_id as position_id, p.name as position, s.name as site,
                    w.pin_hash is not null as has_pin
               from workers w left join positions p on p.id = w.current_position_id left join sites s on s.id = w.current_site_id where w.id = ?", [$who['id']]);
        if ($w->status === 'terminated') {
            throw new ApiProblem(409, 'conflict', 'Trabajador cesado', 'El trabajador está cesado: no se le entrega EPP.');
        }
        $notice = (int) LegalParams::number('ppe_replace_notice_days', 15);
        $today = now('America/Lima')->toDateString();
        $soon = now('America/Lima')->addDays($notice)->toDateString();
        $items = $w->position_id ? DB::select(
            "select i.id as ppe_item_id, i.code, i.name, array_to_json(coalesce(i.size_options, '{}'::text[])) as sizes, r.quantity, r.is_mandatory,
                    (select max(coalesce(d.expires_on, '9999-12-31'::date)) from ppe_deliveries d where d.worker_id = ? and d.ppe_item_id = i.id
                        and d.deleted_at is null and d.status in ('delivered', 'due_for_replacement')) as until,
                    exists (select 1 from ppe_deliveries d where d.worker_id = ? and d.ppe_item_id = i.id and d.deleted_at is null and d.status = 'pending_signature') as pending_signature
               from ppe_position_requirements r join ppe_items i on i.id = r.ppe_item_id and i.deleted_at is null and coalesce(i.is_active, true)
              where r.position_id = ? and r.deleted_at is null order by r.is_mandatory desc, i.name", [$w->id, $w->id, $w->position_id]) : [];
        $out = array_map(function ($i) use ($today, $soon, $w) {
            $state = $i->until === null ? 'missing' : ($i->until < $today ? 'expired' : ($i->until <= $soon ? 'due_soon' : 'ok'));

            return ['ppe_item_id' => $i->ppe_item_id, 'code' => $i->code, 'name' => $i->name, 'quantity' => (int) $i->quantity, 'mandatory' => (bool) $i->is_mandatory,
                'state' => $state, 'until' => $i->until === '9999-12-31' ? null : $i->until, 'pending_signature' => (bool) $i->pending_signature,
                'sizes' => array_values(array_filter(json_decode((string) $i->sizes, true) ?: [])),
                'stock' => \App\Modules\REC\Rules\PpeCommitteeRules::stock($i->ppe_item_id, $w->site_id)];
        }, $items);

        return response()->json(['data' => ['worker' => ['id' => $w->id, 'full_name' => $w->full_name, 'document' => $w->document_last4 ? '****'.$w->document_last4 : null,
            'position' => $w->position, 'site' => $w->site, 'has_pin' => (bool) $w->has_pin], 'items' => $out, 'notice_days' => $notice]]);
    }

    public function deliver(Request $request, RecordsService $records): JsonResponse
    {
        $this->can('epp.ppe_deliveries.create');
        $d = $request->validate([
            'worker_id' => ['required', 'uuid'],
            'delivery_reason' => ['required', 'in:initial,replacement,loss,damage,other'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.ppe_item_id' => ['required', 'uuid', 'distinct'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'items.*.size' => ['nullable', 'string', 'max:20'],
        ], ['items.required' => 'Marca al menos un EPP para entregar.', 'items.min' => 'Marca al menos un EPP para entregar.', 'delivery_reason.required' => 'Elige el motivo de la entrega.',
            'items.*.ppe_item_id.distinct' => 'Ese EPP está dos veces en la entrega.']);
        if (! Str::isUuid($d['worker_id']) || ! DB::selectOne('select 1 as ok from workers where id = ? and deleted_at is null', [$d['worker_id']])) {
            throw ApiProblem::notFound('El trabajador no existe.');
        }
        $res = Registry::resource('EPP', 'ppe_deliveries');
        $ids = DB::transaction(function () use ($records, $res, $d, $request) {
            $ids = [];
            foreach ($d['items'] as $it) {
                $sized = DB::selectOne('select coalesce(array_length(size_options, 1), 0) > 0 as has_sizes, name from ppe_items where id = ? and deleted_at is null', [$it['ppe_item_id']]);
                if ($sized && $sized->has_sizes && empty($it['size'])) {
                    throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', "Elige la talla de «{$sized->name}».", ['errors' => ['items' => ["Elige la talla de «{$sized->name}»."]]]);
                }
                $row = $records->create($res, ['worker_id' => $d['worker_id'], 'ppe_item_id' => $it['ppe_item_id'], 'quantity' => $it['quantity'] ?? 1,
                    'size' => $it['size'] ?? null, 'delivery_reason' => $d['delivery_reason']]);
                // RN-EPP-02: la firma del trabajador en el mismo momento deja la entrega «entregada».
                $sig = AssistedSignature::sign($request, 'ppe_deliveries', $row['id'], $d['worker_id'], 'worker');
                DB::update("update ppe_deliveries set signature_id = ?, status = 'delivered', updated_by = ?, updated_at = now() where id = ?", [$sig, (string) auth()->id(), $row['id']]);
                $ids[] = $row['id'];
            }

            return $ids;
        });

        return response()->json(['data' => ['ids' => $ids, 'delivered' => count($ids)]], 201);
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no entrega EPP.');
        }
    }
}
