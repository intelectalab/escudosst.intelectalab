<?php

namespace App\Modules\FIR;

use App\Core\Http\ApiProblem;
use App\Modules\NOT\WhatsAppCloud;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Código de un solo uso (M-FIR, método OTP «por correo/WhatsApp», §7.3): 6 dígitos, vale fir_otp_minutes (10 min), se guarda
 * solo su huella, 5 intentos como máximo y se puede pedir otro cada minuto. Va por WhatsApp si el WhatsApp de la plataforma
 * está en vivo con la plantilla «template_otp» y la persona tiene celular y aceptó el canal (whatsapp_opt_in del trabajador
 * vinculado, sin desactivarlo en sus preferencias); si no, o si el envío falla, va al correo. El canal queda en otp_channel.
 * Lo usan la firma de una solicitud y la aprobación de la política.
 */
final class Otp
{
    /** Envía el código por WhatsApp o al correo. Devuelve el canal, el destino enmascarado y los minutos de vigencia. */
    public static function send(string $key, string $userId, string $purpose): array
    {
        if (Cache::get($key.':sent')) {
            throw new ApiProblem(429, 'too_many_requests', 'Espera un momento', 'Ya te enviamos un código hace menos de un minuto: revísalo.');
        }
        $code = (string) random_int(100000, 999999);
        $minutes = max(3, (int) config('escudo.fir_otp_minutes', 10));
        $channel = 'email';
        $sentTo = null;
        $wa = new WhatsAppCloud;
        if ($wa->ready() && ($tpl = $wa->template('otp')) && ($phone = self::whatsappPhone($userId))) {
            if ($wa->sendTemplate($phone, $tpl, [$code])['ok']) {
                $channel = 'whatsapp';
                $sentTo = 'WhatsApp •••••'.substr($phone, -3);
            }
        }
        Cache::put($key, ['hash' => hash_hmac('sha256', $code, (string) config('app.key')), 'tries' => 0, 'channel' => $channel], now()->addMinutes($minutes));
        Cache::put($key.':sent', 1, now()->addMinute());
        if ($channel === 'email') {
            $u = DB::selectOne('select full_name, email from users where id = ?', [$userId]);
            Mail::to($u->email)->send(new \App\Modules\WEB\Mail\SiteRequestMail(explode(' ', (string) $u->full_name)[0] ?: 'Hola',
                'Tu código para '.$purpose.': '.$code, "Usa este código para {$purpose} en EscudoSST. Vale {$minutes} minutos. Si no lo pediste, ignora este correo.",
                [['Código', $code]]));
            $sentTo = preg_replace('/(?<=.).(?=[^@]*@)/', '•', (string) $u->email);
        }

        return ['channel' => $channel, 'sent_to' => $sentTo, 'minutes' => $minutes];
    }

    /** Celular de WhatsApp del usuario: el del trabajador vinculado en esta empresa, si aceptó el canal y no lo desactivó. */
    private static function whatsappPhone(string $userId): ?string
    {
        $company = app()->bound(\App\Core\Tenancy\CompanyContext::class) ? app(\App\Core\Tenancy\CompanyContext::class)->companyId : null;
        if (! $company || DB::selectOne("select 1 as ok from user_notification_prefs where company_id = ? and user_id = ? and channel = 'whatsapp' and not enabled
                and deleted_at is null and event_category in ('*', 'fir')", [$company, $userId])) {
            return null;
        }
        $w = DB::selectOne(
            "select w.phone_e164 from workers w
              where w.deleted_at is null and w.status <> 'terminated' and w.whatsapp_opt_in and w.phone_e164 is not null and (w.user_id = ?
                 or w.id in (select m.worker_id from memberships m where m.user_id = ? and m.company_id = ? and m.status = 'active' and m.deleted_at is null))
              limit 1", [$userId, $userId, $company]);
        if (! $w) {
            return null;
        }
        try {
            $phone = Crypt::decryptString($w->phone_e164);
        } catch (\Throwable) {
            $phone = $w->phone_e164;
        }

        return WhatsAppCloud::normalize($phone);
    }

    /** Valida el código y devuelve el canal por el que se envió («email» o «whatsapp»); si no corresponde, 422 en el campo «otp». */
    public static function check(string $key, string $code): string
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

        return $s['channel'] ?? 'email';
    }
}
