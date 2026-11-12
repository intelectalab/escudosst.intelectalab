<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de permisos de trabajo, ATS, charlas e inspecciones según sus fichas:
 *  - M-ATS RN-ATS-02: firma tardía de quien se suma a un ATS ya firmado; RN-ATS-07: tarea de alto riesgo exige PETAR.
 *  - M-PTR RN-PTR-03: rangos permitidos de gases (parámetros a confirmar; D.S. 024-2016-EM art. 135 y normas de espacio confinado).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table ats_participants add column if not exists late_signature boolean not null default false');
        DB::statement('alter table ats_forms add column if not exists is_high_risk boolean not null default false');

        foreach ([
            ['gas_o2_min_pct', '19.5', '%', 'Oxígeno mínimo para iniciar un trabajo con medición de gases'],
            ['gas_o2_max_pct', '22.5', '%', 'Oxígeno máximo para iniciar un trabajo con medición de gases'],
            ['gas_lel_max_pct', '10', '%', 'Límite de explosividad (LEL) a partir del cual no se inicia el trabajo'],
            ['gas_co_max_ppm', '25', 'ppm', 'Monóxido de carbono (CO) a partir del cual no se inicia el trabajo'],
            ['gas_h2s_max_ppm', '10', 'ppm', 'Sulfuro de hidrógeno (H₂S) a partir del cual no se inicia el trabajo'],
        ] as [$key, $value, $unit, $desc]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, 'decimal', ?, ?, 'D.S. 024-2016-EM art. 135 · M-PTR RN-PTR-03', 'M-PTR', date '2016-07-28', true
                  where not exists (select 1 from legal_parameters where company_id is null and key = ?)", [$key, $value, $unit, $desc, $key]);
        }
    }

    public function down(): void
    {
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key in ('gas_o2_min_pct', 'gas_o2_max_pct', 'gas_lel_max_pct', 'gas_co_max_ppm', 'gas_h2s_max_ppm')");
    }
};
