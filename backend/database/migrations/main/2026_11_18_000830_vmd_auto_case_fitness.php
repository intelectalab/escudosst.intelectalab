<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-VMD §7 paso 2 «los resultados de EMO que cumplen criterios abren casos»: la ficha no define los criterios. El parámetro
 * guarda las aptitudes que abren caso (p. ej. ["fit_with_restrictions","unfit"]) y nace vacío y «por confirmar»: mientras el
 * médico no lo defina, ningún EMO abre casos solo.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
             select null, 'vmd_auto_case_fitness', '[]', 'json', null, 'Aptitudes del EMO que abren solas un caso en los programas de vigilancia del puesto', 'M-VMD §7', 'M-VMD', date '2012-04-25', true
              where not exists (select 1 from legal_parameters where company_id is null and key = 'vmd_auto_case_fitness')");
    }

    public function down(): void
    {
    }
};
