<?php

namespace App\Modules\SUS\Services;

use App\Modules\WEB\Mail\SiteRequestMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Vigilante del servidor (cada hora): que la copia de seguridad se haga sí o sí y que el disco no se llene sin aviso.
 *  - Si alguna base no tiene copia correcta en backups.max_age_hours (26 h), la hace en ese momento y avisa al equipo.
 *    Si vuelve a fallar, avisa con el error. No repite el intento si hay una copia corriendo (menos de 3 h).
 *  - Si el disco del servidor pasa disk_alert_pct (85 %), avisa una vez al día con cuánto queda.
 * Los avisos van al correo de contacto del sitio (Panel › Sitio web) y quedan en el panel de Salud.
 */
final class SystemWatch
{
    public function __construct(private BackupService $backups) {}

    /** @return array{backup: string, disk_used_pct: int} */
    public function run(): array
    {
        return ['backup' => $this->backup(), 'disk_used_pct' => $this->disk()];
    }

    private function backup(): string
    {
        $overdue = array_filter($this->backups->status()['targets'], fn ($t) => $t['overdue']);
        if (! $overdue) {
            return 'ok';
        }
        $running = DB::connection('platform')->selectOne("select 1 as ok from backup_runs where status = 'running' and started_at > now() - interval '3 hours' limit 1");
        if ($running) {
            return 'running';
        }
        $failed = [];
        foreach ($overdue as $t) {
            try {
                $this->backups->run($t['target'], 'schedule');
            } catch (\Throwable $e) {
                $failed[] = $t['label'].': '.mb_substr($e->getMessage(), 0, 200);
            }
        }
        $this->backups->prune();
        $labels = implode(', ', array_column($overdue, 'label'));
        $this->notify('backup:'.date('Y-m-d'), $failed ? 'La copia de seguridad está fallando' : 'Copia de seguridad recuperada',
            $failed ? 'La copia diaria no se hizo a tiempo y el intento automático también falló. Revisa el panel de Salud.' : "La copia diaria de {$labels} no se había hecho a tiempo: el vigilante la hizo ahora y salió bien.",
            $failed ? array_map(fn ($f) => ['Error', $f], $failed) : [['Bases', $labels]]);

        return $failed ? 'failed' : 'recovered';
    }

    private function disk(): int
    {
        $path = storage_path();
        $total = @disk_total_space($path) ?: 0;
        $free = @disk_free_space($path) ?: 0;
        if ($total <= 0) {
            return 0;
        }
        $used = (int) round(($total - $free) * 100 / $total);
        $limit = max(50, min(98, (int) config('escudo.disk_alert_pct', 85)));
        if ($used >= $limit) {
            $this->notify('disk:'.date('Y-m-d'), 'El disco del servidor se está llenando',
                "El disco del servidor está al {$used} %. Conviene mover los archivos a un almacenamiento externo (Panel › Proveedores) o ampliar el disco.",
                [['Usado', "{$used} %"], ['Libre', round($free / 1073741824, 1).' GB de '.round($total / 1073741824, 1).' GB']]);
        }

        return $used;
    }

    private function notify(string $key, string $title, string $intro, array $rows): void
    {
        if (! Cache::add('system-watch:'.$key, 1, now()->addHours(20))) {
            return;
        }
        try {
            $s = json_decode(DB::connection('platform')->selectOne('select public_site_settings() as s')->s, true);
            $to = ($s['contact_email'] ?? null) ?: ($s['sales_email'] ?? null);
            if ($to) {
                Mail::to($to)->send(new SiteRequestMail('equipo', $title, $intro, $rows, null, 'Abrir el panel de Salud', rtrim((string) config('escudo.frontend_url'), '/').'/plataforma/salud'));
            }
        } catch (\Throwable) {
            // El aviso nunca detiene al vigilante.
        }
    }
}
