<?php

namespace App\Modules\USR\Services;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\DbContext;
use Illuminate\Support\Facades\DB;

/**
 * Intentos fallidos y bloqueo temporal (RN-USR-06). Cuentan tanto la contraseña
 * como el código del segundo factor.
 */
class LoginAttempts
{
    public function assertNotLocked(?string $lockedUntil): void
    {
        if ($lockedUntil !== null && now()->lt($lockedUntil)) {
            throw new ApiProblem(401, 'account_locked', 'Cuenta bloqueada temporalmente',
                'Hubo demasiados intentos fallidos. Vuelve a intentarlo en unos minutos.');
        }
    }

    public function registerFailure(string $userId): void
    {
        DbContext::setUser($userId);

        $max = (int) config('escudo.auth.max_failed_logins');
        $u = DB::selectOne(
            'update users
                set failed_login_count = failed_login_count + 1,
                    locked_until = case when failed_login_count + 1 >= ? then now() + make_interval(mins => ?) else locked_until end
              where id = ? returning failed_login_count, full_name, email',
            [$max, (int) config('escudo.auth.lock_minutes'), $userId]
        );
        // M-USR §10: al quedar bloqueada, se avisa al usuario por correo (una vez por bloqueo).
        if ($u && (int) $u->failed_login_count === $max && $u->email) {
            try {
                \Illuminate\Support\Facades\Mail::to($u->email)->send(new \App\Modules\USR\AccountNoticeMail(explode(' ', (string) $u->full_name)[0], 'Tu cuenta se bloqueó por unos minutos',
                    'Hubo '.$max.' intentos fallidos de iniciar sesión con tu cuenta. Por seguridad la bloqueamos '.(int) config('escudo.auth.lock_minutes').' minutos.'));
            } catch (\Throwable) {
            }
        }
    }

    /** Fecha de bloqueo actual del usuario (el usuario ya debe estar en el contexto). */
    public function lockedUntil(string $userId): ?string
    {
        DbContext::setUser($userId);

        return DB::selectOne('select locked_until from users where id = ?', [$userId])?->locked_until;
    }
}
