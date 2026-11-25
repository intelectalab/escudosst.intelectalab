<?php

namespace App\Modules\SUS\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;

/**
 * Prueba de restauración de las copias de seguridad (docs/15 §9 «Restauración probada», docs/12 §9).
 *
 * 1. Toma la última copia buena (backup_runs status = ok, no borrada) de la base pedida.
 * 2. Comprueba la huella sha256 del archivo cifrado y lo descifra con su llave.
 * 3. Crea una base temporal (escudo_restore_test_…) y restaura con pg_restore, deteniéndose en el primer error.
 * 4. Revisa: hay tablas, hay tablas con RLS forzado, la base principal trae su registro de migraciones y las tablas
 *    clave tienen filas contadas (se comparan con la base en uso solo como referencia: la copia puede ser de ayer).
 * 5. Borra la base temporal y los archivos, y deja el resultado y la duración en backup_restore_tests.
 *
 * La base temporal vive en el mismo servidor de PostgreSQL de la copia (o en BACKUP_RESTORE_DB_HOST si se configura
 * un servidor aislado, que debe tener los mismos roles). Necesita un usuario con CREATEDB (escudo.backups.restore).
 * Nunca lanza: el fallo queda registrado.
 */
class BackupRestoreTestService
{
    /** Tablas cuyas filas se cuentan como muestra (las que existan en la copia). */
    private const KEY_TABLES = [
        'main' => ['accounts', 'companies', 'users', 'memberships', 'workers', 'files', 'audit_logs', 'sst_events', 'trainings', 'documents'],
        'medical' => ['medical_exams', 'medical_exam_files', 'surveillance_cases', 'clinical_notes', 'medical_leave_details', 'medical_access_logs'],
    ];

    public function __construct(private BackupService $backups) {}

