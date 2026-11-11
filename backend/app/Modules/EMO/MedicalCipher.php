<?php

namespace App\Modules\EMO;

use App\Core\Http\ApiProblem;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;

/**
 * Cifrado de aplicación con la llave médica (docs/10 §5): hallazgos, diagnósticos, recomendaciones, nombres y contenido
 * de los archivos clínicos se guardan cifrados en la base médica / disco médico. La llave (MEDICAL_FIELD_KEY) es distinta
 * de APP_KEY y de las de respaldo. Fuera de producción, si falta, se deriva una de APP_KEY para poder desarrollar y probar.
 */
final class MedicalCipher
{
    private static ?Encrypter $enc = null;

    public static function encrypter(): Encrypter
    {
        if (self::$enc) {
            return self::$enc;
        }
        $raw = (string) config('escudo.medical.field_key');
        $key = Str::startsWith($raw, 'base64:') ? base64_decode(substr($raw, 7), true) : null;
        if (! $key || strlen($key) !== 32) {
            if (app()->environment('production')) {
                throw new ApiProblem(503, 'medical_key_missing', 'Datos médicos no disponibles', 'Falta configurar la llave de datos médicos del servidor. Avisa al equipo de EscudoSST.');
            }
            $key = hash('sha256', 'escudo-medical-dev|'.config('app.key'), true);
        }

        return self::$enc = new Encrypter($key, 'aes-256-gcm');
    }

    public static function encrypt(?string $v): ?string
    {
        return $v === null || $v === '' ? null : self::encrypter()->encryptString($v);
    }

    public static function decrypt(?string $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        try {
            return self::encrypter()->decryptString($v);
        } catch (\Throwable) {
            return null;   // dato ilegible con esta llave: no se muestra
        }
    }
}
