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

        // Conteo de correos enviados para «Salud del sistema».
        Event::listen(MessageSent::class, fn () => SystemPulse::mailSent());

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Límite de intentos de ingreso por correo + IP.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        // Segundo factor: por desafío (o usuario) + IP.
        RateLimiter::for('mfa', fn (Request $request) => Limit::perMinute(10)
            ->by(($request->input('mfa_challenge_id') ?: $request->user()?->id ?: 'anon').'|'.$request->ip()));

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
