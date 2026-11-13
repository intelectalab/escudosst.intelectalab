<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Parámetros de API e ingreso (valores a confirmar): M-API RN-API-04 webhook_max_retries; M-ING RN-ING-05 access_retention_days.
 */
return new class extends Migration
{
    private const PARAMS = [
        ['webhook_max_retries', '6', null, 'Reintentos de una entrega de webhook antes de abandonarla', 'M-API RN-API-04', 'M-API'],
        ['access_retention_days', '0', 'días', 'Días que se conservan los eventos de garita (0 = sin purga hasta que la empresa lo defina)', 'M-ING RN-ING-05', 'M-ING'],
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
        DB::connection('platform')->delete('delete from legal_parameters where company_id is null and key in (?, ?)', array_column(self::PARAMS, 0));
    }
};
