<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-PAS §5 y RN-PAS-01: cada ejecución tiene su estado (ejecutada / parcial) y su mes programado; solo cuentan las validadas y la
 * parcial pesa partial_execution_weight (parámetro a confirmar). RN-PAS-03: versiones del programa (version_number, previous_program_id,
 * y cada actividad recuerda la de la versión anterior para llevar sus ejecuciones).
 * M-MRL SCR-MRL-04 y RN-MRL-08: la evidencia de la evaluación puede ser un registro del sistema (tipo + id, de la misma empresa).
 * M-PCA RN-PCA-07: los temas obligatorios por puesto pasan a requisitos del fotocheck (credential_requirements.training_program_item_id).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("alter table activity_executions add column if not exists status text not null default 'executed' check (status in ('executed', 'partial'))");
        DB::statement('alter table activity_executions add column if not exists planned_month smallint check (planned_month is null or planned_month between 1 and 12)');

        DB::statement('alter table annual_programs add column if not exists version_number integer not null default 1');
        DB::statement('alter table annual_programs add column if not exists previous_program_id uuid references annual_programs(id)');
        DB::statement('drop index if exists uq_annual_programs_1');
        DB::statement('create unique index if not exists uq_annual_programs_1 on annual_programs (company_id, year, version_number) where deleted_at is null');
        DB::statement('alter table program_activities add column if not exists previous_activity_id uuid references program_activities(id)');

        DB::statement('alter table requirement_evaluations add column if not exists evidence_record_type text');
        DB::statement('alter table requirement_evaluations add column if not exists evidence_record_id uuid');

        DB::statement('alter table credential_requirements add column if not exists training_program_item_id uuid references training_program_items(id)');

        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
             select null, 'partial_execution_weight', '0.5', 'decimal', 'factor', 'Cuánto cuenta una ejecución parcial del programa anual (1 = como ejecutada, 0 = no cuenta)',
                    'M-PAS RN-PAS-01', 'M-PAS', date '2012-04-25', true
              where not exists (select 1 from legal_parameters where company_id is null and key = 'partial_execution_weight')");
    }

    public function down(): void
    {
        DB::statement('alter table activity_executions drop column if exists status');
        DB::statement('alter table activity_executions drop column if exists planned_month');
        DB::statement('alter table program_activities drop column if exists previous_activity_id');
        DB::statement('drop index if exists uq_annual_programs_1');
        DB::statement('create unique index if not exists uq_annual_programs_1 on annual_programs (company_id, year) where deleted_at is null');
        DB::statement('alter table annual_programs drop column if exists previous_program_id');
        DB::statement('alter table annual_programs drop column if exists version_number');
        DB::statement('alter table requirement_evaluations drop column if exists evidence_record_type');
        DB::statement('alter table requirement_evaluations drop column if exists evidence_record_id');
        DB::statement('alter table credential_requirements drop column if exists training_program_item_id');
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key = 'partial_execution_weight'");
    }
};
