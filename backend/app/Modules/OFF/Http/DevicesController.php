<?php

namespace App\Modules\OFF\Http;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\Xlsx;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dispositivos de la app sin conexión (M-OFF SCR-OFF-05) y reporte de sincronización:
 *  GET  /sync/devices                 dispositivos por usuario con su última sincronización y lo que tiene pendiente de revisar
 *  POST /sync/devices/{id}/revoke     {reason, wipe?} revocar; al conectarse, el equipo borra sus datos (RN-OFF-04).
 *                                     wipe = true lo marca «borrado remoto solicitado» (estado «perdido» hasta que se conecte).
 *  GET  /sync/report.xlsx?from=&to=   lotes por dispositivo y usuario: operaciones, aplicadas, conflictos y rechazadas
 */
class DevicesController extends Controller
{
    public function index(): JsonResponse
    {
        $this->can('off.devices.read');
        $rows = DB::select("select d.id, d.device_uid, d.platform, d.model, d.app_version, d.status, d.registered_at, d.last_sync_at, d.revoked_at, d.revoke_reason,
                                   u.full_name as user_name,
                                   (select count(*) from sync_conflicts c join sync_batches cb on cb.id = c.sync_batch_id where cb.device_id = d.id and c.resolution is null and c.deleted_at is null) as open_conflicts,
                                   (select coalesce(sum(b.rejected_count), 0) from sync_batches b where b.device_id = d.id and b.deleted_at is null) as rejected
                              from devices d left join users u on u.id = d.user_id where d.deleted_at is null
                             order by d.status, u.full_name, d.last_sync_at desc nulls last");

        return response()->json(['data' => $rows]);
    }

    public function revoke(Request $request, string $id): JsonResponse
    {
        $this->can('off.devices.revoke');
        $d = Str::isUuid($id) ? DB::selectOne('select id, status, device_uid from devices where id = ? and deleted_at is null', [$id]) : null;
        if (! $d) {
            throw ApiProblem::notFound('El dispositivo no existe.');
        }
        if ($d->status !== 'active') {
            throw new ApiProblem(409, 'conflict', 'Ya revocado', 'El dispositivo ya no está activo.');
        }
        $v = $request->validate(['reason' => ['required', 'string', 'min:4', 'max:300'], 'wipe' => ['sometimes', 'boolean']],
            ['reason.required' => 'Escribe el motivo.', 'reason.min' => 'Escribe el motivo.']);
        $status = ($v['wipe'] ?? false) ? 'lost' : 'revoked';
        $uid = (string) auth()->id();
        DB::update('update devices set status = ?, revoked_at = now(), revoked_by = ?, revoke_reason = ?, updated_by = ?, updated_at = now() where id = ?',
            [$status, $uid, trim($v['reason']), $uid, $id]);
        $ctx = app(CompanyContext::class);
        DB::insert("insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes) values (?, ?, ?, 'user', 'update', 'OFF', 'devices', ?, ?::jsonb)",
            [$ctx->companyId, $uid, $ctx->roleCode, $id, json_encode(['status' => $status, 'reason' => trim($v['reason'])], JSON_UNESCAPED_UNICODE)]);

        return response()->json(['data' => ['id' => $id, 'status' => $status]]);
    }

    public function report(Request $request): Response
    {
        $this->can('off.devices.read');
        $from = (string) ($request->query('from') ?: now('America/Lima')->startOfMonth()->toDateString());
        $to = (string) ($request->query('to') ?: now('America/Lima')->toDateString());
        if (! strtotime($from) || ! strtotime($to)) {
            throw ApiProblem::badRequest('Las fechas no son válidas.');
        }
        $rows = DB::select("select u.full_name as user_name, coalesce(d.model, d.device_uid) as device, d.status, count(b.id) as batches, coalesce(sum(b.operations_count), 0) as ops,
                                   coalesce(sum(b.applied_count), 0) as applied, coalesce(sum(b.conflict_count), 0) as conflicts, coalesce(sum(b.rejected_count), 0) as rejected,
                                   max(b.received_at) as last_at
                              from devices d left join users u on u.id = d.user_id
                              left join sync_batches b on b.device_id = d.id and b.deleted_at is null and (b.received_at at time zone 'America/Lima')::date between ? and ?
                             where d.deleted_at is null group by 1, 2, 3 order by 1, 2", [$from, $to]);
        $label = ['active' => 'Activo', 'revoked' => 'Revocado', 'lost' => 'Borrado remoto pedido', 'wiped' => 'Borrado'];
        $out = [['Sincronización por dispositivo'], ["Del {$from} al {$to}."], [], ['Usuario', 'Dispositivo', 'Estado', 'Lotes', 'Operaciones', 'Aplicadas', 'Conflictos', 'Rechazadas', 'Última sincronización']];
        foreach ($rows as $r) {
            $out[] = [$r->user_name ?? '—', $r->device, $label[$r->status] ?? $r->status, (int) $r->batches, (int) $r->ops, (int) $r->applied, (int) $r->conflicts, (int) $r->rejected,
                $r->last_at ? date('d/m/Y H:i', strtotime($r->last_at)) : '—'];
        }

        return response(Xlsx::build('Sincronización', $out, 4), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"sincronizacion-{$from}-{$to}.xlsx\"",
        ]);
    }

    private function can(string $permission): void
    {
        if (! in_array($permission, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no administra los dispositivos.');
        }
    }
}
