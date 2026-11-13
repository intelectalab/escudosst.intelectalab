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

        $this->checkDevice($user, $userAgent);
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

    /**
     * M-USR §10 «Nuevo inicio de sesión desde dispositivo desconocido»: cada equipo se reconoce por su navegador y su red
     * (huella sin datos personales). Al entrar desde uno nuevo, si ya había otros conocidos, se avisa por correo.
     */
    private function checkDevice(User $user, ?string $userAgent): void
    {
        try {
            $ip = (string) request()->ip();
            $net = str_contains($ip, ':') ? implode(':', array_slice(explode(':', $ip), 0, 4)) : implode('.', array_slice(explode('.', $ip), 0, 3));
            $print = hash('sha256', mb_substr((string) $userAgent, 0, 200).'|'.$net);
            $key = 'usr-devices:'.$user->id;
            $known = \Illuminate\Support\Facades\Cache::get($key, []);
            if (! in_array($print, $known, true)) {
                if ($known !== [] && $user->email) {
                    \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Modules\USR\AccountNoticeMail(explode(' ', (string) $user->full_name)[0],
                        'Nuevo inicio de sesión en tu cuenta', 'Se inició sesión en tu cuenta de EscudoSST desde un equipo o red que no habías usado, el '
                        .now('America/Lima')->format('d/m/Y \a \l\a\s H:i').'.'));
                }
                $known[] = $print;
                \Illuminate\Support\Facades\Cache::put($key, array_slice($known, -20), now()->addYear());
            }
        } catch (\Throwable) {
            // El aviso nunca impide iniciar sesión.
        }
    }
}
