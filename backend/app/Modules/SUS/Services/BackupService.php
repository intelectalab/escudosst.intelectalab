<?php

namespace App\Modules\SUS\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Copias de seguridad lógicas cifradas (docs/15 §9, docs/10 §8).
 *
 * - Volcado con pg_dump (formato personalizado, comprimido) usando el rol escudo_backup: solo lectura, lee todas las
 *   filas aunque tengan RLS. Base principal y médica por separado.
 * - Se cifra antes de salir del servidor con libsodium (XChaCha20-Poly1305 por bloques). Llaves distintas para la
 *   base principal (BACKUP_KEY) y la médica (BACKUP_MEDICAL_KEY), distintas también de APP_KEY. Sin la llave no se
 *   puede leer: hay que guardarla fuera del servidor (docs/10: copia offline custodiada por dos personas).
 * - Se guarda en el disco escudo.backups.disk (local en desarrollo; bucket de respaldos con bloqueo de objetos en
 *   producción) y se borra al pasar escudo.backups.retention_days.
 * - Cada copia queda en backup_runs (resultado, tamaño, huella sha256 del archivo cifrado).
 *
 * Esto es la copia lógica diaria. La copia física con WAL continuo (recuperación a un punto en el tiempo) se configura
 * en el servidor de producción (pgBackRest o WAL-G), no en la aplicación.
 */
class BackupService
{
    /** Cabecera del archivo cifrado: identifica el formato y la versión. */
    private const MAGIC = "ESCUDOBK1\n";

    private const CHUNK = 1024 * 1024;

    public const TARGETS = ['main' => 'Base principal', 'medical' => 'Base médica'];

    private function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    /** Hace la copia de una base. Devuelve el id del registro. Nunca lanza: el fallo queda registrado. */
    public function run(string $target, string $trigger = 'schedule', ?string $actor = null): string
    {
        $id = $this->db()->selectOne(
            'insert into backup_runs (target, trigger, requested_by) values (?, ?, ?) returning id',
            [$target, $trigger, $actor]
        )->id;
        $dump = tempnam(sys_get_temp_dir(), 'bk');
        $enc = $dump.'.enc';
        try {
            $this->dump($target, $dump);
            $this->encrypt($dump, $enc, $this->key($target));
            $disk = (string) config('escudo.backups.disk');
            $path = sprintf('%s/%s/escudo-%s-%s.dump.enc', $target, now()->format('Y/m'), $target, now()->format('Ymd-His'));
            $stream = fopen($enc, 'rb');
            Storage::disk($disk)->writeStream($path, $stream);
            is_resource($stream) && fclose($stream);
            $this->db()->update(
                "update backup_runs set status = 'ok', finished_at = now(), disk = ?, path = ?, size_bytes = ?, sha256 = ? where id = ?",
                [$disk, $path, filesize($enc), hash_file('sha256', $enc), $id]
            );
        } catch (\Throwable $e) {
            Log::error('Copia de seguridad fallida', ['target' => $target, 'run' => $id, 'error' => $e->getMessage()]);
            $this->db()->update(
                "update backup_runs set status = 'failed', finished_at = now(), error = ? where id = ?",
                [mb_substr($this->clean($e->getMessage()), 0, 500), $id]
            );
        } finally {
            @unlink($dump);
            @unlink($enc);
        }

        return $id;
    }

    /** Copia de todas las bases y limpieza por retención. */
    public function runAll(string $trigger = 'schedule', ?string $actor = null): void
    {
        foreach (array_keys(self::TARGETS) as $t) {
            $this->run($t, $trigger, $actor);
        }
        $this->prune();
    }

    /** Borra las copias más antiguas que la retención (el registro queda con deleted_at). */
    public function prune(): int
    {
        $days = (int) config('escudo.backups.retention_days');
        $old = $this->db()->select(
            "select id, disk, path from backup_runs where status = 'ok' and deleted_at is null and started_at < now() - make_interval(days => ?)",
            [$days]
        );
        foreach ($old as $r) {
            try {
                Storage::disk($r->disk)->delete($r->path);   // con bloqueo de objetos, el bucket lo impide hasta que vence
                $this->db()->update('update backup_runs set deleted_at = now() where id = ?', [$r->id]);
            } catch (\Throwable $e) {
                Log::warning('No se pudo borrar una copia vencida', ['run' => $r->id, 'error' => $e->getMessage()]);
            }
        }

        return count($old);
    }

