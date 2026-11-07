<?php

namespace App\Modules\USR\Services;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\DbContext;
use App\Models\User;
use App\Modules\USR\Mail\MfaCodeMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Segundo factor con TOTP (app de autenticación) y códigos de recuperación
 * (docs/08-api.md §2, RN-USR-05).
 *
 * - El secreto se guarda cifrado con la llave de la aplicación (pendiente: gestor de secretos, DA-08).
 * - Los códigos de recuperación se guardan como hash SHA-256 y sirven una sola vez.
 * - Un mismo código TOTP no se acepta dos veces (protección contra repetición).
 * - El desafío de ingreso vive en caché unos minutos y admite pocos intentos.
 *
 * Alternativa: código de 6 dígitos por correo (email_otp), válido solo para ese desafío.
 * Pendiente: registro en sessions_mfa.
 */
class MfaService
{
    /** Alcanza para esperar el correo con el código. */
    private const CHALLENGE_TTL_MINUTES = 10;

    /** Espera mínima entre dos envíos del código por correo. */
    public const EMAIL_RESEND_SECONDS = 60;

    private const CHALLENGE_MAX_ATTEMPTS = 5;

    private const RECOVERY_CODES = 8;

    public function __construct(private readonly Google2FA $google2fa) {}

    /** RN-USR-05: obligatorio por el rol (en BD o en la configuración) o por ser personal de la plataforma. */
    public function isRequired(string $userId, bool $isPlatformStaff): bool
    {
        if ($isPlatformStaff) {
            return true;
        }

        DbContext::setUser($userId);
        $roles = DB::select(
            'select m.role_code, r.requires_mfa from app_my_memberships() m
               join roles r on r.code = m.role_code and r.account_id is null'
        );

        foreach ($roles as $role) {
            if ($role->requires_mfa || in_array($role->role_code, config('escudo.auth.mfa_required_roles'), true)) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------- Desafío de ingreso

    /** @return array{id: string, expires_in: int} */
    public function createChallenge(string $userId): array
    {
        $id = (string) Str::uuid7();
        Cache::put($this->challengeKey($id), ['user_id' => $userId, 'attempts' => 0, 'pending_secret' => null],
            now()->addMinutes(self::CHALLENGE_TTL_MINUTES));

        return ['id' => $id, 'expires_in' => self::CHALLENGE_TTL_MINUTES * 60];
    }

    /** @return array{user_id: string, attempts: int, pending_secret: ?string} */
    public function challenge(?string $challengeId): array
    {
        $data = $challengeId && Str::isUuid($challengeId) ? Cache::get($this->challengeKey($challengeId)) : null;

        if ($data === null) {
            throw new ApiProblem(401, 'mfa_challenge_expired', 'La verificación venció',
                'Vuelve a ingresar tu correo y contraseña.');
        }

        return $data;
    }

    public function updateChallenge(string $challengeId, array $data): void
    {
        Cache::put($this->challengeKey($challengeId), $data, now()->addMinutes(self::CHALLENGE_TTL_MINUTES));
    }

    public function registerChallengeFailure(string $challengeId, array $data): void
    {
        $data['attempts']++;

        if ($data['attempts'] >= self::CHALLENGE_MAX_ATTEMPTS) {
            $this->forgetChallenge($challengeId);
        } else {
            $this->updateChallenge($challengeId, $data);
        }
    }

    public function forgetChallenge(string $challengeId): void
    {
        Cache::forget($this->challengeKey($challengeId));
    }

    // ---------------------------------------------------------------- Código por correo (email_otp)

    /**
     * Genera un código de 6 dígitos para el desafío, guarda solo su hash y lo envía por correo.
     * Sirve como alternativa a la app (por ejemplo, si el celular no está a la mano).
     */
    public function sendEmailCode(User $user, string $challengeId, array $challenge): void
    {
        $sentAt = $challenge['email_sent_at'] ?? null;

        if ($sentAt !== null && now()->timestamp - $sentAt < self::EMAIL_RESEND_SECONDS) {
            throw new ApiProblem(429, 'rate_limited', 'Espera un momento',
                'Ya te enviamos un código. Puedes pedir otro en un minuto.');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $challenge['email_code_hash'] = hash('sha256', $challengeId.'|'.$code);
        $challenge['email_sent_at'] = now()->timestamp;
        $this->updateChallenge($challengeId, $challenge);

        Mail::to($user->email, $user->full_name)->send(new MfaCodeMail($user->full_name, $code, self::CHALLENGE_TTL_MINUTES));
    }

    public function verifyEmailCode(string $challengeId, array $challenge, string $code): bool
    {
        $hash = $challenge['email_code_hash'] ?? null;
        $code = preg_replace('/\D/', '', $code);

        return $hash !== null && strlen($code) === 6 && hash_equals($hash, hash('sha256', $challengeId.'|'.$code));
    }

    /** a***@empresa.com: para decir a dónde se envió sin mostrar el correo completo. */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).str_repeat('*', max(3, mb_strlen($local) - 1)).'@'.$domain;
    }

    // ---------------------------------------------------------------- Alta del segundo factor

    public function newSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function otpauthUri(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl('EscudoSST', $user->email, $secret);
    }

    /**
     * Activa el segundo factor con el secreto ya comprobado y devuelve los códigos de recuperación en claro
     * (se muestran una sola vez).
     *
     * @return list<string>
     */
    public function enable(User $user, string $secret): array
    {
        $codes = $this->makeRecoveryCodes();

        DbContext::setUser($user->id);
        DB::update('update users set mfa_secret = ?, mfa_recovery_codes = ?, mfa_enabled = true where id = ?', [
            Crypt::encryptString($secret),
            Crypt::encryptString(json_encode(array_map($this->hashCode(...), $codes))),
            $user->id,
        ]);

        return $codes;
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->makeRecoveryCodes();

        DbContext::setUser($user->id);
        DB::update('update users set mfa_recovery_codes = ? where id = ?', [
            Crypt::encryptString(json_encode(array_map($this->hashCode(...), $codes))),
            $user->id,
        ]);

        return $codes;
    }

    public function disable(User $user): void
    {
        DbContext::setUser($user->id);
        DB::update('update users set mfa_secret = null, mfa_recovery_codes = null, mfa_enabled = false where id = ?', [$user->id]);
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count($this->storedRecoveryHashes($user));
    }

    // ---------------------------------------------------------------- Verificación

    /** Comprueba un código TOTP contra un secreto en claro, sin aceptar el mismo código dos veces. */
    public function verifyTotp(string $userId, string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code);

        if (strlen($code) !== 6) {
            return false;
        }

        $lastKey = 'mfa_last_ts:'.$userId;
        // Con un timestamp anterior (0 si no hay) la librería devuelve el paso de tiempo usado.
        $timestamp = $this->google2fa->verifyKeyNewer($secret, $code, (int) Cache::get($lastKey, 0), 1);

        if ($timestamp === false) {
            return false;
        }

        Cache::put($lastKey, $timestamp, now()->addMinutes(3));

        return true;
    }

