<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-SUN RN-SUN-01/02: gravedad de la infracción asociada a cada obligación del autodiagnóstico (leve/grave/muy grave) y
 * pesos del puntaje ponderado (sunafil_weight_*). La ficha dice que la gravedad la confirma un especialista y no fija los
 * pesos: todos son parámetros «por confirmar». Mientras nadie marque una obligación como muy grave, no hay aviso de brecha
 * muy grave.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    private const PARAMS = [
        ['sunafil_weight_minor', '1', 'decimal', null, 'Peso en el puntaje SUNAFIL de una obligación cuya infracción es leve', 'M-SUN RN-SUN-02'],
        ['sunafil_weight_serious', '2', 'decimal', null, 'Peso en el puntaje SUNAFIL de una obligación cuya infracción es grave', 'M-SUN RN-SUN-02'],
        ['sunafil_weight_very_serious', '3', 'decimal', null, 'Peso en el puntaje SUNAFIL de una obligación cuya infracción es muy grave', 'M-SUN RN-SUN-02'],
        ['sunafil_obligation_severity', '{"committee":"serious","iperc":"serious","pets":"serious","drills":"serious","registers":"serious","training":"serious","emo":"serious","policy":"serious","ppe":"serious"}',
            'json', null, 'Gravedad de la infracción de cada obligación del autodiagnóstico (minor, serious, very_serious) — la confirma un especialista', 'M-SUN RN-SUN-01; D.S. 019-2006-TR'],
        ['sunafil_gap_threshold_pct', '70', 'decimal', '%', 'Debajo de este porcentaje una obligación cuenta como brecha (no cumple)', 'M-SUN RN-SUN-01'],
    ];

    public function up(): void
    {
        foreach (self::PARAMS as [$key, $value, $type, $unit, $desc, $ref]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, ?, ?, ?, ?, 'M-SUN', date '2012-04-25', true where not exists (select 1 from legal_parameters where company_id is null and key = ?)",
                [$key, $value, $type, $unit, $desc, $ref, $key]);
        }
    }

    public function down(): void
    {
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key in ('sunafil_weight_minor', 'sunafil_weight_serious', 'sunafil_weight_very_serious', 'sunafil_obligation_severity', 'sunafil_gap_threshold_pct')");
    }
};
