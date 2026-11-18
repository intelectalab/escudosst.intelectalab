<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-DOC RN-DOC-07: los enlaces de descarga firmados duran signed_url_ttl minutos. La ficha nombra el parámetro sin fijar
 * el valor: «por confirmar».
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
             select null, 'signed_url_ttl', '10', 'integer', 'minutos', 'Minutos que dura un enlace de descarga firmado antes de dejar de funcionar', 'M-DOC RN-DOC-07', 'M-DOC', date '2012-04-25', true
              where not exists (select 1 from legal_parameters where company_id is null and key = 'signed_url_ttl')");
    }

    public function down(): void
    {
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key = 'signed_url_ttl'");
    }
};
