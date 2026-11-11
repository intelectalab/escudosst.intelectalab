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
/*
| Primer Dueño de la plataforma en producción (la carga inicial de producción no trae usuarios).
| Uso: php artisan escudo:create-owner tu.correo@empresa.com "Tu nombre"
| No pide contraseña: llega un correo con el enlace para crearla (y luego el código de verificación al ingresar).
*/
Artisan::command('escudo:create-owner {email} {name}', function (\App\Modules\USR\Services\AccountSecurityService $accounts) {
    $owners = (int) DB::connection('platform')->selectOne("select count(*) as n from users where platform_role = 'owner' and deleted_at is null")->n;
    if ($owners > 0 && ! $this->confirm("Ya hay {$owners} Dueño(s) de la plataforma. ¿Crear otro?")) {
        return 1;
    }
    try {
        $accounts->invite(null, (string) $this->argument('name'), (string) $this->argument('email'), 'owner');
    } catch (\Throwable $e) {
        $this->error('No se pudo: '.$e->getMessage());

        return 1;
    }
    $this->info('Listo. Revisa el correo '.$this->argument('email').': trae el enlace para crear la contraseña.');

    return 0;
})->purpose('Crea el primer Dueño de la plataforma y le envía el enlace para crear su contraseña');

/*
| Sesión para las pruebas de interfaz automáticas (frontend/e2e): crea un token de Sanctum «e2e-…» que vence en 2 horas
| sin pasar por la verificación en dos pasos. Solo en desarrollo o pruebas (APP_ENV local o testing); en cualquier otro
| entorno se niega. Imprime una línea JSON {token, company_id}. Las pruebas borran sus tokens al terminar.
| Uso: php artisan escudo:e2e-token corporativo@escudo.test --company=<uuid>
*/
Artisan::command('escudo:e2e-token {email} {--company=}', function () {
    if (! app()->environment(['local', 'testing'])) {
        $this->error('Solo se puede usar en desarrollo o pruebas (APP_ENV local o testing).');

        return 1;
    }
    $found = DB::selectOne('select id from app_auth_lookup_user(?)', [$this->argument('email')]);
    if ($found === null) {
        $this->error('No existe un usuario con ese correo.');

        return 1;
    }

    DbContext::setUser($found->id);
    try {
        $user = User::findOrFail($found->id);
        $companies = array_map(fn ($r) => $r->company_id, DB::select('select company_id from app_my_memberships() order by company_name'));
        $company = $this->option('company') ?: ($companies[0] ?? null);
        if ($company !== null && ! in_array($company, $companies, true)) {
            $this->error('El usuario no tiene acceso activo a esa empresa.');

            return 1;
        }
        $token = $user->createToken(name: 'e2e-'.now()->format('YmdHis'), expiresAt: now()->addHours(2));
        $token->accessToken->forceFill(['last_used_at' => now()])->save();
    } finally {
        DbContext::setUser(null);
    }

    $this->line(json_encode(['token' => $token->plainTextToken, 'company_id' => $company]));

    return 0;
})->purpose('Solo desarrollo: crea un token de 2 horas para las pruebas de interfaz automáticas');

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
    ->withoutOverlapping()
    ->runInBackground();

// Latido del programador de tareas: «Salud del sistema» avisa si deja de correr.
Schedule::call(fn () => SystemPulse::schedulerBeat())->everyMinute()->name('escudo:heartbeat');

// Accesos de soporte vencidos: se cierran solos (docs/05 §4.6).
Schedule::call(fn () => app(SupportAccessService::class)->expire())->everyFifteenMinutes()->name('escudo:support-access-expire');

