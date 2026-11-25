<?php

namespace App\Modules\OFF\Http;

use App\Core\Auth\Permissions;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\OFF\Services\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * App offline (M-OFF, docs/16), con empresa activa y el complemento OFF habilitado:
 *
 *  POST /sync/devices                  registrar el dispositivo {device_uid, platform, model, app_version} y ver su estado
 *  POST /sync/batches                  lote de operaciones {batch_uuid, device_uid, client_created_at, operations: [...]}
 *  GET  /sync/changes                  cambios del servidor desde una marca {changes, next_since, has_more}
 *  GET  /sync/conflicts                conflictos abiertos (propios; todos para quien resuelve en la empresa)
 *  POST /sync/conflicts/{id}/resolve   {resolution: server_wins|client_wins|merged|discarded, payload?, notes?}
 *
 * Cada operación: {idempotency_key, op: create|update, entity, entity_id, base_version, payload, created_at_device}.
 * Un dispositivo revocado recibe 403 device_revoked con wipe: true (la app borra sus datos locales).
 */
class SyncController extends Controller
{
    public function __construct(private SyncService $sync) {}

    public function device(Request $request): JsonResponse
    {
        $device = $this->sync->device($request->all(), in_array('off.devices.create', Permissions::effective(), true));

        return response()->json(['data' => $this->sync->status($device) + ['offline_warning_days' => max(1, (int) \App\Core\Support\LegalParams::number('offline_warning_days', 3)),
            // RN-OFF-06: tamaño máximo de las fotos tomadas sin conexión (se comprimen en el equipo).
            'offline_photo_max_kb' => max(100, (int) \App\Core\Support\LegalParams::number('offline_photo_max_kb', 800)),
            // RN-OFF-07: días sin sincronizar tras los que el equipo deja de aceptar nuevas capturas.
            'offline_max_days' => max(1, (int) \App\Core\Support\LegalParams::number('offline_max_days', 7))]]);
    }

    public function batch(Request $request): JsonResponse
    {
        $device = $this->sync->device($request->all(), in_array('off.devices.create', Permissions::effective(), true));

        return response()->json(['data' => $this->sync->batch($device, $request->all())]);
    }

    /**
     * GET /sync/changes?since=<marca>&device_id=<device_uid>&limit= — cambios del servidor para lo que guarda el equipo
     * (docs/08 §13). Un dispositivo revocado recibe 403 device_revoked con wipe: true.
     */
    public function changes(Request $request, \App\Modules\OFF\Services\ChangeFeed $feed): JsonResponse
    {
        $this->sync->device(['device_uid' => $request->query('device_id', $request->query('device_uid'))], in_array('off.devices.create', Permissions::effective(), true));
        $since = $request->query('since');

        return response()->json(['data' => $feed->changes(is_string($since) ? $since : null, (int) $request->query('limit', \App\Modules\OFF\Services\ChangeFeed::MAX_LIMIT))]);
    }

    public function conflicts(): JsonResponse
    {
        return response()->json(['data' => $this->sync->conflicts($this->resolvesAll())]);
    }

    public function resolve(Request $request, string $conflict): JsonResponse
    {
        return response()->json(['data' => $this->sync->resolve($conflict, $request->all(), $this->resolvesAll())]);
    }

    /** El Jefe SST (y quien administra la cuenta o la asesora) ve y resuelve los conflictos de toda la empresa. */
    private function resolvesAll(): bool
    {
        return in_array(app(CompanyContext::class)->roleCode, ['sst_manager', 'account_admin', 'consultant'], true);
    }
}
