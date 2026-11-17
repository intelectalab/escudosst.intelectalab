<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-OFF RN-OFF-06: las fotos tomadas sin conexión se comprimen en el equipo hasta offline_photo_max_kb. La ficha no fija
 * el valor: parámetro «por confirmar».
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
             select null, 'offline_photo_max_kb', '800', 'integer', 'KB', 'Tamaño máximo de una foto tomada sin conexión (se comprime en el equipo)', 'M-OFF RN-OFF-06', 'M-OFF', date '2012-04-25', true
              where not exists (select 1 from legal_parameters where company_id is null and key = 'offline_photo_max_kb')");
    }

    public function down(): void
    {
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key = 'offline_photo_max_kb'");
    }
};
