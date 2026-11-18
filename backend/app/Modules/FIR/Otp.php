<?php

namespace App\Modules\FIR;

use App\Core\Http\ApiProblem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Código de un solo uso por correo (M-FIR, método OTP): 6 dígitos, vale fir_otp_minutes (10 min), se guarda solo su huella,
 * 5 intentos como máximo y se puede pedir otro cada minuto. Lo usan la firma de una solicitud y la aprobación de la política.
 */
final class Otp
{
    /** Envía el código al correo del usuario. Devuelve el correo enmascarado y los minutos de vigencia. */
    public static function send(string $key, string $userId, string $purpose): array
    {
        if (Cache::get($key.':sent')) {
            throw new ApiProblem(429, 'too_many_requests', 'Espera un momento', 'Ya te enviamos un código hace menos de un minuto: revisa tu correo.');
        }
        $code = (string) random_int(100000, 999999);
        $minutes = max(3, (int) config('escudo.fir_otp_minutes', 10));
        Cache::put($key, ['hash' => hash_hmac('sha256', $code, (string) config('app.key')), 'tries' => 0], now()->addMinutes($minutes));
        Cache::put($key.':sent', 1, now()->addMinute());
        $u = DB::selectOne('select full_name, email from users where id = ?', [$userId]);
        Mail::to($u->email)->send(new \App\Modules\WEB\Mail\SiteRequestMail(explode(' ', (string) $u->full_name)[0] ?: 'Hola',
            'Tu código para '.$purpose.': '.$code, "Usa este código para {$purpose} en EscudoSST. Vale {$minutes} minutos. Si no lo pediste, ignora este correo.",
            [['Código', $code]]));

        return ['sent_to' => preg_replace('/(?<=.).(?=[^@]*@)/', '•', (string) $u->email), 'minutes' => $minutes];
    }

    /** Valida el código; si no corresponde, 422 en el campo «otp». */
    public static function check(string $key, string $code): void
    {
        $s = Cache::get($key);
        $fail = fn (string $m) => throw new ApiProblem(422, 'validation_failed', 'Revisa el código', $m, ['errors' => ['otp' => [$m]]]);
        if (! $s) {
            $fail('El código venció o no se pidió: pide uno nuevo.');
        }
        if ($s['tries'] >= 5) {
            Cache::forget($key);
            $fail('Demasiados intentos: pide un código nuevo.');
        }
        if (! hash_equals($s['hash'], hash_hmac('sha256', preg_replace('/\D/', '', $code), (string) config('app.key')))) {
            $s['tries']++;
            Cache::put($key, $s, now()->addMinutes(10));
            $fail('El código no es correcto.');
        }
        Cache::forget($key);
    }
}
