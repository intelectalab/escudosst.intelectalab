<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-EST: horas-hombre validadas (man_hours.validated_by, RN-EST-03), ajustes de un mes cerrado con motivo y usuario
 * (monthly_statistics.adjustments, RN-EST-08) y los parámetros include_contractors_in_stats (RN-EST-07) y lost_days_allocation
 * (RN-EST-04: event_month = al mes del evento; loss_month = al mes en que se pierden), a confirmar.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table man_hours add column if not exists validated_by uuid');
        DB::statement('alter table man_hours add column if not exists validated_at timestamptz');
        DB::statement("alter table monthly_statistics add column if not exists adjustments jsonb not null default '[]'::jsonb");
        DB::statement('alter table monthly_statistics add column if not exists includes_contractors boolean not null default false');
        foreach ([
            ['include_contractors_in_stats', 'false', 'boolean', null, 'Las horas-hombre y los eventos de contratistas se incluyen en las estadísticas de la empresa', 'M-EST RN-EST-07'],
            ['lost_days_allocation', 'event_month', 'text', null, 'Imputación de los días perdidos: event_month (al mes del evento) o loss_month (al mes en que se pierden)', 'M-EST RN-EST-04'],
        ] as [$key, $value, $type, $unit, $desc, $ref]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, ?, ?, ?, ?, 'M-EST', date '2012-04-25', true where not exists (select 1 from legal_parameters where company_id is null and key = ?)",
                [$key, $value, $type, $unit, $desc, $ref, $key]);
        }
    }

    public function down(): void
    {
        DB::statement('alter table man_hours drop column if exists validated_by');
        DB::statement('alter table man_hours drop column if exists validated_at');
        DB::statement('alter table monthly_statistics drop column if exists adjustments');
        DB::statement('alter table monthly_statistics drop column if exists includes_contractors');
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key in ('include_contractors_in_stats', 'lost_days_allocation')");
    }
};
