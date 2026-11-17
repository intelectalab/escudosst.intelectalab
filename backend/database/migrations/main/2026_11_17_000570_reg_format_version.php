<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-REG RN-REG-06: versión vigente del formato de los registros obligatorios como parámetro con vigencia e historial
 * (cambiarla no altera las exportaciones previas, que guardan su propia versión).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
             select null, 'reg_format_version', 'rm050-v1', 'text', null, 'Versión vigente de los formatos de los registros obligatorios', 'R.M. 050-2013-TR · M-REG RN-REG-06', 'M-REG', date '2013-03-15', false
              where not exists (select 1 from legal_parameters where company_id is null and key = 'reg_format_version')");
    }

    public function down(): void
    {
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key = 'reg_format_version'");
    }
};
