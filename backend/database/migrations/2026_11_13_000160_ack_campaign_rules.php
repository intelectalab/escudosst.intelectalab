<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cargos y avisos según las fichas:
 *  - M-POL §5: la política vigente se publica como versión de documento (document_version_id) para su campaña de cargos (RN-POL-04).
 *  - Parámetros (a confirmar): M-RIS RN-RIS-06 risst_delivery_grace_days; M-MAG RN-MAG-07 monitoring_frequency_months;
 *    M-AUI external_audit_years.
 */
return new class extends Migration
{
    private const PARAMS = [
        ['risst_delivery_grace_days', '30', 'días', 'Días desde el ingreso de un trabajador para entregarle el RISST con su cargo', 'M-RIS RN-RIS-06', 'M-RIS'],
        ['monitoring_frequency_months', '12', 'meses', 'Cada cuántos meses se mide un punto de monitoreo de un agente (por defecto para todos los agentes)', 'M-MAG RN-MAG-07', 'M-MAG'],
        ['external_audit_years', '2', 'años', 'Cada cuántos años corresponde la auditoría externa legal del SG-SST', 'M-AUI', 'M-AUI'],
    ];

    public function up(): void
    {
        DB::statement('alter table sst_policies add column if not exists document_version_id uuid references document_versions(id)');
        foreach (self::PARAMS as [$key, $value, $unit, $desc, $ref, $source]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, 'integer', ?, ?, ?, ?, date '2012-04-25', true
                  where not exists (select 1 from legal_parameters where company_id is null and key = ?)",
                [$key, $value, $unit, $desc, $ref, $source, $key]
            );
        }
    }

    public function down(): void
    {
        $keys = array_column(self::PARAMS, 0);
        DB::connection('platform')->delete('delete from legal_parameters where company_id is null and key in ('.implode(', ', array_fill(0, count($keys), '?')).')', $keys);
    }
};