    public function verifyUserTotp(User $user, string $code): bool
    {
        $secret = $this->storedSecret($user);

        return $secret !== null && $this->verifyTotp($user->id, $secret, $code);
    }

    /** Usa (y elimina) un código de recuperación. */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $hash = $this->hashCode($code);
        $hashes = $this->storedRecoveryHashes($user);

        if (! in_array($hash, $hashes, true)) {
            return false;
        }

        $remaining = array_values(array_filter($hashes, fn ($h) => ! hash_equals($h, $hash)));
        DbContext::setUser($user->id);
        DB::update('update users set mfa_recovery_codes = ? where id = ?', [
            Crypt::encryptString(json_encode($remaining)), $user->id,
        ]);

        return true;
    }

    // ---------------------------------------------------------------- Internos

    private function storedSecret(User $user): ?string
    {
        $raw = DB::selectOne('select mfa_secret from users where id = ?', [$user->id])?->mfa_secret;

        return $raw ? Crypt::decryptString($raw) : null;
    }

    /** @return list<string> */
    private function storedRecoveryHashes(User $user): array
    {
        $raw = DB::selectOne('select mfa_recovery_codes from users where id = ?', [$user->id])?->mfa_recovery_codes;

        return $raw ? json_decode(Crypt::decryptString($raw), true) : [];
    }

    /** @return list<string> Formato XXXX-XXXX, sin caracteres que se confunden (0/O, 1/I). */
    private function makeRecoveryCodes(): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $chars = '';
            for ($j = 0; $j < 8; $j++) {
                $chars .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($chars, 0, 4).'-'.substr($chars, 4);
        }

        return $codes;
    }

    private function hashCode(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)));
    }

    private function challengeKey(string $id): string
    {
        return 'mfa_challenge:'.$id;
    }
}
