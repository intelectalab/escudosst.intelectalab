<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-EQE §5 y RN-EQE-01/02/07/08: fechas de recarga y prueba hidrostática, fecha de fabricación (vida útil) y próxima inspección del
 * equipo; mínimo de extintores por sede (lo configura la empresa). Parámetros nombrados en la ficha, con valor de referencia a
 * confirmar con la NTP: extinguisher_recharge_months, hydrostatic_test_years, equipment_check_days, extinguisher_max_life_years.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['last_recharge_on', 'next_recharge_on', 'last_hydrostatic_test_on', 'next_hydrostatic_test_on', 'manufacture_date', 'next_inspection_on'] as $c) {
            DB::statement("alter table emergency_equipment add column if not exists {$c} date");
        }
        DB::statement('alter table sites add column if not exists min_extinguishers integer check (min_extinguishers is null or min_extinguishers >= 0)');
        foreach ([
            ['extinguisher_recharge_months', '12', 'meses', 'Cada cuántos meses se recarga un extintor (próxima recarga = última recarga + este valor)'],
            ['hydrostatic_test_years', '5', 'años', 'Cada cuántos años se hace la prueba hidrostática del extintor'],
            ['equipment_check_days', '30', 'días', 'Cada cuántos días se inspecciona cada equipo de emergencia; sin inspección queda «inspección vencida»'],
            ['extinguisher_max_life_years', '20', 'años', 'Vida útil máxima del extintor desde su fabricación; superada, queda «fuera de servicio»'],
        ] as [$key, $value, $unit, $desc]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, 'integer', ?, ?, 'NTP de extintores (a confirmar) · M-EQE', 'M-EQE', date '2012-04-25', true
                  where not exists (select 1 from legal_parameters where company_id is null and key = ?)", [$key, $value, $unit, $desc, $key]);
        }
    }

    public function down(): void
    {
        foreach (['last_recharge_on', 'next_recharge_on', 'last_hydrostatic_test_on', 'next_hydrostatic_test_on', 'manufacture_date', 'next_inspection_on'] as $c) {
            DB::statement("alter table emergency_equipment drop column if exists {$c}");
        }
        DB::statement('alter table sites drop column if exists min_extinguishers');
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key in ('extinguisher_recharge_months', 'hydrostatic_test_years', 'equipment_check_days', 'extinguisher_max_life_years')");
    }
};
