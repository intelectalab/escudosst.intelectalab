<?php

use App\Core\Support\SystemPulse;
use App\Core\Tenancy\DbContext;
use App\Models\User;
use App\Modules\SUS\Services\RenewalService;
use App\Modules\USR\Services\MfaService;
use App\Modules\USR\Services\SupportAccessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

/*
| Quita el segundo factor de un usuario (pérdida del celular, o en desarrollo para volver a probar el alta).
| En el siguiente ingreso, si su rol lo exige, se le pedirá configurarlo otra vez.
| Uso: php artisan escudo:mfa-reset correo@empresa.com
| Pendiente: en producción debe hacerlo soporte con autorización y quedar en la bitácora (AUD).
*/
Artisan::command('escudo:mfa-reset {email}', function (MfaService $mfa) {
    $found = DB::selectOne('select id from app_auth_lookup_user(?)', [$this->argument('email')]);

    if ($found === null) {
        $this->error('No existe un usuario con ese correo.');

        return 1;
    }

    DbContext::setUser($found->id);
    $mfa->disable(User::findOrFail($found->id));
    DB::update('update users set failed_login_count = 0, locked_until = null where id = ?', [$found->id]);
    DbContext::setUser(null);

    $this->info('Segundo factor quitado y cuenta desbloqueada para '.$this->argument('email').'.');

    return 0;
})->purpose('Quita la verificación en dos pasos de un usuario y lo desbloquea');

/*
| Cambia el correo de un usuario (en desarrollo: para que los códigos lleguen a tu Gmail real).
| Uso: php artisan escudo:user-email esencial@escudo.test tu.cuenta@gmail.com
| Pendiente: en producción el cambio de correo lo hace el propio usuario con verificación (USR).
*/
Artisan::command('escudo:user-email {actual} {nuevo}', function () {
    $nuevo = strtolower(trim($this->argument('nuevo')));

    if (! filter_var($nuevo, FILTER_VALIDATE_EMAIL)) {
        $this->error('El correo nuevo no es válido.');

        return 1;
    }

    $found = DB::selectOne('select id from app_auth_lookup_user(?)', [$this->argument('actual')]);

    if ($found === null) {
        $this->error('No existe un usuario con ese correo.');

        return 1;
    }

    if (DB::selectOne('select id from app_auth_lookup_user(?)', [$nuevo]) !== null) {
        $this->error('Ya existe otro usuario con '.$nuevo.'.');

        return 1;
    }

    DbContext::setUser($found->id);
    DB::update('update users set email = ? where id = ?', [$nuevo, $found->id]);
    DbContext::setUser(null);

    $this->info('Listo: ahora ingresa con '.$nuevo.' (misma contraseña).');

    return 0;
})->purpose('Cambia el correo de un usuario');

/*
| Vencimientos de suscripciones (SUS): avisos a 5, 3 y 0 días, pago pendiente con días de gracia y
| solo lectura al terminar la gracia. Corre solo todos los días; también se puede correr a mano.
| Uso: php artisan escudo:subscriptions-daily
*/
Artisan::command('escudo:subscriptions-daily', function (RenewalService $renewals) {
    $r = $renewals->run();
    SystemPulse::dailyRun($r);
    $this->info("Avisos: {$r['reminders']} · pago pendiente: {$r['past_due']} · pruebas vencidas: {$r['trial_expired']} · bloqueadas: {$r['blocked']}");
})->purpose('Avisos de vencimiento y bloqueo por falta de pago');

Schedule::command('escudo:subscriptions-daily')
    ->dailyAt(config('escudo.subscriptions.daily_at'))
    ->timezone('America/Lima')
    ->withoutOverlapping();

// Latido del programador de tareas: «Salud del sistema» avisa si deja de correr.
Schedule::call(fn () => SystemPulse::schedulerBeat())->everyMinute()->name('escudo:heartbeat');

// Accesos de soporte vencidos: se cierran solos (docs/05 §4.6).
Schedule::call(fn () => app(SupportAccessService::class)->expire())->everyFifteenMinutes()->name('escudo:support-access-expire');
