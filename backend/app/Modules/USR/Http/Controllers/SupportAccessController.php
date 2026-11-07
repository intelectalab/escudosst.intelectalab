<?php

namespace App\Modules\USR\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\USR\Services\SupportAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Accesos de soporte (docs/05 §4.6).
 *
 * Plataforma (/internal, equipo de EscudoSST):
 *  GET  /internal/support-access                 solicitudes y accesos, y empresas para pedir
 *  POST /internal/support-access                 { company_id, reason, ticket_reference?, hours }
 *  POST /internal/support-access/{id}/cancel     cancelar una solicitud sin responder
 *  POST /internal/support-access/{id}/revoke     terminar un acceso activo
 *
 * Empresa (con X-Company-Id; solo el administrador de cuenta):
 *  GET  /account/support-access
 *  POST /account/support-access/{id}/approve
 *  POST /account/support-access/{id}/reject      { reason? }
 *  POST /account/support-access/{id}/revoke
 */
class SupportAccessController extends Controller
{
    public function __construct(private readonly SupportAccessService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->service->platformList()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:10', 'max:300'],
            'ticket_reference' => ['nullable', 'string', 'max:40'],
            'hours' => ['required', 'integer', 'in:'.implode(',', SupportAccessService::DURATIONS)],
        ], [
            'company_id.required' => 'Elige una empresa.',
            'reason.required' => 'Explica para qué necesitas entrar.',
            'reason.min' => 'Detalla un poco más el motivo: el cliente lo verá para decidir.',
            'hours.in' => 'Elige una duración.',
        ]);

        return response()->json(['data' => $this->service->request($data['company_id'], $data['reason'], $data['ticket_reference'] ?? null, (int) $data['hours'], $request->user()->id)], 201);
    }

    public function cancel(Request $request, string $grant): Response
    {
        $this->service->cancel($grant, $request->user()->id);

        return response()->noContent();
    }

    public function revoke(Request $request, string $grant): Response
    {
        $this->service->revokeByPlatform($grant, $request->user()->id);

        return response()->noContent();
    }

    // ---------------------------------------------------------------- Empresa

    public function companyIndex(): JsonResponse
    {
        $context = $this->adminContext();

        return response()->json(['data' => $this->service->companyList($context->companyId), 'can_manage' => $context->roleCode === 'account_admin']);
    }

    public function approve(Request $request, string $grant): Response
    {
        $this->service->approve($this->adminContext(true)->companyId, $grant, $request->user()->id);

        return response()->noContent();
    }

    public function reject(Request $request, string $grant): Response
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);
        $this->service->reject($this->adminContext(true)->companyId, $grant, $request->user()->id, $data['reason'] ?? null);

        return response()->noContent();
    }

    public function companyRevoke(Request $request, string $grant): Response
    {
        $this->service->revokeByCompany($this->adminContext(true)->companyId, $grant, $request->user()->id);

        return response()->noContent();
    }

    /** Ver: administrador de cuenta y Jefe SST. Decidir: solo el administrador de cuenta (docs/05 §4.6). */
    private function adminContext(bool $decide = false): CompanyContext
    {
        $context = app(CompanyContext::class);
        $allowed = $decide ? ['account_admin'] : ['account_admin', 'sst_manager'];
        if (! in_array($context->roleCode, $allowed, true)) {
            throw ApiProblem::forbidden($decide
                ? 'Solo el administrador de cuenta puede autorizar o revocar accesos de soporte.'
                : 'No tienes permiso para ver los accesos de soporte.');
        }

        return $context;
    }
}
