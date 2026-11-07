<?php

namespace App\Modules\USR\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\DbContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\USR\Services\LoginAttempts;
use App\Modules\USR\Services\MfaService;
use App\Modules\USR\Services\SessionIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Segundo factor (docs/08-api.md §2):
 *
 *  POST /auth/mfa/setup           alta: devuelve el secreto y el enlace otpauth para el QR
 *  POST /auth/mfa/confirm         confirma el alta con el primer código; entrega los códigos de recuperación
 *  POST /auth/mfa/email           envía un código de 6 dígitos por correo (alternativa a la app)
 *  POST /auth/mfa/verify          ingreso con código TOTP, por correo o de recuperación; entrega el token
 *  POST /auth/mfa/recovery-codes  genera códigos nuevos (con sesión y código vigente)
 *  POST /auth/mfa/disable         desactiva (con sesión y código), solo si el rol no lo exige
 *
 * setup y confirm funcionan en dos modos: durante el ingreso (con mfa_challenge_id) o desde
 * «Mi cuenta» (con sesión).
 */
class MfaController extends Controller
{
    public function __construct(
        private readonly MfaService $mfa,
        private readonly LoginAttempts $attempts,
        private readonly SessionIssuer $sessions,
    ) {}

    public function setup(Request $request): JsonResponse
    {
        [$user, $challengeId, $challenge] = $this->resolve($request);

        if ($user->mfa_enabled) {
            throw new ApiProblem(409, 'conflict', 'La verificación en dos pasos ya está activa');
        }

        $secret = $this->mfa->newSecret();

        if ($challengeId) {
            $challenge['pending_secret'] = $secret;
            $this->mfa->updateChallenge($challengeId, $challenge);
        } else {
            Cache::put('mfa_pending:'.$user->id, $secret, now()->addMinutes(10));
        }

        return response()->json([
            'secret' => $secret,
            'otpauth_uri' => $this->mfa->otpauthUri($user, $secret),
            'issuer' => 'EscudoSST',
            'account' => $user->email,
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $code = $this->requireCode($request);
        [$user, $challengeId, $challenge] = $this->resolve($request);

        $secret = $challengeId ? $challenge['pending_secret'] : Cache::get('mfa_pending:'.$user->id);

        if (! $secret) {
            throw new ApiProblem(409, 'conflict', 'Primero escanea el código QR',
                'Vuelve a iniciar la configuración de la verificación en dos pasos.');
        }

        if (! $this->mfa->verifyTotp($user->id, $secret, $code)) {
            $this->failed($user, $challengeId, $challenge);
        }

        $recoveryCodes = $this->mfa->enable($user, $secret);
        Cache::forget('mfa_pending:'.$user->id);

        $body = ['recovery_codes' => $recoveryCodes];

        if ($challengeId) {
            $this->mfa->forgetChallenge($challengeId);
            $body += $this->sessions->issue($user->id, $request->userAgent());
        }

        return response()->json($body);
    }

    public function verify(Request $request): JsonResponse
    {
        $challengeId = (string) $request->input('mfa_challenge_id');
        $challenge = $this->mfa->challenge($challengeId);
        $user = $this->userFor($challenge['user_id']);

        $this->attempts->assertNotLocked($this->attempts->lockedUntil($user->id));

        $recovery = trim((string) $request->input('recovery_code'));
        $emailCode = trim((string) $request->input('email_code'));

        // El código por correo sirve aunque todavía no haya configurado la app.
        if ($emailCode === '' && ! $user->mfa_enabled) {
            throw new ApiProblem(409, 'conflict', 'Primero configura la verificación en dos pasos');
        }

        $ok = match (true) {
            $emailCode !== '' => $this->mfa->verifyEmailCode($challengeId, $challenge, $emailCode),
            $recovery !== '' => $this->mfa->consumeRecoveryCode($user, $recovery),
            default => $this->mfa->verifyUserTotp($user, $this->requireCode($request)),
        };

        if (! $ok) {
            $this->failed($user, $challengeId, $challenge);
        }

        $this->mfa->forgetChallenge($challengeId);

        return response()->json($this->sessions->issue($user->id, $request->userAgent()) + [
            'recovery_codes_left' => $user->mfa_enabled ? $this->mfa->remainingRecoveryCodes($user) : 0,
        ]);
    }

    /** POST /auth/mfa/email · envía un código de 6 dígitos al correo del usuario para este desafío. */
    public function sendEmail(Request $request): JsonResponse
    {
        $challengeId = (string) $request->input('mfa_challenge_id');
        $challenge = $this->mfa->challenge($challengeId);
        $user = $this->userFor($challenge['user_id']);

        $this->attempts->assertNotLocked($this->attempts->lockedUntil($user->id));
        $this->mfa->sendEmailCode($user, $challengeId, $challenge);

        return response()->json([
            'sent_to' => MfaService::maskEmail($user->email),
            'resend_in' => MfaService::EMAIL_RESEND_SECONDS,
        ], 202);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->mfa_enabled || ! $this->mfa->verifyUserTotp($user, $this->requireCode($request))) {
            throw $this->invalidCode();
        }

        return response()->json(['recovery_codes' => $this->mfa->regenerateRecoveryCodes($user)]);
    }

    public function disable(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($this->mfa->isRequired($user->id, $user->is_platform_staff)) {
            throw new ApiProblem(403, 'forbidden', 'No se puede desactivar',
                'Tu rol exige la verificación en dos pasos.');
        }

        if (! $user->mfa_enabled || ! $this->mfa->verifyUserTotp($user, $this->requireCode($request))) {
            throw $this->invalidCode();
        }

        $this->mfa->disable($user);

        return response()->json(['mfa_enabled' => false]);
    }

    /** Estado para «Mi cuenta». */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'mfa_enabled' => (bool) $user->mfa_enabled,
            'mfa_required' => $this->mfa->isRequired($user->id, $user->is_platform_staff),
            'recovery_codes_left' => $user->mfa_enabled ? $this->mfa->remainingRecoveryCodes($user) : 0,
        ]);
    }

    /**
     * Usuario del desafío de ingreso o, si no hay desafío, el de la sesión.
     *
     * @return array{0: User, 1: ?string, 2: ?array}
     */
    private function resolve(Request $request): array
    {
        $challengeId = $request->input('mfa_challenge_id');

        if ($challengeId) {
            $challenge = $this->mfa->challenge((string) $challengeId);
            $user = $this->userFor($challenge['user_id']);
            $this->attempts->assertNotLocked($this->attempts->lockedUntil($user->id));

            return [$user, (string) $challengeId, $challenge];
        }

        $user = Auth::guard('sanctum')->user() ?? throw ApiProblem::unauthenticated();

        return [$user, null, null];
    }

    private function userFor(string $userId): User
    {
        DbContext::setUser($userId);

        return User::findOrFail($userId);
    }

    /** Registra el intento fallido (cuenta para el bloqueo) y responde con error. */
    private function failed(User $user, ?string $challengeId, ?array $challenge): never
    {
        $this->attempts->registerFailure($user->id);

        if ($challengeId) {
            $this->mfa->registerChallengeFailure($challengeId, $challenge);
        }

        throw $this->invalidCode();
    }

    private function requireCode(Request $request): string
    {
        $code = (string) $request->input('code');

        if (! preg_match('/^\s*\d{3}\s?\d{3}\s*$/', $code)) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos ingresados', null,
                ['errors' => ['code' => ['Ingresa el código de 6 dígitos de tu app.']]]);
        }

        return $code;
    }

    private function invalidCode(): ApiProblem
    {
        return new ApiProblem(401, 'invalid_mfa_code', 'Código incorrecto',
            'El código no es válido o ya venció. Revisa la hora de tu celular e intenta con el código nuevo.');
    }
}