    /** Estado para el panel y los avisos: última copia buena por base, si está atrasada y las últimas ejecuciones. */
    public function status(): array
    {
        $maxAge = (int) config('escudo.backups.max_age_hours');
        $targets = [];
        foreach (self::TARGETS as $t => $label) {
            $last = $this->db()->selectOne("select started_at, finished_at, size_bytes from backup_runs where target = ? and status = 'ok' order by started_at desc limit 1", [$t]);
            $lastRun = $this->db()->selectOne('select status, started_at, error from backup_runs where target = ? order by started_at desc limit 1', [$t]);
            $targets[] = [
                'target' => $t,
                'label' => $label,
                'last_ok_at' => $last?->finished_at,
                'last_size_bytes' => $last?->size_bytes,
                'last_status' => $lastRun?->status,
                'last_error' => $lastRun?->status === 'failed' ? $lastRun->error : null,
                'overdue' => ! $last || strtotime($last->finished_at) < time() - $maxAge * 3600,
            ];
        }

        return [
            'targets' => $targets,
            'runs' => $this->db()->select(
                'select r.id, r.target, r.trigger, r.status, r.started_at, r.finished_at, r.size_bytes, r.sha256, r.error, r.path, r.deleted_at, u.full_name as requested_by
                   from backup_runs r left join users u on u.id = r.requested_by order by r.started_at desc limit 30'
            ),
            'config' => [
                'disk' => config('escudo.backups.disk'),
                'retention_days' => (int) config('escudo.backups.retention_days'),
                'daily_at' => config('escudo.backups.daily_at'),
                'max_age_hours' => $maxAge,
                'keys_ready' => $this->hasKey('main') && $this->hasKey('medical'),
            ],
        ];
    }

    /** Descifra un archivo de copia (para restaurar). Lanza si la llave no corresponde o el archivo fue alterado. */
    public function decrypt(string $in, string $out, string $target): void
    {
        $key = $this->key($target);
        $src = fopen($in, 'rb') ?: throw new RuntimeException('No se pudo abrir el archivo cifrado.');
        $dst = fopen($out, 'wb') ?: throw new RuntimeException('No se pudo crear el archivo de salida.');
        try {
            if (fread($src, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('El archivo no es una copia de EscudoSST.');
            }
            $header = fread($src, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $tag = 0;
            while (! feof($src)) {
                $len = unpack('N', (string) fread($src, 4))[1] ?? 0;
                if ($len === 0) {
                    break;
                }
                $res = sodium_crypto_secretstream_xchacha20poly1305_pull($state, (string) fread($src, $len));
                if ($res === false) {
                    throw new RuntimeException('La llave no corresponde o el archivo fue alterado.');
                }
                [$plain, $tag] = $res;
                fwrite($dst, $plain);
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    break;
                }
            }
            if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                throw new RuntimeException('El archivo está incompleto.');
            }
        } finally {
            fclose($src);
            fclose($dst);
        }
    }

    // ---------------------------------------------------------------- Apoyo

    private function dump(string $target, string $file): void
    {
        $c = config('database.connections.'.($target === 'medical' ? 'medical' : 'pgsql'));
        $user = (string) config('escudo.backups.db_username');
        $pass = (string) config('escudo.backups.db_password');
        if ($pass === '') {
            throw new RuntimeException('Falta DB_BACKUP_PASSWORD en el servidor.');
        }
        $result = Process::timeout((int) config('escudo.backups.timeout_seconds'))
            ->env(['PGPASSWORD' => $pass, 'PGSSLMODE' => $c['sslmode'] ?? 'prefer'])
            ->run(['pg_dump', '--format=custom', '--compress=6', '--no-owner', '--no-privileges',
                '--host='.$c['host'], '--port='.$c['port'], '--username='.$user, '--dbname='.$c['database'], '--file='.$file]);
        if (! $result->successful()) {
            throw new RuntimeException('pg_dump falló: '.trim($result->errorOutput() ?: $result->output()));
        }
        if (! is_file($file) || filesize($file) === 0) {
            throw new RuntimeException('pg_dump no generó el archivo.');
        }
    }

    private function encrypt(string $in, string $out, string $key): void
    {
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        fwrite($dst, self::MAGIC.$header);
        $size = filesize($in);
        $read = 0;
        do {
            $chunk = (string) fread($src, self::CHUNK);
            $read += strlen($chunk);
            $last = $read >= $size;
            $tag = $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
            fwrite($dst, pack('N', strlen($cipher)).$cipher);
        } while (! $last);
        fclose($src);
        fclose($dst);
    }

    private function key(string $target): string
    {
        $raw = (string) config($target === 'medical' ? 'escudo.backups.medical_key' : 'escudo.backups.key');
        $key = str_starts_with($raw, 'base64:') ? base64_decode(substr($raw, 7), true) : false;
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('Falta la llave de cifrado de copias ('.($target === 'medical' ? 'BACKUP_MEDICAL_KEY' : 'BACKUP_KEY').') o no es válida.');
        }

        return $key;
    }

    private function hasKey(string $target): bool
    {
        try {
            $this->key($target);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Quita contraseñas o cadenas de conexión de un mensaje de error antes de guardarlo. */
    private function clean(string $msg): string
    {
        $pass = (string) config('escudo.backups.db_password');

        return $pass !== '' ? str_replace($pass, '***', $msg) : $msg;
    }
}
