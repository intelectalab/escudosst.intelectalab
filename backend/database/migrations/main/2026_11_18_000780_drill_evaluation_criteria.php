<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-SIM SCR-SIM-03: la evaluación del simulacro usa criterios configurables. La ficha no nombra los criterios, así que el
 * parámetro nace vacío y «por confirmar»: cada empresa (o la plataforma) carga su lista; mientras tanto se evalúa con
 * observaciones.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
             select null, 'drill_evaluation_criteria', '[]', 'json', null, 'Criterios con los que se evalúa un simulacro (lista de textos)', 'M-SIM SCR-SIM-03', 'M-SIM', date '2012-04-25', true
              where not exists (select 1 from legal_parameters where company_id is null and key = 'drill_evaluation_criteria')");
    }

    public function down(): void
    {
    }
};
