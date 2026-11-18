<?php

namespace App\Modules\AUD;

use App\Core\Support\LegalParams;
use App\Modules\SUS\Services\AccountsService;
use App\Modules\SUS\Services\RemoteBackupStore;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * M-AUD RN-AUD-06: cada mes de la bitácora que pasó audit_retention_years se exporta por empresa (líneas JSON comprimidas y
 * cifradas con una llave derivada de la del sistema), se guarda en el disco de copias y se sube al almacenamiento externo
 * si está configurado. Los registros NO se borran: quedan en la base hasta que alguien apruebe la purga.
 */
final class AuditArchive
{
    public function __construct(private AccountsService $accounts, private RemoteBackupStore $remote) {}

    /** @return int meses archivados en esta pasada */
    public function run(?string $before = null): int
    {
        $years = max(1, (int) LegalParams::number('audit_retention_years', 5));
        $cutoff = $before ?? date('Y-m-01', strtotime("-{$years} years"));
        $n = 0;
        $companies = DB::connection('platform')->select('select id from companies order by id');
        foreach ($companies as $c) {
            $n += $this->accounts->withCompany($c->id, fn (ConnectionInterface $db) => $this->company($db, $c->id, $cutoff));
        }

        return $n;
    }

    private function company(ConnectionInterface $db, string $company, string $cutoff): int
    {
        $n = 0;
        $months = $db->select("select distinct date_trunc('month', occurred_at)::date as m from audit_logs where company_id = ? and occurred_at < ?::date
                                and date_trunc('month', occurred_at)::date not in (select period from audit_archives where company_id = ?) order by 1", [$company, $cutoff, $company]);
        foreach ($months as $mo) {
            // to_jsonb deja los campos binarios (huellas) en hexadecimal y respeta los tipos.
            $rows = $db->select("select to_jsonb(l)::text as j from audit_logs l where company_id = ? and occurred_at >= ?::date and occurred_at < (?::date + interval '1 month')
                                  order by occurred_at, id", [$company, $mo->m, $mo->m]);
            $ndjson = implode("\n", array_column($rows, 'j'));
            $sealed = self::seal(gzencode($ndjson, 9));
            $key = 'audit/'.$company.'/'.substr($mo->m, 0, 7).'.ndjson.gz.enc';
            Storage::disk('backups')->put($key, $sealed);
            $sha = hash('sha256', $sealed);
            [$remoteKey, $status] = [null, 'skipped'];
            if ($this->remote->configured()) {
                try {
                    $this->remote->put($key, Storage::disk('backups')->path($key));
                    [$remoteKey, $status] = [$key, 'ok'];
                } catch (\Throwable) {
                    $status = 'failed';
                }
            }
            $db->insert('insert into audit_archives (company_id, period, rows_count, sha256, local_key, remote_key, remote_status) values (?, ?, ?, ?, ?, ?, ?)',
                [$company, $mo->m, count($rows), $sha, $key, $remoteKey, $status]);
            $n++;
        }

        return $n;
    }

    /** Cifrado autenticado (XSalsa20-Poly1305) con llave derivada de APP_KEY; el nonce va al inicio. */
    public static function seal(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return $nonce.sodium_crypto_secretbox($plain, $nonce, self::key());
    }

    public static function open(string $sealed): string
    {
        $plain = sodium_crypto_secretbox_open(substr($sealed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($sealed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
        if ($plain === false) {
            throw new \RuntimeException('El archivo de la bitácora no se pudo descifrar (llave distinta o archivo alterado).');
        }

        return (string) gzdecode($plain);
    }

    private static function key(): string
    {
        return hash('sha256', 'audit-archive|'.config('app.key'), true);
    }
}
