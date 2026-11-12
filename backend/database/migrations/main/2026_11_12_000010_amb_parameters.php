<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Medio ambiente (M-AMB): parámetros configurables, «a confirmar» por especialista ambiental.
 *  - amb_significance_threshold (RN-AMB-01): puntaje severidad × probabilidad desde el que un aspecto es significativo.
 *  - waste_manifest_days (§10): días tras los que un residuo peligroso sin manifiesto genera un aviso.
 * Cada empresa puede fijar su propio valor.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            ['amb_significance_threshold', '9', 'integer', null, 'Puntaje (severidad × probabilidad) desde el que un aspecto ambiental es significativo', 'M-AMB RN-AMB-01'],
            ['waste_manifest_days', '30', 'days', 'días', 'Días tras la generación de un residuo peligroso para tener su manifiesto antes de avisar', 'M-AMB §10'],
        ] as [$key, $value, $type, $unit, $desc, $ref]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, ?, ?, ?, ?, 'M-AMB', date '2017-06-22', true
                  where not exists (select 1 from legal_parameters where company_id is null and key = ?)",
                [$key, $value, $type, $unit, $desc, $ref, $key]
            );
        }
    }

    public function down(): void
    {
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key in ('amb_significance_threshold', 'waste_manifest_days')");
    }
};
