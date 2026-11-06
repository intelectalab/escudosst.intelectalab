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
 */
class PersonalAccessToken extends SanctumToken
{
    use HasUuids;

    public static function findToken($token)
    {
        $found = parent::findToken($token);

        if ($found !== null) {
            DbContext::setUser($found->tokenable_id);
        }

        return $found;
    }
}