    private function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    /** Prueba la última copia buena de una base. Devuelve el id del registro. */
    public function run(string $target, string $trigger = 'schedule', ?string $actor = null): string
    {
        if (! array_key_exists($target, BackupService::TARGETS)) {
            throw new RuntimeException('Base desconocida: '.$target);
        }
        $id = $this->db()->selectOne(
            'insert into backup_restore_tests (target, trigger, requested_by) values (?, ?, ?) returning id',
            [$target, $trigger, $actor]
        )->id;
        $t0 = hrtime(true);
        $enc = tempnam(sys_get_temp_dir(), 'rt');
        $dump = $enc.'.dump';
        $temp = null;
        $admin = null;
        $checks = [];
        try {
            $run = $this->db()->selectOne(
                "select id, disk, path, sha256 from backup_runs where target = ? and status = 'ok' and deleted_at is null and path is not null order by started_at desc limit 1",
                [$target]
            );
            if (! $run) {
                throw new RuntimeException('No hay una copia buena para probar. Haz una copia primero.');
            }
            $this->db()->update('update backup_restore_tests set backup_run_id = ? where id = ?', [$run->id, $id]);

            $in = Storage::disk($run->disk)->readStream($run->path);
            if (! is_resource($in)) {
                throw new RuntimeException('No se encontró el archivo de la copia en el almacenamiento.');
            }
            $out = fopen($enc, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            $sameHash = $run->sha256 && hash_equals((string) $run->sha256, hash_file('sha256', $enc));
            $checks[] = ['key' => 'sha256', 'label' => 'Huella del archivo cifrado igual a la registrada', 'ok' => $sameHash];
            if (! $sameHash) {
                throw new RuntimeException('La huella del archivo no coincide con la registrada: la copia fue alterada o está incompleta.');
            }
            $this->backups->decrypt($enc, $dump, $target);
            $checks[] = ['key' => 'decrypt', 'label' => 'Se descifra con su llave', 'ok' => true];

            $conn = $this->target($target);
            $admin = $this->pdo($conn, 'postgres');
            $temp = 'escudo_restore_test_'.now()->format('Ymd_His').'_'.Str::lower(Str::random(4));
            $admin->exec('CREATE DATABASE "'.$temp.'"');
            $this->db()->update('update backup_restore_tests set temp_database = ? where id = ?', [$temp, $id]);

            $result = Process::timeout((int) config('escudo.backups.timeout_seconds'))
                ->env(['PGPASSWORD' => $conn['password'], 'PGSSLMODE' => $conn['sslmode']])
                ->run(['pg_restore', '--no-owner', '--no-privileges', '--exit-on-error',
                    '--host='.$conn['host'], '--port='.$conn['port'], '--username='.$conn['username'], '--dbname='.$temp, $dump]);
            if (! $result->successful()) {
                throw new RuntimeException('pg_restore falló: '.trim($result->errorOutput() ?: $result->output()));
            }
            $checks[] = ['key' => 'restore', 'label' => 'pg_restore terminó sin errores', 'ok' => true];

            $r = $this->pdo($conn, $temp);
            $tables = (int) $r->query("select count(*) from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relkind in ('r', 'p') and not c.relispartition")->fetchColumn();
            $rls = (int) $r->query("select count(*) from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relkind in ('r', 'p') and not c.relispartition and c.relrowsecurity and c.relforcerowsecurity")->fetchColumn();
            $hasMigrations = (bool) $r->query("select to_regclass('public.migrations') is not null")->fetchColumn();
            $migrations = $hasMigrations ? (int) $r->query('select count(*) from migrations')->fetchColumn() : null;
            $counts = [];
            foreach (self::KEY_TABLES[$target] as $t) {
                if ($r->query("select to_regclass('public.{$t}') is not null")->fetchColumn()) {
                    $counts[$t] = (int) $r->query("select count(*) from {$t}")->fetchColumn();
                }
            }
            $r = null;

            $live = $this->liveCounts($target);
            $checks[] = ['key' => 'tables', 'label' => "Tablas restauradas: {$tables}".($live['tables'] !== null ? " (en uso: {$live['tables']})" : ''), 'ok' => $tables > 0];
            $checks[] = ['key' => 'rls', 'label' => "Tablas con RLS forzado: {$rls}".($live['rls'] !== null ? " (en uso: {$live['rls']})" : ''), 'ok' => $rls > 0];
            if ($target === 'main') {
                $checks[] = ['key' => 'migrations', 'label' => 'Registro de migraciones: '.($migrations ?? 0), 'ok' => ($migrations ?? 0) > 0];
            }
            $checks[] = ['key' => 'rows', 'label' => 'Tablas clave presentes: '.count($counts).' de '.count(self::KEY_TABLES[$target]), 'ok' => count($counts) > 0];

            $ok = collect($checks)->every(fn ($c) => $c['ok']);
            $this->db()->update(
                'update backup_restore_tests set status = ?, finished_at = now(), duration_ms = ?, tables_count = ?, rls_tables_count = ?, migrations_count = ?, row_counts = ?::jsonb, checks = ?::jsonb, error = ? where id = ?',
                [$ok ? 'ok' : 'failed', $this->ms($t0), $tables, $rls, $migrations, json_encode((object) $counts), json_encode($checks, JSON_UNESCAPED_UNICODE),
                    $ok ? null : 'La copia se restauró, pero no pasó todas las revisiones.', $id]
            );
        } catch (\Throwable $e) {
            Log::error('Prueba de restauración fallida', ['target' => $target, 'test' => $id, 'error' => $this->clean($e->getMessage())]);
            $this->db()->update(
                "update backup_restore_tests set status = 'failed', finished_at = now(), duration_ms = ?, checks = ?::jsonb, error = ? where id = ?",
                [$this->ms($t0), json_encode($checks, JSON_UNESCAPED_UNICODE), mb_substr($this->clean($e->getMessage()), 0, 500), $id]
            );
        } finally {
            if ($temp && $admin) {
                try {
                    $admin->exec('DROP DATABASE IF EXISTS "'.$temp.'" WITH (FORCE)');
                } catch (\Throwable $e) {
                    Log::warning('No se pudo borrar la base temporal de la prueba de restauración', ['database' => $temp, 'error' => $this->clean($e->getMessage())]);
                }
            }
            $admin = null;
            @unlink($enc);
            @unlink($dump);
        }

        return $id;
    }

    /** Prueba las dos bases. */
    public function runAll(string $trigger = 'schedule', ?string $actor = null): array
    {
        return array_map(fn ($t) => $this->run($t, $trigger, $actor), array_keys(BackupService::TARGETS));
    }

    /** Para el panel: última prueba por base, si está vencida y las últimas pruebas. */
    public function status(): array
    {
        $maxAge = (int) config('escudo.backups.restore.max_age_days');
        $targets = [];
        foreach (BackupService::TARGETS as $t => $label) {
            $lastOk = $this->db()->selectOne("select finished_at, duration_ms from backup_restore_tests where target = ? and status = 'ok' order by started_at desc limit 1", [$t]);
            $last = $this->db()->selectOne('select status, started_at, error from backup_restore_tests where target = ? order by started_at desc limit 1', [$t]);
            $targets[] = [
                'target' => $t,
                'label' => $label,
                'last_ok_at' => $lastOk?->finished_at,
                'last_ok_duration_ms' => $lastOk?->duration_ms,
                'last_status' => $last?->status,
                'last_error' => $last?->status === 'failed' ? $last->error : null,
                'overdue' => ! $lastOk || strtotime($lastOk->finished_at) < time() - $maxAge * 86400,
            ];
        }
        $tests = array_map(function ($r) {
            $r->row_counts = json_decode($r->row_counts, true) ?: [];
            $r->checks = json_decode($r->checks, true) ?: [];

            return $r;
        }, $this->db()->select(
            'select t.id, t.target, t.trigger, t.status, t.started_at, t.finished_at, t.duration_ms, t.tables_count, t.rls_tables_count, t.migrations_count,
                    t.row_counts, t.checks, t.error, b.started_at as backup_started_at, u.full_name as requested_by
               from backup_restore_tests t left join backup_runs b on b.id = t.backup_run_id left join users u on u.id = t.requested_by
              order by t.started_at desc limit 20'
        ));
        $days = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

        return [
            'targets' => $targets,
            'tests' => $tests,
            'config' => [
                'weekly' => 'Cada '.($days[(int) config('escudo.backups.restore.weekly_day')] ?? 'domingo').' a las '.config('escudo.backups.restore.weekly_at'),
                'max_age_days' => $maxAge,
                'ready' => (string) config('escudo.backups.restore.password') !== '',
            ],
        ];
    }

    // ---------------------------------------------------------------- Apoyo

    /** Datos de conexión del servidor donde se crea la base temporal. */
    private function target(string $target): array
    {
        $c = config('database.connections.'.($target === 'medical' ? 'medical' : 'pgsql'));
        $pass = (string) config('escudo.backups.restore.password');
        if ($pass === '') {
            throw new RuntimeException('Falta BACKUP_RESTORE_DB_PASSWORD (o POSTGRES_SUPERUSER_PASSWORD) en el servidor.');
        }

        return [
            'host' => (string) (config('escudo.backups.restore.host') ?: $c['host']),
            'port' => (string) (config('escudo.backups.restore.port') ?: $c['port']),
            'username' => (string) config('escudo.backups.restore.username'),
            'password' => $pass,
            'sslmode' => $c['sslmode'] ?? 'prefer',
        ];
    }

    private function pdo(array $c, string $database): PDO
    {
        return new PDO(
            "pgsql:host={$c['host']};port={$c['port']};dbname={$database};sslmode={$c['sslmode']}",
            $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    /** Cantidad de tablas y de tablas con RLS de la base en uso, solo como referencia en el informe. */
    private function liveCounts(string $target): array
    {
        try {
            $row = DB::connection($target === 'medical' ? 'medical' : 'platform')->selectOne(
                "select count(*) as tables, count(*) filter (where c.relrowsecurity and c.relforcerowsecurity) as rls
                   from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relkind in ('r', 'p') and not c.relispartition"
            );

            return ['tables' => (int) $row->tables, 'rls' => (int) $row->rls];
        } catch (\Throwable) {
            return ['tables' => null, 'rls' => null];
        }
    }

    private function ms(int $t0): int
    {
        return (int) round((hrtime(true) - $t0) / 1e6);
    }

    private function clean(string $msg): string
    {
        foreach ([(string) config('escudo.backups.restore.password'), (string) config('escudo.backups.db_password')] as $secret) {
            if ($secret !== '') {
                $msg = str_replace($secret, '***', $msg);
            }
        }

        return $msg;
    }
}
