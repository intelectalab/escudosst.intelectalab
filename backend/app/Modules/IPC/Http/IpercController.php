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

    /** Alcance por sede/área (App\Core\Auth\Scope): la matriz de la versión debe ser de la sede o área del usuario. */
    private function scopeVersion(string $version, bool $write = false): void
    {
        $m = \Illuminate\Support\Str::isUuid($version) ? \Illuminate\Support\Facades\DB::selectOne('select iperc_matrix_id as id from iperc_versions where id = ?', [$version])?->id : null;
        if ($m) {
            $write ? \App\Core\Auth\Scope::assertRowWritable('iperc_matrices', $m) : \App\Core\Auth\Scope::assertRow('iperc_matrices', $m);
        }
    }

    public function workspace(Request $request, string $matrix): JsonResponse
    {
        \App\Core\Auth\Scope::assertRow('iperc_matrices', $matrix);
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
        ], 'approval' => [
            // RN-IPC-07: con comité se elige la sesión que aprobó; sin comité, firma o aprueba el supervisor.
            'sst_model' => \Illuminate\Support\Facades\DB::selectOne('select sst_model from companies where id = ?', [app(\App\Core\Tenancy\CompanyContext::class)->companyId])?->sst_model,
            'meetings' => \Illuminate\Support\Facades\DB::select("select id, number, meeting_type, held_at from committee_meetings where deleted_at is null and held_at is not null order by held_at desc limit 20"),
        ]]]);
    }

    public function newVersion(Request $request, string $matrix): JsonResponse
    {
        \App\Core\Auth\Scope::assertRowWritable('iperc_matrices', $matrix);
        return response()->json(['data' => $this->flow->newVersion($matrix, $request->input('change_reason'))], 201);
    }

    public function submit(Request $request, string $version): Response
    {
        $this->scopeVersion($version, true);
        $p = $request->input('participants');
        $this->flow->submit($version, is_array($p) ? $p : null);

        return response()->noContent();
    }

    public function sendBack(Request $request, string $version): Response
    {
        $this->scopeVersion($version, true);
        $this->flow->sendBack($version, $request->input('comment'));

        return response()->noContent();
    }

    public function approve(Request $request, string $version): JsonResponse
    {
        $this->scopeVersion($version, true);
        return response()->json(['data' => $this->flow->approve($version, $request->input('committee_meeting_id'))]);
    }

    public function pdf(string $version): Response
    {
        $this->scopeVersion($version);
        [$name, $bytes] = $this->export->pdf($version);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"{$name}\""]);
    }

    public function xlsx(string $version): Response
    {
        $this->scopeVersion($version);
        [$name, $bytes] = $this->export->xlsx($version);

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$name}\"",
        ]);
    }
}
