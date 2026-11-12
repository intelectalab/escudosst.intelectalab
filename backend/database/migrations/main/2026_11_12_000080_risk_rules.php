<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de agentes ocupacionales y gestión del cambio según sus fichas:
 *  - M-MAG RN-MAG-02: razón medición/límite y nivel de acción («action_level_ratio», fracción del límite, a confirmar).
 *  - M-MOC RN-MOC-02: el cambio declara si introduce peligros nuevos; RN-MOC-04: fecha de fin del cambio temporal.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table measurements add column if not exists ratio_to_limit numeric(10,4)');
        DB::statement('alter table change_requests add column if not exists introduces_new_hazards boolean not null default false');
        DB::statement('alter table change_requests add column if not exists temporary_until date');
        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
             select null, 'action_level_ratio', '0.5', 'decimal', null, 'Fracción del límite de exposición a partir de la cual la medición queda en «nivel de acción»', 'M-MAG RN-MAG-02', 'M-MAG', date '2012-04-25', true
              where not exists (select 1 from legal_parameters where company_id is null and key = 'action_level_ratio')"
        );
    }

    public function down(): void
    {
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key = 'action_level_ratio'");
    }
};
