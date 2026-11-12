<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Parámetros de las reglas de contratistas, SCTR, proveedores y estructura (valores a confirmar por la empresa):
 *  - M-TEN RN-TEN-08 max_area_depth; M-SCT sctr_policy_notice_days; M-CTR contractor_doc_notice_days;
 *  - M-HOM RN-HOM-03 vendor_validity_months y RN-HOM-05 vendor_notice_days.
 */
return new class extends Migration
{
    private const PARAMS = [
        ['max_area_depth', '5', null, 'Niveles máximos del árbol de áreas', 'M-TEN RN-TEN-08', 'M-TEN'],
        ['sctr_policy_notice_days', '15', 'días', 'Días antes del fin de una póliza SCTR en que pasa a «por vencer» y se avisa', 'M-SCT', 'M-SCT'],
        ['contractor_doc_notice_days', '15', 'días', 'Días antes del vencimiento de un documento de contratista en que se avisa', 'M-CTR RN-CTR-03', 'M-CTR'],
        ['vendor_validity_months', '12', 'meses', 'Vigencia de la homologación aprobada de un proveedor', 'M-HOM RN-HOM-03', 'M-HOM'],
        ['vendor_notice_days', '30', 'días', 'Días antes del fin de la homologación en que se avisa', 'M-HOM RN-HOM-05', 'M-HOM'],
    ];

    public function up(): void
    {
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
        DB::connection('platform')->delete('delete from legal_parameters where company_id is null and key in (?, ?, ?, ?, ?)', array_column(self::PARAMS, 0));
    }
};
