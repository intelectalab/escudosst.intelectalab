<?php

namespace App\Modules\USR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\USR\Services\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * Aceptar una invitación (sin sesión).
 *
 *  GET  /auth/invitations/{token}          a quién y a qué empresa invita
 *  POST /auth/invitations/{token}/accept   crea la contraseña y activa el acceso
 */
class InvitationController extends Controller
{
    public function __construct(private readonly InvitationService $service) {}

    public function show(string $token): JsonResponse
    {
        return response()->json(['data' => $this->service->preview($token)]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'confirmed', 'max:128', Password::min(10)->letters()->numbers()],
        ], [
            'password.required' => 'Escribe una contraseña.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'Usa al menos 10 caracteres.',
            'password.letters' => 'Incluye al menos una letra.',
            'password.numbers' => 'Incluye al menos un número.',
        ]);

        return response()->json(['data' => ['email' => $this->service->accept($token, $data['password'])]]);
    }
}
