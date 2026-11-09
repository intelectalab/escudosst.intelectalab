<?php

namespace App\Modules\IPC\Http;

use App\Http\Controllers\Controller;
use App\Modules\IPC\Services\IpercExport;
use App\Modules\IPC\Services\IpercWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Flujo de la matriz IPERC con empresa activa (las filas y controles se editan con /records/IPC/...):
 *
 *  GET  /ipc/matrices/{id}/workspace?version=   editor: matriz, criterios, versiones, filas, mapa de calor y pendientes
 *  POST /ipc/matrices/{id}/versions             nueva versión en borrador (copia la vigente) {change_reason}
 *  POST /ipc/versions/{id}/submit               enviar a aprobación {participants: [{name, role}]}
 *  POST /ipc/versions/{id}/return               observar y devolver a borrador {comment}
 *  POST /ipc/versions/{id}/approve              aprobar (otra persona): queda vigente
 *  GET  /ipc/versions/{id}/export.pdf | .xlsx   matriz en PDF o Excel
 */
class IpercController extends Controller
{
    public function __construct(private IpercWorkflow $flow, private IpercExport $export) {}

    public function workspace(Request $request, string $matrix): JsonResponse
    {
        $version = $request->query('version');
        $p = \App\Core\Auth\Permissions::effective();
        $can = fn (string $x) => in_array("ipc.{$x}", $p, true);

        return response()->json(['data' => $this->flow->workspace($matrix, is_string($version) && $version !== '' ? $version : null) + ['can' => [
            'edit' => $can('iperc_rows.create') && $can('iperc_rows.update'),
            'controls' => $can('iperc_controls.create') && $can('iperc_controls.update'),
            'submit' => $can('iperc_versions.update'),
            'approve' => $can('iperc_versions.approve'),
            'new_version' => $can('iperc_versions.create'),
            'export' => $can('iperc_matrices.export'),
        ]]]);
    }

    public function newVersion(Request $request, string $matrix): JsonResponse
    {
        return response()->json(['data' => $this->flow->newVersion($matrix, $request->input('change_reason'))], 201);
    }

    public function submit(Request $request, string $version): Response
    {
        $p = $request->input('participants');
        $this->flow->submit($version, is_array($p) ? $p : null);

        return response()->noContent();
    }

    public function sendBack(Request $request, string $version): Response
    {
        $this->flow->sendBack($version, $request->input('comment'));

        return response()->noContent();
    }

    public function approve(string $version): JsonResponse
    {
        return response()->json(['data' => $this->flow->approve($version)]);
    }

    public function pdf(string $version): Response
    {
        [$name, $bytes] = $this->export->pdf($version);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"{$name}\""]);
    }

    public function xlsx(string $version): Response
    {
        [$name, $bytes] = $this->export->xlsx($version);

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$name}\"",
        ]);
    }
}
