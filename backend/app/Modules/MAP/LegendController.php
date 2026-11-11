<?php

namespace App\Modules\MAP;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GET /map/maps/{id}/legend — mapa de riesgos con su leyenda (M-MAP SCR-MAP-02, 04 y 05): datos del plano y cada
 * pictograma con su ubicación, tipo, la fila IPERC vinculada (matriz, versión, peligro y nivel) y el equipo de emergencia.
 * Lo usan la vista principal del módulo (tocar un marcador) y el afiche imprimible.
 */
class LegendController extends Controller
{
    public function __invoke(string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            throw ApiProblem::notFound('El mapa no existe.');
        }
        $map = DB::selectOne(
            'select m.id, m.title, m.floor_label, m.version_number, m.status, m.approved_at, m.effective_on, m.plan_file_id, m.image_width, m.image_height,
                    s.name as site_name, a.name as area_name, u.full_name as approved_by_name
               from risk_maps m join sites s on s.id = m.site_id left join areas a on a.id = m.area_id left join users u on u.id = m.approved_by
              where m.id = ? and m.deleted_at is null', [$id]);
        if (! $map) {
            throw ApiProblem::notFound('El mapa no existe o es de otra empresa.');
        }
        $markers = DB::select(
            'select k.id, k.marker_type, k.pictogram_code, k.x_pct, k.y_pct, k.label, k.notes,
                    r.id as row_id, r.row_number, r.hazard, r.risk, coalesce(r.residual_level, r.risk_level) as level, r.deleted_at as row_deleted,
                    v.version_number, v.status as version_status, mx.id as matrix_id, mx.code as matrix_code, mx.title as matrix_title, c.level_bands,
                    e.id as equipment_id, e.code as equipment_code, e.equipment_type, e.location_text
               from risk_map_markers k
               left join iperc_rows r on r.id = k.iperc_row_id
               left join iperc_versions v on v.id = r.iperc_version_id
               left join iperc_matrices mx on mx.id = v.iperc_matrix_id
               left join risk_criteria c on c.id = mx.risk_criteria_id
               left join emergency_equipment e on e.id = k.emergency_equipment_id
              where k.risk_map_id = ? and k.deleted_at is null order by k.y_pct, k.x_pct', [$id]);

        $place = implode(' · ', array_filter([$map->site_name, $map->area_name, $map->floor_label]));

        return response()->json(['data' => [
            'map' => [...(array) $map, 'place' => $place],
            'markers' => array_map(function ($k) use ($place) {
                $band = $k->level_bands ? collect(json_decode($k->level_bands, true) ?: [])->firstWhere('code', $k->level) : null;

                return [
                    'id' => $k->id, 'marker_type' => $k->marker_type, 'pictogram_code' => $k->pictogram_code, 'x_pct' => (float) $k->x_pct, 'y_pct' => (float) $k->y_pct,
                    'label' => $k->label, 'notes' => $k->notes, 'location' => $place,
                    'iperc' => $k->row_id ? [
                        'row_id' => $k->row_id, 'row_number' => (int) $k->row_number, 'hazard' => $k->hazard, 'risk' => $k->risk,
                        'level' => $band['label'] ?? $k->level, 'color' => $band['color'] ?? null, 'matrix_id' => $k->matrix_id, 'matrix_code' => $k->matrix_code,
                        'matrix_title' => $k->matrix_title, 'version_number' => (int) $k->version_number, 'version_status' => $k->version_status,
                        // Coherencia (SCR-MAP-03): la fila fue retirada o su versión ya no es la vigente.
                        'stale' => $k->row_deleted !== null || $k->version_status === 'obsolete',
                    ] : null,
                    'equipment' => $k->equipment_id ? ['id' => $k->equipment_id, 'code' => $k->equipment_code, 'type' => $k->equipment_type, 'location' => $k->location_text] : null,
                ];
            }, $markers),
        ]]);
    }
}
