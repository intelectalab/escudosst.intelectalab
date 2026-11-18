<?php

namespace App\Modules\USR\Support;

use Illuminate\Validation\Rules\Password;

/**
 * Política de contraseñas (docs/10 §Contraseñas): mínimo 12 caracteres, sin reglas de composición forzadas y verificada
 * contra listas de contraseñas filtradas (consulta por rango de huella: solo viajan los 5 primeros caracteres del SHA-1).
 */
final class PasswordPolicy
{
    public static function rule(): Password
    {
        $rule = Password::min(12);

        return config('escudo.password_breach_check', true) ? $rule->uncompromised() : $rule;
    }
}
