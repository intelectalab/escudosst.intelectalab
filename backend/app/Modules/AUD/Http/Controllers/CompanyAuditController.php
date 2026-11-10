<?php

namespace App\Modules\AUD\Http\Controllers;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\REC\Registry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GET /company/audit — bitácora de la empresa activa (M-AUD): quién cambió qué y cuándo.
 * Filtros: module, entity_id, user_id, from, to, page. Cada fila trae el módulo y la tabla para abrir el registro.
 */
class CompanyAuditController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! in_array('aud.audit_logs.read', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no ve la bitácora.');
        }
        $where = ['a.company_id = (select nullif(current_setting(\'app.company_id\', true), \'\')::uuid)'];
        $bind = [];
        if ($m = $request->query('module')) {
            $where[] = 'a.module_code = ?';
            $bind[] = strtoupper(substr((string) $m, 0, 3));
        }
        foreach (['entity_id', 'user_id'] as $k) {
            if (($v = $request->query($k)) && Str::isUuid((string) $v)) {
                $where[] = ($k === 'user_id' ? 'a.actor_user_id' : 'a.entity_id').' = ?';
                $bind[] = $v;
            }
        }
        foreach (['from' => '>=', 'to' => '<'] as $k => $op) {
            if (($v = $request->query($k)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v)) {
                $where[] = "a.occurred_at {$op} (?::date".($k === 'to' ? ' + 1' : '').")::timestamp at time zone 'America/Lima'";
                $bind[] = $v;
            }
        }
        $page = max(1, (int) $request->query('page', 1));
        $sql = 'from audit_logs a left join users u on u.id = a.actor_user_id where '.implode(' and ', $where);
        $total = (int) DB::selectOne("select count(*) as n {$sql}", $bind)->n;
        $rows = DB::select("select a.id, a.occurred_at, a.action, a.module_code, a.entity_type, a.entity_id, a.changes, a.actor_role, u.full_name as actor_name
                              {$sql} order by a.occurred_at desc limit 50 offset ".(($page - 1) * 50), $bind);
        $resources = Registry::data()['resources'];

        return response()->json([
            'data' => array_map(fn ($r) => [
                ...(array) $r,
                'changes' => json_decode((string) $r->changes, true),
                'tab' => $resources[$r->entity_type]['tab'] ?? $r->entity_type,
                'module_of_entity' => $resources[$r->entity_type]['module'] ?? null,
            ], $rows),
            'meta' => ['page' => $page, 'total' => $total, 'pages' => (int) max(1, ceil($total / 50))],
        ]);
    }
}
