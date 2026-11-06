<?php

namespace App\Modules\USR\Services;

use App\Core\Tenancy\DbContext;
use App\Models\User;
use App\Modules\USR\Http\Resources\UserResource;
use Illuminate\Support\Facades\DB;

/**
 * Cierra un ingreso correcto: reinicia los intentos fallidos, registra el último acceso
 * y emite el token de Sanctum.
 */
class SessionIssuer
{
    /** @return array<string, mixed> */
    public function issue(string $userId, ?string $userAgent): array
    {
        DbContext::setUser($userId);

        $user = DB::transaction(function () use ($userId) {
            DB::update('update users set last_login_at = now(), failed_login_count = 0, locked_until = null where id = ?', [$userId]);

            return User::findOrFail($userId);
        });

        $token = $user->createToken(
            name: substr((string) $userAgent, 0, 100) ?: 'web',
            expiresAt: now()->addMinutes((int) config('escudo.auth.token_ttl_minutes')),
        );

        return [
            'mfa_required' => false,
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => new UserResource($user),
        ];
    }
}
