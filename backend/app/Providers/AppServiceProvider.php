<?php

namespace App\Providers;

use App\Core\Auth\PersonalAccessToken;
use App\Core\Support\SystemPulse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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
    }
}
