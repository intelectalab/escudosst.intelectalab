<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * IPERC (M-IPC RN-IPC-08 y §10): periodicidad de revisión y aviso previo, como parámetros con vigencia
 * y «a confirmar» por especialista SST/legal (Ley 29783, art. 57: al menos una vez al año).
 */
return new class extends Migration
{
    private const PARAMS = [
        ['iperc_review_months', '12', 'months', 'meses', 'Periodicidad máxima de revisión de la matriz IPERC', 'Ley 29783, art. 57'],
        ['iperc_review_notice_days', '30', 'days', 'días', 'Días de anticipación del aviso de revisión anual del IPERC', 'M-IPC §10'],
    ];

    public function up(): void
    {
        foreach (self::PARAMS as [$key, $value, $type, $unit, $description, $reference]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, ?, ?, ?, ?, 'M-IPC', date '2012-04-25', true
                  where not exists (select 1 from legal_parameters where company_id is null and key = ?)",
                [$key, $value, $type, $unit, $description, $reference, $key]
            );
        }
    }

    public function down(): void
    {
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key in ('iperc_review_months', 'iperc_review_notice_days')");
    }
};
