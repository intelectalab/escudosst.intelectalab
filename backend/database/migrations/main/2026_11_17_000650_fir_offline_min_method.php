<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-FIR RN-FIR-07: firma capturada sin conexión válida si no excede offline_signature_max_age al sincronizar.
 * RN-FIR-08: método mínimo de firma por tipo de registro (fir_min_method_by_type, json {tabla: método}).
 * La ficha no fija los valores (el de la política con OTP es un ejemplo): parámetros «por confirmar».
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    private const PARAMS = [
        ['offline_signature_max_age', '72', 'hours', 'horas', 'Antigüedad máxima de una firma capturada sin conexión al sincronizarse', 'M-FIR RN-FIR-07'],
        ['fir_min_method_by_type', '{}', 'json', null, 'Método mínimo de firma por tipo de registro (trazo < asistida < contraseña < código OTP)', 'M-FIR RN-FIR-08'],
    ];

    public function up(): void
    {
        foreach (self::PARAMS as [$key, $value, $type, $unit, $desc, $ref]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, ?, ?, ?, ?, 'M-FIR', date '2012-04-25', true where not exists (select 1 from legal_parameters where company_id is null and key = ?)",
                [$key, $value, $type, $unit, $desc, $ref, $key]);
        }
    }

    public function down(): void
    {
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key in ('offline_signature_max_age', 'fir_min_method_by_type')");
    }
};
