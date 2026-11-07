<?php

namespace App\Modules\SUS\Http\Controllers;

use App\Core\Support\SystemPulse;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use App\Modules\SUS\Services\RenewalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Panel del dueño · Salud del sistema (docs/03 §11; producción en docs/15: VPS Contabo, archivos en Backblaze B2,
 * colas con Horizon, base médica en DigitalOcean). Resume en una pantalla lo que el monitoreo externo de docs/15 §10
 * (Sentry, monitor de disponibilidad, agente de métricas) alerta por su cuenta.
 *
 * Cada revisión devuelve: status ok | warning | error | off (sin configurar), detail (lo que se midió),
 * help (qué es, en palabras simples) y fix (qué hacer si no está bien).
 *
 *  GET  /internal/health             estado de todo
 *  POST /internal/health/run-daily   revisar los vencimientos ahora, sin esperar al horario automático
 */
class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        $checks = [
            $this->api(),
            $this->timed('db_main', 'Base de datos principal', 'infra', 'database',
                'Donde se guarda toda la información de las empresas (menos la médica).',
                'Si no responde, nadie puede usar EscudoSST. Revisar que el servicio de PostgreSQL esté encendido en el servidor.',
                fn () => $this->dbDetail('pgsql')),
            $this->timed('db_medical', 'Base de datos médica', 'infra', 'heart',
                'Base separada para los datos de salud ocupacional (en producción, en DigitalOcean).',
                'Si no responde, no se pueden ver ni registrar datos médicos. Revisar el panel de DigitalOcean y la IP permitida.',
                fn () => $this->dbDetail('medical')),
            $this->timed('redis', 'Caché y sesiones (Redis)', 'infra', 'grid',
                'Memoria rápida para las sesiones, la caché y la cola de trabajos.',
                'Si no responde, las personas pierden la sesión y no se envían avisos. Reiniciar el servicio «redis».',
                function () {
                    Redis::connection()->ping();

                    return 'Responde.';
                }),
            $this->disk(),
            $this->files(),
            $this->scheduler(),
            $this->daily(),
            $this->queue(),
            $this->failedJobs(),
            $this->mail(),
            [
                'key' => 'whatsapp', 'name' => 'WhatsApp', 'group' => 'comms', 'icon' => 'mail', 'status' => 'off',
                'detail' => 'Pendiente: falta elegir el proveedor de WhatsApp. Mientras tanto los avisos llegan por correo.',
                'help' => 'Para enviar avisos y alertas por WhatsApp.',
                'fix' => 'Nada en el panel. Cuando se elija el proveedor, el equipo técnico conecta su cuenta en el servidor.',
            ],
            $this->backups(),
            [
                'key' => 'sunat', 'name' => 'Facturación electrónica (OSE)', 'group' => 'comms', 'icon' => 'file', 'status' => 'off',
                'detail' => 'Pendiente: falta contratar el proveedor de facturación electrónica. Los pagos se registran igual y su factura queda como «SUNAT pendiente».',
                'help' => 'Envía a la SUNAT la factura de cada pago de suscripción.',
                'fix' => 'Nada en el panel. Mientras tanto, emite la factura por tu sistema actual. Cuando se contrate el proveedor (OSE), el equipo técnico lo conecta.',
            ],
        ];

        $failed = DB::connection('platform')->select(
            "select uuid, queue, failed_at, split_part(exception, E'\\n', 1) as error from failed_jobs order by failed_at desc limit 10"
        );

        return response()->json(['data' => [
            'environment' => app()->environment(),
            'checks' => $checks,
            'mail_days' => SystemPulse::mailLastDays(),
            'last_mail_error' => SystemPulse::lastMailError(),
            'failed_jobs' => $failed,
            'checked_at' => now()->toIso8601String(),
        ]]);
    }

    public function runDaily(Request $request, RenewalService $renewals, AccountsService $accounts): JsonResponse
    {
        $summary = $renewals->run();
        SystemPulse::dailyRun($summary);
        $accounts->audit($request->user()->id, 'daily_process_run', 'subscriptions', null, ['summary' => $summary], 'Ejecutado a mano desde Salud del sistema');

        return response()->json(['data' => $summary]);
    }

    private function api(): array
    {
        $prodDebug = app()->isProduction() && config('app.debug');

        return [
            'key' => 'api', 'name' => 'Aplicación (API)', 'group' => 'infra', 'icon' => 'globe',
            'status' => $prodDebug ? 'error' : 'ok',
            'detail' => 'PHP '.PHP_VERSION.' · Laravel '.app()->version().' · entorno «'.app()->environment().'»'
                .($prodDebug ? ' · modo depuración activo en producción.' : ''),
            'help' => 'El programa de EscudoSST que atiende a la web. Si ves esta página, está funcionando.',
            'fix' => 'En producción, APP_DEBUG debe ser false: si no, los errores muestran datos internos.',
        ];
    }

    private function dbDetail(string $connection): string
    {
        $r = DB::connection($connection)->selectOne("select current_setting('server_version') as v, pg_size_pretty(pg_database_size(current_database())) as size");

        return 'PostgreSQL '.explode(' ', $r->v)[0].' · ocupa '.$r->size.'.';
    }

    /** Disco donde corre la aplicación: en desarrollo, esta computadora; en producción, el VPS (logs, temporales, base principal). */
    private function disk(): array
    {
        $local = app()->environment('local');
        $base = [
            'key' => 'disk', 'group' => 'infra', 'icon' => 'box',
            'name' => $local ? 'Disco de esta computadora' : 'Disco del servidor',
            'help' => $local
                ? 'En desarrollo todo corre en tu computadora. Si el disco se llena, Docker y la aplicación dejan de funcionar.'
                : 'Disco del VPS: guarda la base principal, los registros y archivos temporales. Si se llena, EscudoSST se detiene.',
            'fix' => $local
                ? 'Libera espacio: vacía la papelera, borra descargas o usa «Liberador de espacio en disco» de Windows.'
                : 'Ampliar el disco del VPS en Contabo o limpiar registros antiguos.',
        ];
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        if (! $free || ! $total) {
            return $base + ['status' => 'warning', 'detail' => 'No se pudo leer el espacio en disco.'];
        }
        $pct = (int) round($free / $total * 100);

        return $base + [
            'status' => $pct < 10 ? 'error' : ($pct < 20 ? 'warning' : 'ok'),
            'detail' => sprintf('Quedan %s libres de %s (%d %%).', $this->bytes($free), $this->bytes($total), $pct)
                .($pct < 10 ? ' Queda muy poco espacio.' : ''),
        ];
    }

    /** Archivos de las empresas (evidencias, fotos, PDF): en producción, Backblaze B2 (docs/15 §4). */
    private function files(): array
    {
        $disk = (string) config('filesystems.default');
        $base = [
            'key' => 'files', 'name' => 'Archivos de las empresas', 'group' => 'infra', 'icon' => 'file',
            'help' => 'Evidencias, fotos, PDF y firmas que suben las empresas. Lo ideal es guardarlos en un almacenamiento externo (Backblaze B2), aparte del servidor.',
            'fix' => 'Nada en el panel. Cuando se contrate Backblaze B2, el equipo técnico carga sus datos de acceso en el servidor y los archivos pasan a guardarse ahí.',
        ];
        if ($disk !== 's3') {
            // Pendiente (no es una falla): funciona guardando en el disco del servidor mientras se contrata B2.
            return $base + [
                'status' => 'off',
                'detail' => app()->isProduction()
                    ? 'Funcionan: por ahora se guardan en el disco de este servidor. Falta contratar el almacenamiento externo (Backblaze B2).'
                    : 'En desarrollo los archivos se guardan en tu computadora.',
            ];
        }
        $start = microtime(true);
        try {
            Storage::disk('s3')->exists('.healthcheck');

            return $base + ['status' => 'ok', 'detail' => 'Conectado al bucket «'.config('filesystems.disks.s3.bucket').'».', 'latency_ms' => (int) round((microtime(true) - $start) * 1000)];
        } catch (Throwable $e) {
            return $base + ['status' => 'error', 'detail' => 'No conecta con B2: '.mb_substr($e->getMessage(), 0, 140)];
        }
    }

    private function scheduler(): array
    {
        $beat = SystemPulse::schedulerLastBeat();
        $ago = $beat ? Carbon::parse($beat)->diffInMinutes(now()) : null;

        return [
            'key' => 'scheduler', 'name' => 'Programador de tareas', 'group' => 'process', 'icon' => 'clock',
            'status' => $beat === null || $ago > 3 ? 'error' : 'ok',
            'detail' => $beat === null ? 'No está corriendo.' : ($ago > 3 ? 'Se detuvo hace '.(int) $ago.' minutos.' : 'Activo: dio señal hace menos de un minuto.'),
            'help' => 'El «reloj» del sistema: lanza solo las tareas automáticas, como los avisos de vencimiento de cada mañana.',
            'fix' => 'Si se detiene, los avisos y bloqueos automáticos no ocurren. Iniciar el servicio «scheduler».',
            'at' => $beat,
        ];
    }

    private function daily(): array
    {
        $last = SystemPulse::lastDailyRun();
        $hours = $last ? Carbon::parse($last['at'])->diffInHours(now()) : null;
        $s = $last['summary'] ?? null;

        return [
            'key' => 'daily', 'name' => 'Revisión diaria de vencimientos', 'group' => 'process', 'icon' => 'history',
            'status' => $last === null || $hours > 26 ? 'warning' : 'ok',
            'detail' => $last === null
                ? 'Aún no ha corrido desde que se encendió el seguimiento.'
                : "Última vez: {$s['reminders']} avisos enviados, {$s['past_due']} pasaron a pago pendiente, {$s['trial_expired']} pruebas vencieron y {$s['blocked']} quedaron en solo lectura.",
            'help' => 'Todos los días a las '.config('escudo.subscriptions.daily_at').' (hora de Lima) revisa qué planes vencen: envía los avisos y bloquea lo que no se pagó.',
            'fix' => 'Si no corrió en más de un día, revisar el programador de tareas. Puedes revisarlos ahora con el botón.',
            'at' => $last['at'] ?? null,
            'action' => 'run_daily',
        ];
    }

    private function queue(): array
    {
        $base = [
            'key' => 'queue', 'name' => 'Cola de trabajos', 'group' => 'process', 'icon' => 'grid',
            'help' => 'Fila de tareas que se hacen en segundo plano (correos masivos, PDF, importaciones). En producción la atienden los trabajadores de Horizon.',
            'fix' => 'Si crece y no baja, los trabajadores (servicio «worker») están detenidos: reiniciarlos.',
        ];
        try {
            $size = Queue::size('default');
        } catch (Throwable $e) {
            return $base + ['status' => 'error', 'detail' => 'No se pudo leer la cola: '.$e->getMessage()];
        }

        return $base + [
            'status' => $size > 50 ? 'warning' : 'ok',
            'detail' => $size === 0 ? 'Vacía: no hay tareas esperando.' : "{$size} tareas esperando.",
            'value' => $size,
        ];
    }

    private function failedJobs(): array
    {
        $r = DB::connection('platform')->selectOne(
            "select count(*) as total, count(*) filter (where failed_at > now() - interval '24 hours') as recent from failed_jobs"
        );

        return [
            'key' => 'failed_jobs', 'name' => 'Tareas fallidas', 'group' => 'process', 'icon' => 'lock',
            'status' => $r->recent > 0 ? 'error' : ($r->total > 0 ? 'warning' : 'ok'),
            'detail' => $r->total == 0 ? 'Ninguna.' : "{$r->total} en total, {$r->recent} en las últimas 24 horas.",
            'help' => 'Tareas en segundo plano que no se pudieron completar (por ejemplo, un correo que el proveedor rechazó).',
            'fix' => 'Ver el error en la lista de abajo y corregir la causa; luego se pueden reintentar.',
            'value' => (int) $r->total,
        ];
    }

    private function mail(): array
    {
        $host = (string) config('mail.mailers.smtp.host');
        $port = (int) config('mail.mailers.smtp.port');
        $start = microtime(true);
        $socket = @fsockopen($host, $port, $errno, $errstr, 2);
        $ms = (int) round((microtime(true) - $start) * 1000);
        if ($socket) {
            fclose($socket);
        }
        $today = SystemPulse::mailLastDays(1)[0];
        $provider = str_contains($host, 'mailpit') ? 'Mailpit (pruebas: los correos no salen a internet)' : $host;

        return [
            'key' => 'mail', 'name' => 'Correo', 'group' => 'comms', 'icon' => 'mail',
            'status' => ! $socket ? 'error' : ($today['failed'] > 0 ? 'warning' : 'ok'),
            'detail' => ($socket ? "Conectado a {$provider}." : "No conecta con {$host}:{$port}.")." Hoy: {$today['sent']} enviados, {$today['failed']} fallidos.",
            'help' => 'Envía los códigos de verificación, invitaciones y avisos de pago. En producción, Elastic Email.',
            'fix' => 'Revisar las credenciales del proveedor de correo en el servidor y su panel (rebotes, límites).',
            'latency_ms' => $socket ? $ms : null,
        ];
    }

    /**
     * Copias de seguridad: lee las copias reales (BackupService, a las 02:30). Si están al día pero solo en el
     * servidor, queda «Revisar» con la explicación: falta una copia fuera del servidor (Backblaze B2).
     */
    private function backups(): array
    {
        $base = [
            'key' => 'backups', 'name' => 'Copias de seguridad', 'group' => 'infra', 'icon' => 'shield',
            'help' => 'Copia diaria y cifrada de las bases de datos (también la médica), para recuperar la información si algo falla.',
        ];
        try {
            $status = app(\App\Modules\SUS\Services\BackupService::class)->status();
        } catch (Throwable $e) {
            return $base + ['status' => 'error', 'detail' => 'No se pudo leer el estado de las copias.', 'fix' => 'Revisa «Copias de seguridad» en este panel o avisa al equipo técnico.'];
        }
        $targets = collect($status['targets']);
        $failed = $targets->firstWhere('last_status', 'failed');
        $last = $targets->pluck('last_ok_at')->filter()->min();
        $at = (string) $status['config']['daily_at'];

        if ($failed) {
            return $base + ['status' => 'error', 'at' => $last, 'detail' => "Falló la última copia de {$failed['label']}.", 'fix' => 'Revisa el error en «Copias de seguridad» y vuelve a intentarlo con «Hacer copia ahora».'];
        }
        if (! $last) {
            return $base + ['status' => 'warning', 'detail' => "Aún no hay copias. La primera se hace sola hoy a las {$at}.", 'fix' => 'Puedes hacer una ahora desde «Copias de seguridad», o esperar a la copia automática.'];
        }
        if ($targets->contains('overdue', true)) {
            return $base + ['status' => 'error', 'at' => $last, 'detail' => 'La copia está atrasada: la última tiene más de un día.', 'fix' => 'Revisa que el programador de tareas esté activo (en esta misma página) y haz una copia ahora.'];
        }
        $offsite = (string) config('filesystems.disks.'.$status['config']['disk'].'.driver') === 's3';
        if (! $offsite) {
            return $base + [
                'status' => 'warning', 'at' => $last,
                'detail' => "Funcionan: se hace una copia cifrada cada día a las {$at} y se guarda en este servidor. Falta guardar también una copia fuera del servidor.",
                'fix' => 'Nada urgente. Cuando se contrate Backblaze B2, el equipo técnico lo conecta para que cada copia se guarde también fuera del servidor (por si el servidor completo falla).',
            ];
        }

        return $base + ['status' => 'ok', 'at' => $last, 'detail' => "Copia cifrada diaria a las {$at}, guardada fuera del servidor.", 'fix' => ''];
    }

    private function timed(string $key, string $name, string $group, string $icon, string $help, string $fix, callable $fn): array
    {
        $start = microtime(true);
        try {
            $detail = $fn();
            $ms = (int) round((microtime(true) - $start) * 1000);

            return compact('key', 'name', 'group', 'icon', 'detail', 'help', 'fix') + ['status' => $ms > 500 ? 'warning' : 'ok', 'latency_ms' => $ms];
        } catch (Throwable $e) {
            return compact('key', 'name', 'group', 'icon', 'help', 'fix') + ['status' => 'error', 'detail' => 'No responde: '.mb_substr($e->getMessage(), 0, 160)];
        }
    }

    private function bytes(float $b): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log(max($b, 1), 1024));

        return round($b / 1024 ** $i, 1).' '.$units[$i];
    }
}
