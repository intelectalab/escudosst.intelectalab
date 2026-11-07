<?php

namespace App\Core\Auth;

use App\Core\Tenancy\DbContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Token de Sanctum con id UUID v7.
 *
 * La tabla users tiene RLS: solo deja ver al propio usuario (app.user_id).
 * Al encontrar un token válido fijamos app.user_id con su dueño para que
 * Sanctum pueda cargar el usuario.
 *
 * Inactividad: si el token no se usó en los últimos escudo.auth.idle_minutes, se borra y la sesión se cierra (la
 * persona vuelve a ingresar con contraseña y verificación). Así un navegador olvidado abierto no deja el panel abierto.
 */
class PersonalAccessToken extends SanctumToken
{
    use HasUuids;

    public static function findToken($token)
    {
        // El id es un UUID: un token con otro formato (por ejemplo «1|abc» inventado) es inválido sin consultar la
        // base; si no, PostgreSQL rechaza el valor y la respuesta sería un error 500 en lugar de 401.
        if (str_contains((string) $token, '|') && ! \Illuminate\Support\Str::isUuid(explode('|', (string) $token, 2)[0])) {
            return null;
        }
        $found = parent::findToken($token);

        $idle = (int) config('escudo.auth.idle_minutes');
        $lastUse = $found?->last_used_at ?? $found?->created_at;
        if ($found !== null && $idle > 0 && $lastUse !== null && $lastUse->lt(now()->subMinutes($idle))) {
            $found->delete();

            return null;
        }

        if ($found !== null) {
            DbContext::setUser($found->tokenable_id);
        }

        return $found;
    }
}
