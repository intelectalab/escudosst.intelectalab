<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-ATS RN-ATS-02 («si se firma después, queda marcada "firmado tardío" y cuenta en un indicador») y §11 («indicador de ATS
 * firmados antes de iniciar»): dos indicadores en DSH. La ficha no da umbrales de color: se dejan sin ellos.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $p = DB::connection('platform');
        $p->insert(
            "insert into kpi_definitions (company_id, code, name, description, module_code, formula_key, unit, direction, granularity, is_active)
             select null, 'ats_signed_before_start', 'ATS firmados antes de iniciar', 'ATS del periodo en que todos los participantes firmaron antes de iniciar el trabajo, sobre los ATS firmados del periodo',
                    'ATS', 'ats_signed_before_start_pct', '%', 'higher_better', 'monthly', true
              where not exists (select 1 from kpi_definitions where company_id is null and code = 'ats_signed_before_start')");
        $p->insert(
            "insert into kpi_definitions (company_id, code, name, description, module_code, formula_key, unit, direction, granularity, is_active)
             select null, 'ats_late_signatures', 'Firmas tardías de ATS', 'Firmas de participantes registradas después de iniciado o firmado el ATS (firma tardía) en los ATS del periodo',
                    'ATS', 'ats_late_signatures_count', null, 'lower_better', 'monthly', true
              where not exists (select 1 from kpi_definitions where company_id is null and code = 'ats_late_signatures')");
    }

    public function down(): void
    {
    }
};
