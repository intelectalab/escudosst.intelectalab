<?php

namespace App\Modules\SUS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Panel interno · Cuentas (SCR-SUS-02 y SCR-SUS-03; permisos sus.subscriptions.*).
 *
 *  GET  /internal/accounts                lista con empresas y suscripción vigente
 *  POST /internal/accounts                alta: cuenta + empresa + suscripción + administrador invitado
 *  GET  /internal/accounts/{id}           detalle con complementos, módulos, administradores y línea de tiempo
 *  POST /internal/accounts/{id}/companies/{company}/admins/{user}/resend   reenviar invitación
 */
class AccountsController extends Controller
{
    public function __construct(private readonly AccountsService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->service->list()]);
    }

    public function show(string $account): JsonResponse
    {
        return response()->json(['data' => $this->service->show($account)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_name' => ['nullable', 'string', 'max:120'],
            'account_type' => ['required', 'in:direct,group'],
            'ruc' => ['required', 'digits:11'],
            'legal_name' => ['required', 'string', 'min:3', 'max:160'],
            'trade_name' => ['nullable', 'string', 'max:120'],
            'economic_sector' => ['nullable', 'in:construction,mining,agroindustry,manufacturing,retail,services,office,health,education,transport,energy,other'],
            'headcount' => ['required', 'integer', 'min:1', 'max:1000000'],
            'start' => ['required', 'in:trial,active'],
            'plan' => ['required_if:start,active', 'nullable', 'in:E,P,C'],
            'billing_period' => ['required', 'in:monthly,annual'],
            'billing_email' => ['nullable', 'email:rfc', 'max:160'],
            'billing_phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:500'],
            'admin_name' => ['required', 'string', 'min:3', 'max:120'],
            'admin_email' => ['required', 'email:rfc', 'max:160'],
        ], [
            'account_type.required' => 'Elige el tipo de cuenta.',
            'ruc.required' => 'Escribe el RUC.',
            'ruc.digits' => 'El RUC tiene 11 dígitos.',
            'legal_name.required' => 'Escribe la razón social.',
            'legal_name.min' => 'La razón social es muy corta.',
            'headcount.required' => 'Indica cuántos trabajadores tiene.',
            'headcount.integer' => 'El número de trabajadores debe ser un número entero.',
            'headcount.min' => 'Debe tener al menos 1 trabajador.',
            'plan.required_if' => 'Elige el plan contratado.',
            'billing_email.email' => 'El correo de facturación no es válido.',
            'admin_name.required' => 'Escribe el nombre del administrador.',
            'admin_name.min' => 'El nombre es muy corto.',
            'admin_email.required' => 'Escribe el correo del administrador.',
            'admin_email.email' => 'El correo del administrador no es válido.',
        ]);

        return response()->json(['data' => $this->service->create($data, $request->user()->id)], 201);
    }

    public function resendInvitation(Request $request, string $account, string $company, string $user): Response
    {
        $this->service->resendInvitation($account, $company, $user, $request->user()->id);

        return response()->noContent();
    }
}
