<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-CAP RN-CAP-14 / SCR-CAP-13: límite de consultas de verificación pública por IP y minuto. La ficha nombra el parámetro
 * sin valor: nace «por confirmar» con 30 (el límite que ya tenía la ruta).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
             select null, 'certificate_verify_rate_limit', '30', 'integer', 'consultas por minuto', 'Consultas de verificación pública de certificados por IP y minuto', 'M-CAP RN-CAP-14', 'M-CAP', date '2012-04-25', true
              where not exists (select 1 from legal_parameters where company_id is null and key = 'certificate_verify_rate_limit')");
    }

    public function down(): void
    {
    }
};
