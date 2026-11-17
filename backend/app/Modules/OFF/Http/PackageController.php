<?php

namespace App\Modules\OFF\Http;

use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GET /sync/package — paquete de datos de referencia para trabajar sin conexión (M-OFF SCR-OFF-02): trabajadores del
 * ámbito de la persona (sus sedes o áreas si tiene alcance), plantillas de inspección con sus ítems, IPERC vigente del
 * área, EPP con lo exigido por puesto y el estado de los fotochecks, con el tamaño estimado.
 * RN-OFF-05: nunca incluye datos médicos; de la aptitud solo va el estado del fotocheck.
 */
class PackageController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $ctx = app(CompanyContext::class);
        $scopes = DB::select("select site_id, area_id from membership_scopes where membership_id = ? and scope_type in ('site', 'area') and deleted_at is null", [$ctx->membershipId]);
        $sites = array_values(array_filter(array_column($scopes, 'site_id')));
        $areas = array_values(array_filter(array_column($scopes, 'area_id')));
        $where = '';
        $bind = [];
        if ($sites || $areas) {
            $where = ' and ('.implode(' or ', array_filter([$sites ? 'w.current_site_id = any(?::uuid[])' : null, $areas ? 'w.current_area_id = any(?::uuid[])' : null])).')';
            if ($sites) {
                $bind[] = '{'.implode(',', $sites).'}';
            }
            if ($areas) {
                $bind[] = '{'.implode(',', $areas).'}';
            }
        }
        $workers = DB::select("select w.id, w.full_name, w.document_last4, w.status, w.current_site_id as site_id, w.current_area_id as area_id, w.current_position_id as position_id
                                 from workers w where w.deleted_at is null and w.status in ('active', 'on_leave'){$where} order by w.full_name", $bind);
        $ids = array_column($workers, 'id');
        $areaIds = array_values(array_unique(array_filter(array_column($workers, 'area_id'))));
        $positionIds = array_values(array_unique(array_filter(array_column($workers, 'position_id'))));
        $arr = fn (array $l) => '{'.implode(',', $l).'}';

        $templates = DB::select("select id, code, name, inspection_type, version_number from inspection_templates where deleted_at is null and status = 'active' order by name");
        $items = $templates ? DB::select('select template_id, section, item_code, question, answer_type, is_critical, requires_photo_on_fail from inspection_template_items
                                           where template_id = any(?::uuid[]) and deleted_at is null order by template_id, sort_order', [$arr(array_column($templates, 'id'))]) : [];
        $iperc = DB::select("select m.code, m.title, m.area_id, m.position_id, r.task, r.hazard, r.risk, r.risk_level, r.residual_level, r.is_significant
                               from iperc_matrices m join iperc_rows r on r.iperc_version_id = m.current_version_id and r.deleted_at is null
                              where m.deleted_at is null and m.status in ('active', 'review_overdue')".($areaIds || $positionIds ? ' and (m.area_id = any(?::uuid[]) or m.position_id = any(?::uuid[]))' : '').'
                              order by m.code, r.row_number limit 3000', $areaIds || $positionIds ? [$arr($areaIds), $arr($positionIds)] : []);
        $ppe = DB::select('select id, code, name, category, unit from ppe_items where deleted_at is null and coalesce(is_active, true) order by name');
        $ppeReq = $positionIds ? DB::select('select position_id, ppe_item_id, quantity, replacement_days, is_mandatory from ppe_position_requirements where position_id = any(?::uuid[]) and deleted_at is null', [$arr($positionIds)]) : [];
        $credentials = $ids ? DB::select('select id, worker_id, credential_number, status, expires_on, blocking_reasons from credentials where worker_id = any(?::uuid[]) and deleted_at is null', [$arr($ids)]) : [];

        $data = ['generated_at' => now()->toIso8601String(), 'workers' => $workers, 'inspection_templates' => $templates, 'inspection_template_items' => $items,
            'iperc' => $iperc, 'ppe_items' => $ppe, 'ppe_position_requirements' => $ppeReq, 'credentials' => $credentials];
        $size = strlen((string) json_encode($data, JSON_UNESCAPED_UNICODE));

        return response()->json(['data' => $data, 'meta' => ['size_bytes' => $size, 'counts' => [
            'workers' => count($workers), 'inspection_templates' => count($templates), 'iperc_rows' => count($iperc), 'ppe_items' => count($ppe), 'credentials' => count($credentials),
        ], 'scoped' => (bool) ($sites || $areas)]]);
    }
}
