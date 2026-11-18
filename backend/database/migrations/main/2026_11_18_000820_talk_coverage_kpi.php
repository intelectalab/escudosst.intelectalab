<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-CH5 RN-CH5-05 y §12 («KPI de cobertura en DSH»): indicador de cobertura de charlas del día. La ficha no da umbrales de
 * color: se deja sin ellos (la empresa puede fijarlos en su tablero).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::connection('platform')->insert(
            "insert into kpi_definitions (company_id, code, name, description, module_code, formula_key, unit, direction, granularity, is_active)
             select null, 'talk_coverage', 'Cobertura de charlas del día', 'Trabajadores activos con asistencia firmada a una charla de cinco minutos hoy, sobre los trabajadores activos',
                    'CH5', 'talk_coverage_pct', '%', 'higher_better', 'daily', true
              where not exists (select 1 from kpi_definitions where company_id is null and code = 'talk_coverage')");
    }

    public function down(): void
    {
    }
};