/*
| Copias de seguridad lógicas cifradas (docs/15 §9): base principal y médica, todos los días, y limpieza por retención.
| Uso: php artisan escudo:backup [--target=main|medical]
|      php artisan escudo:backup:decrypt archivo.dump.enc salida.dump --target=main   (para restaurar con pg_restore)
*/
Artisan::command('escudo:backup {--target=all : main, medical o all}', function (\App\Modules\SUS\Services\BackupService $backups) {
    $target = (string) $this->option('target');
    $targets = $target === 'all' ? array_keys(\App\Modules\SUS\Services\BackupService::TARGETS) : [$target];
    foreach ($targets as $t) {
        $id = $backups->run($t, 'schedule');
        $run = \Illuminate\Support\Facades\DB::connection('platform')->selectOne('select status, size_bytes, error, remote_status, remote_error from backup_runs where id = ?', [$id]);
        $run->status === 'ok' ? $this->info("{$t}: copia lista (".number_format($run->size_bytes / 1024, 1).' KB)') : $this->error("{$t}: falló — {$run->error}");
        match ($run->remote_status) {
            'ok' => $this->info("{$t}: copia externa subida"),
            'failed' => $this->error("{$t}: copia externa falló — {$run->remote_error}"),
            'skipped' => $this->line("{$t}: copia externa sin configurar (BACKUP_S3_*)"),
            default => null,
        };
    }
    if ($target === 'all') {
        $this->info('Copias vencidas borradas: '.$backups->prune());
    }
})->purpose('Copia de seguridad cifrada de las bases de datos');

Artisan::command('escudo:backup:decrypt {input} {output} {--target=main}', function (\App\Modules\SUS\Services\BackupService $backups) {
    $backups->decrypt($this->argument('input'), $this->argument('output'), (string) $this->option('target'));
    $this->info('Descifrado. Restaura con: pg_restore --clean --if-exists -d <base> '.$this->argument('output'));
})->purpose('Descifra un archivo de copia de seguridad para restaurarlo');

Schedule::command('escudo:backup')
    ->dailyAt(config('escudo.backups.daily_at'))
    ->timezone('America/Lima')
    ->withoutOverlapping()
    ->runInBackground()   // la copia puede tardar más de un minuto: no detiene al programador
    ->name('escudo:backup');


Artisan::command('escudo:newsletter:send', function (\App\Modules\WEB\Services\NewsletterService $newsletter) {
    $this->info('Correos del boletín enviados: '.$newsletter->sendDue());
})->purpose('Envía las ediciones del boletín programadas cuya hora ya llegó');

Schedule::command('escudo:newsletter:send')->everyMinute()->withoutOverlapping()->runInBackground()->name('escudo:newsletter:send');

Artisan::command('escudo:analytics:daily', function () {
    $this->info(\App\Modules\WEB\Http\Controllers\AnalyticsController::dailyMaintenance());
})->purpose('Resume las métricas del día anterior');

Schedule::command('escudo:analytics:daily')->dailyAt('00:15')->timezone('America/Lima')->withoutOverlapping()->runInBackground()->name('escudo:analytics:daily');

Artisan::command('escudo:demos:reminders', function (\App\Modules\WEB\Services\DemoService $demos) {
    $this->info('Recordatorios de demo enviados: '.$demos->sendReminders());
})->purpose('Envía los recordatorios del día anterior y de la hora previa de las demos confirmadas');

Schedule::command('escudo:demos:reminders')->everyFiveMinutes()->withoutOverlapping()->runInBackground()->name('escudo:demos:reminders');

Artisan::command('escudo:webinars:reminders', function (\App\Modules\WEB\Services\WebinarsService $webinars) {
    $this->info('Recordatorios de webinar enviados: '.$webinars->sendReminders());
})->purpose('Envía a los inscritos el recordatorio del día anterior y de la hora previa de cada webinar');

Schedule::command('escudo:webinars:reminders')->everyFiveMinutes()->withoutOverlapping()->runInBackground()->name('escudo:webinars:reminders');

Artisan::command('escudo:reviews:refresh', function () {
    $this->info('Reseñas actualizadas: '.\App\Modules\WEB\Http\Controllers\ReviewsController::refreshDue());
})->purpose('Renueva la copia de las reseñas de Google cuando vence');

Schedule::command('escudo:reviews:refresh')->hourly()->withoutOverlapping()->runInBackground()->name('escudo:reviews:refresh');

