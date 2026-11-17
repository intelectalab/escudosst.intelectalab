<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-AMB RN-AMB-01: escalas de severidad y probabilidad de la significancia, configurables por empresa junto con el umbral.
 * La ficha no fija sus valores: parámetros «por confirmar».
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    private const PARAMS = [
        ['amb_severity_max', '5', 'Valor máximo de la escala de severidad del aspecto ambiental'],
        ['amb_probability_max', '5', 'Valor máximo de la escala de probabilidad del aspecto ambiental'],
    ];

    public function up(): void
    {
        foreach (self::PARAMS as [$key, $value, $desc]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, 'integer', null, ?, 'M-AMB RN-AMB-01', 'M-AMB', date '2012-04-25', true where not exists (select 1 from legal_parameters where company_id is null and key = ?)",
                [$key, $value, $desc, $key]);
        }
    }

    public function down(): void
    {
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key in ('amb_severity_max', 'amb_probability_max')");
    }
};
