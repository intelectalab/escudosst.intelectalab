<?php

namespace App\Providers;

use App\Core\Auth\PersonalAccessToken;
use App\Core\Support\SystemPulse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\IpUtils;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Migraciones de la base principal (docs/02-convenciones.md §6). Las de la base
        // médica se corren aparte con --database=medical --path=database/migrations/medical.
        $this->loadMigrationsFrom(database_path('migrations/main'));
        // M-CON: los correos llevan la marca blanca de la consultora de la empresa, si tiene (MailBrand::use antes de enviar).
        \Illuminate\Support\Facades\View::composer('mail.*', fn ($view) => $view->with('brand', \App\Modules\Consultora\MailBrand::current()));

        // Conteo de correos enviados para «Salud del sistema».
        Event::listen(MessageSent::class, fn () => SystemPulse::mailSent());

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Límite de intentos de ingreso por correo + IP.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        // Segundo factor: por desafío (o usuario) + IP.
        RateLimiter::for('mfa', fn (Request $request) => Limit::perMinute(10)
            ->by(($request->input('mfa_challenge_id') ?: $request->user()?->id ?: 'anon').'|'.$request->ip()));

        // M-CAP RN-CAP-14: consultas de verificación pública por IP y minuto (parámetro certificate_verify_rate_limit).
        // SCR-CAP-13: desde la página de una empresa (?empresa=), rige el límite que fijó esa empresa (o el global).
        RateLimiter::for('cert-verify', function (Request $request) {
            $company = (string) ($request->query('empresa') ?: $request->route('company') ?: '');
            if (\Illuminate\Support\Str::isUuid($company)) {
                $v = \App\Core\Support\LegalParams::forCompany($company, 'certificate_verify_rate_limit');

                return Limit::perMinute(max(1, is_numeric($v) ? (int) $v : 30))->by('cert-verify|'.$company.'|'.$request->ip());
            }

            return Limit::perMinute(max(1, (int) \App\Core\Support\LegalParams::number('certificate_verify_rate_limit', 30)))->by('cert-verify|'.$request->ip());
        });
        // M-FOT RN-FOT-06: consultas de verificación pública del fotocheck por IP y minuto (parámetro credential_verify_rate_limit).
        RateLimiter::for('cred-verify', fn (Request $request) => Limit::perMinute(max(1, (int) \App\Core\Support\LegalParams::number('credential_verify_rate_limit', 30)))
            ->by('cred-verify|'.$request->ip()));

        // Límites con nombre del sitio y del panel: cada uno con su propio contador por persona (usuario o IP real).
        // En producción (THROTTLE_TRUST_INTERNAL=true) no cuentan las llamadas de la red interna de Docker: son las
        // páginas que arma el servidor de Next para todos los visitantes, no una persona.
        foreach ([
            'sus-backups' => [3, 10],
            'web-chat' => [40, 1],
            'web-chat-lead' => [6, 10],
            'web-chat-start' => [10, 10],
            'web-crm-test' => [10, 10],
            'web-demo-manage' => [30, 10],
            'web-events' => [120, 1],
            'web-forms' => [6, 10],
            'web-google-callback' => [20, 10],
            'web-help' => [60, 1],
            'web-newsletter-test' => [10, 10],
            'web-quote-read' => [40, 10],
            'web-quote-write' => [8, 10],
            'web-read' => [120, 1],
            'web-sensitive' => [10, 10],
            'web-story-read' => [30, 10],
            'web-story-write' => [6, 10],
            'web-subprocessors-notify' => [3, 60],
        ] as $name => [$max, $minutes]) {
            RateLimiter::for($name, function (Request $request) use ($name, $max, $minutes) {
                if (config('escudo.throttle_trust_internal') && IpUtils::checkIp((string) $request->ip(), ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8', '::1'])) {
                    return Limit::none();
                }

                return Limit::perMinutes($minutes, $max)->by($name.'|'.($request->user()?->id ?: $request->ip()));
            });
        }
    }
}
