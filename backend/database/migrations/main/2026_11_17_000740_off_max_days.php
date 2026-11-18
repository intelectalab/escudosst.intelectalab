<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-OFF RN-OFF-07: un dispositivo sin sincronizar en offline_max_days bloquea nuevas capturas hasta sincronizar. La ficha
 * nombra el parámetro pero no fija el valor: «por confirmar».
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
             select null, 'offline_max_days', '7', 'days', 'días', 'Días sin sincronizar tras los que el dispositivo deja de aceptar nuevas capturas', 'M-OFF RN-OFF-07', 'M-OFF', date '2012-04-25', true
              where not exists (select 1 from legal_parameters where company_id is null and key = 'offline_max_days')");
    }

    public function down(): void
    {
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key = 'offline_max_days'");
    }
};
