<?php

namespace App\Modules\USR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\USR\Services\AccountSecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rules\Password;

/**
 * Mi cuenta (cualquier usuario), «Olvidé mi contraseña» y equipo de la plataforma.
 *  Con sesión:  PATCH /me/profile, POST /me/password, POST /me/email
 *  Sin sesión:  POST /auth/password/forgot, GET /auth/links/{token}, POST /auth/links/{token}/password,
 *               POST /auth/email/confirm/{token}
 *  Dueño:       GET/POST /internal/team, PATCH /internal/team/{id}, POST /internal/team/{id}/{resend|disable|enable}
 */
class AccountSecurityController extends Controller
{
    private const MESSAGES = [
        'required' => 'Completa este campo.',
        'email' => 'Escribe un correo válido.',
        'max' => 'Es muy largo.',
        'min' => 'Es muy corto.',
        'in' => 'Elige una opción de la lista.',
        'password.confirmed' => 'Las contraseñas no coinciden.',
        'password.min' => 'Usa al menos 10 caracteres.',
        'password.letters' => 'Incluye al menos una letra.',
        'password.numbers' => 'Incluye al menos un número.',
    ];

    public function __construct(private AccountSecurityService $svc) {}

    private function passwordRule(): array
    {
        return ['required', 'string', 'confirmed', 'max:128', Password::min(10)->letters()->numbers()];
    }

    // ---------------------------------------------------------------- Mi cuenta

    public function profile(Request $request): Response
    {
        $d = $request->validate(['full_name' => ['required', 'string', 'min:3', 'max:120']], self::MESSAGES);
        $this->svc->updateName($request->user()->id, $d['full_name']);

        return response()->noContent();
    }

    public function password(Request $request): Response
    {
        $d = $request->validate(['current_password' => ['required', 'string'], 'password' => $this->passwordRule()], self::MESSAGES);
        $this->svc->changePassword($request->user(), $d['current_password'], $d['password'], (string) $request->user()->currentAccessToken()?->id ?: null);

        return response()->noContent();
    }

    public function email(Request $request): Response
    {
        $d = $request->validate(['email' => ['required', 'email', 'max:160'], 'current_password' => ['required', 'string']], self::MESSAGES);
        $this->svc->requestEmailChange($request->user(), $d['email'], $d['current_password']);

        return response()->noContent();
    }

    // ---------------------------------------------------------------- Sin sesión

    public function forgot(Request $request): JsonResponse
    {
        $d = $request->validate(['email' => ['required', 'email', 'max:160']], self::MESSAGES);
        $this->svc->requestReset($d['email']);

        // Misma respuesta exista o no el correo.
        return response()->json(['data' => ['ok' => true]], 202);
    }

    public function link(string $token): JsonResponse
    {
        return response()->json(['data' => $this->svc->preview($token)]);
    }

    public function setPassword(Request $request, string $token): JsonResponse
    {
        $d = $request->validate(['password' => $this->passwordRule()], self::MESSAGES);

        return response()->json(['data' => ['email' => $this->svc->setPassword($token, $d['password'])]]);
    }

    public function confirmEmail(string $token): JsonResponse
    {
        return response()->json(['data' => ['email' => $this->svc->confirmEmail($token)]]);
    }

    // ---------------------------------------------------------------- Equipo (solo Dueño)

    public function team(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->svc->team(), 'me' => $request->user()->id, 'roles' => AccountSecurityService::ROLES]);
    }

    public function invite(Request $request): JsonResponse
    {
        $d = $request->validate([
            'full_name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'email', 'max:160'],
            'platform_role' => ['required', 'in:owner,support'],
        ], self::MESSAGES);

        return response()->json(['data' => ['id' => $this->svc->invite($request->user()->id, $d['full_name'], $d['email'], $d['platform_role'])]], 201);
    }

    public function update(Request $request, string $id): Response
    {
        $d = $request->validate(['platform_role' => ['required', 'in:owner,support']], self::MESSAGES);
        $this->svc->setRole($request->user()->id, $id, $d['platform_role']);

        return response()->noContent();
    }

    public function action(Request $request, string $id, string $action): Response
    {
        match ($action) {
            'resend' => $this->svc->resendInvite($request->user()->id, $id),
            'disable' => $this->svc->setActive($request->user()->id, $id, false),
            'enable' => $this->svc->setActive($request->user()->id, $id, true),
        };

        return response()->noContent();
    }
}
