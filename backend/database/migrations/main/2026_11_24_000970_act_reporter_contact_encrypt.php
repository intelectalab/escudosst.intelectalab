<?php

use App\Modules\REC\RecordsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-ACT / docs/10: substandard_reports.reporter_contact es [CIFRADO EN APLICACIÓN], pero los reportes por QR y WhatsApp lo
 * guardaban en texto plano (y al leerlo el descifrado fallaba: SST no veía el nombre ni el teléfono). Se cifran en su lugar
 * los valores que no se pueden descifrar. Con RLS por empresa se recorre empresa por empresa con la conexión de la aplicación.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $fixed = 0;
        foreach (DB::connection('platform')->select('select id from companies') as $c) {
            $app = DB::connection('pgsql');
            $fixed += $app->transaction(function () use ($app, $c) {
                $app->select("select set_config('app.company_id', ?, true)", [$c->id]);
                $n = 0;
                foreach ($app->select('select id, reporter_contact from substandard_reports where reporter_contact is not null') as $r) {
                    if (RecordsService::decryptValue($r->reporter_contact) !== null) {
                        continue;   // ya cifrado
                    }
                    $n += $app->update('update substandard_reports set reporter_contact = ? where id = ?', [RecordsService::encryptValue($r->reporter_contact), $r->id]);
                }

                return $n;
            });
        }
        if ($fixed > 0 && app()->runningInConsole()) {
            fwrite(STDOUT, "  reporter_contact cifrado en {$fixed} reporte(s).\n");
        }
    }

    public function down(): void {}
};