Artisan::command('escudo:crm:sync', function (\App\Modules\WEB\Services\CrmService $crm) {
    $this->info('Envíos al CRM procesados: '.$crm->processDue());
})->purpose('Envía al CRM los registros pendientes y reintenta los fallidos');

Schedule::command('escudo:crm:sync')->everyMinute()->withoutOverlapping()->runInBackground()->name('escudo:crm:sync');

/*
| Alertas SST diarias (M-NOT): acciones vencidas o por vencer, notificaciones obligatorias, IPERC por revisar,
| inspecciones atrasadas, aptitudes, EPP y fotochecks por vencer, reportes sin revisar → campana de quien debe actuar.
| Uso: php artisan escudo:sst-alerts
*/
Artisan::command('escudo:sst-alerts', function (\App\Modules\NOT\SstAlerts $alerts) {
    $this->info('Avisos creados: '.$alerts->run());
})->purpose('Genera las alertas SST del día en la campana de cada empresa');

Schedule::command('escudo:sst-alerts')->dailyAt('07:00')->timezone('America/Lima')->withoutOverlapping()->runInBackground()->name('escudo:sst-alerts');

/*
| Entrega por correo y WhatsApp de los avisos de la campana (M-NOT): críticos al momento, enlaces de votación,
| respuesta a reportes y resumen diario por WhatsApp a quien lo aceptó. Cada intento queda en notification_deliveries.
| Uso: php artisan escudo:alerts-deliver
*/
Artisan::command('escudo:alerts-deliver', function (\App\Modules\NOT\AlertDelivery $delivery) {
    $n = $delivery->run();
    $this->info("Correos: {$n['email']} · WhatsApp: {$n['whatsapp']}");
})->purpose('Envía por correo y WhatsApp los avisos que lo requieren');

Schedule::command('escudo:alerts-deliver')->everyMinute()->withoutOverlapping()->runInBackground()->name('escudo:alerts-deliver');

/*
| Vencimientos de contratistas (M-CTR RN-CTR-03, M-SCT): documentos y pólizas SCTR por vencer o vencidos → campana del
| Jefe SST de la empresa principal y del administrador del contratista.
| Uso: php artisan escudo:ctr-expiry
*/
Artisan::command('escudo:ctr-expiry', function (\App\Modules\CTR\ExpiryAlerts $alerts) {
    $this->info('Avisos creados: '.$alerts->run());
})->purpose('Avisa los documentos y pólizas SCTR de contratistas por vencer o vencidos');

Schedule::command('escudo:ctr-expiry')->dailyAt('07:10')->timezone('America/Lima')->withoutOverlapping()->runInBackground()->name('escudo:ctr-expiry');

/*
| Webhooks del complemento API: envía los eventos pendientes firmados y reintenta los fallidos.
| Uso: php artisan escudo:webhooks
*/
Artisan::command('escudo:webhooks', function (\App\Modules\SUS\Services\AccountsService $accounts) {
    $this->info('Envíos: '.\App\Modules\API\Webhooks::dispatch($accounts));
})->purpose('Envía los webhooks pendientes de las empresas con el complemento API');

Schedule::command('escudo:webhooks')->everyMinute()->withoutOverlapping()->runInBackground()->name('escudo:webhooks');

/*
| Reglas de recomendación del aula (M-AUL RN-AUL-24..30): certificados por vencer, brecha del puesto, ruta y
| complementarios → recomendaciones con motivo, sin repetir, respetando consentimiento y límite de envíos.
| Uso: php artisan escudo:aul-recommendations
*/
Artisan::command('escudo:aul-recommendations', function (\App\Modules\AUL\RecommendationEngine $engine) {
    $this->info('Recomendaciones creadas: '.$engine->run());
})->purpose('Evalúa las reglas de recomendación de cursos del aula virtual');

Schedule::command('escudo:aul-recommendations')->dailyAt('06:00')->timezone('America/Lima')->withoutOverlapping()->runInBackground()->name('escudo:aul-recommendations');
