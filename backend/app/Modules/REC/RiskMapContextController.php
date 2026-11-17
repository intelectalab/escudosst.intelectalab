<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\REC\Rules\DocsFieldRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mapa de riesgos (M-MAP):
 *  GET  /map/maps/{id}/context   filas del IPERC vigente de la sede (significativas, con o sin marcador y su justificación, RN-MAP-01/02),
 *                                equipos de emergencia de la sede con su estado (vencido o fuera de servicio en rojo, RN-MAP-08) y, si el
 *                                mapa ya no es el vigente, la versión vigente más reciente (RN-MAP-06)
 *  POST /map/maps/{id}/justify   {row_id, reason}: justifica por qué un riesgo significativo no lleva pictograma (reason vacío la quita)
 */
class RiskMapContextController extends Controller
{
    public function context(string $id): JsonResponse
    {
        if (! in_array('map.risk_maps.read', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no ve el mapa de riesgos.');
        }
        $map = $this->map($id);
        $just = json_decode((string) $map->unmarked_justifications, true) ?: [];
        $rows = DB::select(
            "select r.id, r.row_number, coalesce(r.task, r.activity, '') as task, r.hazard, r.risk, r.risk_level, r.is_significant, m.code as matrix,
                    exists (select 1 from risk_map_markers k where k.risk_map_id = ? and k.iperc_row_id = r.id and k.deleted_at is null) as marked
               from iperc_matrices m join iperc_versions v on v.id = m.current_version_id and v.status = 'current'
               join iperc_rows r on r.iperc_version_id = v.id and r.deleted_at is null
              where m.deleted_at is null and (?::uuid is null or m.site_id is null or m.site_id = ?::uuid)
              order by r.is_significant desc, m.code, r.row_number limit 500", [$map->id, $map->site_id, $map->site_id]);
        foreach ($rows as $r) {
            $r->justification = $just[$r->id] ?? null;
        }
        $today = now('America/Lima')->format('Y-m-d');
        $equipment = DB::select(
            "select id, code, equipment_type, location_text, status, next_recharge_on, next_inspection_on, expires_on
               from emergency_equipment where deleted_at is null and status <> 'retired' and (?::uuid is null or site_id = ?::uuid) order by code", [$map->site_id, $map->site_id]);
        foreach ($equipment as $e) {
            $e->expired = $e->status === 'out_of_service' || collect([$e->next_recharge_on, $e->next_inspection_on, $e->expires_on])->filter()->contains(fn ($d) => substr($d, 0, 10) < $today);
        }
        $newer = in_array($map->status, ['current', 'review_required'], true) ? null : DB::selectOne(
            "select id, version_number, title from risk_maps where deleted_at is null and status in ('current', 'review_required') and id <> ?
                and site_id is not distinct from ?::uuid and area_id is not distinct from ?::uuid and floor_label is not distinct from ? order by version_number desc limit 1",
            [$map->id, $map->site_id, $map->area_id, $map->floor_label]);

        return response()->json(['data' => [
            'map' => ['id' => $map->id, 'status' => $map->status, 'version_number' => (int) $map->version_number],
            'rows' => $rows,
            'unmarked' => count(DocsFieldRules::unmarkedSignificant($map->id)),
            'equipment' => $equipment,
            'newer' => $newer,
        ]]);
    }

    public function justify(Request $request, string $id): JsonResponse
    {
        if (! in_array('map.risk_maps.update', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no edita el mapa de riesgos.');
        }
        $map = $this->map($id);
        if (! in_array($map->status, ['draft', 'in_review'], true)) {
            throw new ApiProblem(409, 'conflict', 'Mapa aprobado', 'El mapa ya está aprobado: para cambiarlo crea una nueva versión.');
        }
        $d = $request->validate(['row_id' => ['required', 'uuid'], 'reason' => ['nullable', 'string', 'max:500']]);
        $just = json_decode((string) $map->unmarked_justifications, true) ?: [];
        if (trim((string) ($d['reason'] ?? '')) === '') {
            unset($just[$d['row_id']]);
        } else {
            $just[$d['row_id']] = trim($d['reason']);
        }
        DB::update('update risk_maps set unmarked_justifications = ?::jsonb, updated_at = now(), updated_by = ? where id = ?',
            [json_encode((object) $just, JSON_UNESCAPED_UNICODE), (string) auth()->id(), $map->id]);

        return response()->json(['data' => ['unmarked' => count(DocsFieldRules::unmarkedSignificant($map->id))]]);
    }

    private function map(string $id): object
    {
        $m = Str::isUuid($id) ? DB::selectOne('select * from risk_maps where id = ? and deleted_at is null', [$id]) : null;

        return $m ?? throw ApiProblem::notFound('El mapa no existe o es de otra empresa.');
    }
}
