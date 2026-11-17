<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-NOT §7 y RN-NOT-07: reintentos con retroceso hasta max_retries y correo del usuario marcado como inválido al rebotar
 * max_bounces veces (se avisa al administrador). La ficha no fija los valores: parámetros «por confirmar».
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const PARAMS = [
        ['max_retries', '3', 'Reintentos de un aviso por correo que falló (con espera creciente entre intentos)'],
        ['max_bounces', '3', 'Rebotes de correo tras los que el correo del usuario se marca como inválido'],
    ];

    public function up(): void
    {
        DB::statement('alter table users add column if not exists email_invalid_at timestamptz');
        foreach (self::PARAMS as [$key, $value, $desc]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, 'integer', null, ?, 'M-NOT §7, RN-NOT-07', 'M-NOT', date '2012-04-25', true where not exists (select 1 from legal_parameters where company_id is null and key = ?)",
                [$key, $value, $desc, $key]);
        }
    }

    public function down(): void
    {
        DB::statement('alter table users drop column if exists email_invalid_at');
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key in ('max_retries', 'max_bounces')");
    }
};
