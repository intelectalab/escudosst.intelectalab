<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-ING RN-ING-06: sin conexión la garita usa la última lista de estados sincronizada (antigüedad máxima
 * access_offline_max_age) y los eventos quedan marcados como offline. La ficha no fija el valor: parámetro «por confirmar».
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('alter table access_events add column if not exists is_offline boolean not null default false');
        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
             select null, 'access_offline_max_age', '12', 'hours', 'horas', 'Antigüedad máxima de la lista de estados que usa la garita sin conexión', 'M-ING RN-ING-06', 'M-ING', date '2012-04-25', true
              where not exists (select 1 from legal_parameters where company_id is null and key = 'access_offline_max_age')");
    }

    public function down(): void
    {
        DB::statement('alter table access_events drop column if exists is_offline');
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key = 'access_offline_max_age'");
    }
};
