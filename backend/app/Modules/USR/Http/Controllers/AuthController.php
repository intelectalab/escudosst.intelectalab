<?php

namespace App\Modules\USR\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\REC\Rules\WorkerRules;
use App\Modules\USR\Http\Requests\LoginRequest;
use App\Modules\USR\Services\LoginAttempts;
use App\Modules\USR\Services\MfaService;
use App\Modules\USR\Services\SessionIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Ingreso y salida (docs/08-api.md §2, SCR-USR-01).
 * Pendiente en otras historias: recuperación de contraseña, ingreso del trabajador con PIN.
 */
class AuthController extends Controller
{
    /** Hash ficticio para que un correo inexistente tarde lo mismo que uno real. */
    private const DUMMY_HASH = '$2y$12$kbOcrxqHvSMu430p6uK/8eTqrNpgScQ4s3VpsWqOfppFYFogMA8I.';

    public function __construct(
        private readonly LoginAttempts $attempts,
        private readonly MfaService $mfa,
        private readonly SessionIssuer $sessions,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $password = $request->validated('password');
        $dni = $request->dni();

        // SECURITY DEFINER: busca por correo, o por DNI del trabajador vinculado, sin abrir la tabla users (RLS).
        // Con DNI solo vale una coincidencia: si hay varias o ninguna, la respuesta es la misma que sin usuario.
        if ($dni !== null) {
            $matches = DB::select("select * from app_auth_lookup_user_by_document(decode(?, 'hex'))", [WorkerRules::documentHash('DNI', $dni)]);
            $found = count($matches) === 1 ? $matches[0] : null;
        } else {
            $found = DB::selectOne('select * from app_auth_lookup_user(?)', [$request->validated('email')]);
        }

        if ($found === null) {
            Hash::check($password, self::DUMMY_HASH);
            throw $this->invalidCredentials();
        }

        $this->attempts->assertNotLocked($found->locked_until);

        if ($found->status !== 'active' || $found->password_hash === null
            || ! Hash::check($password, $found->password_hash)) {
            $this->attempts->registerFailure($found->id);
            throw $this->invalidCredentials();
        }

        $mfaEnabled = (bool) $found->mfa_enabled;

        // RN-USR-05: con MFA activo, o si el rol lo exige, el token se entrega después del segundo factor.
        if ($mfaEnabled || $this->mfa->isRequired($found->id, (bool) $found->is_platform_staff)) {
            $challenge = $this->mfa->createChallenge($found->id);

            return response()->json([
                'mfa_required' => true,
                'mfa_setup_required' => ! $mfaEnabled,
                'mfa_challenge_id' => $challenge['id'],
                'expires_in' => $challenge['expires_in'],
            ]);
        }

        return response()->json($this->sessions->issue($found->id, $request->userAgent()));
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    private function invalidCredentials(): ApiProblem
    {
        // Mismo mensaje exista o no el correo o el DNI.
        return new ApiProblem(401, 'invalid_credentials', 'No pudimos iniciar sesión',
            'El correo o DNI, o la contraseña, no son correctos.');
    }
}
