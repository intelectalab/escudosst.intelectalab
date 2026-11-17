<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-PLA RN-PLA-01/02/05/07: el plan se liga a su línea base, a la política vigente, al PASST y al programa de capacitación del año;
 * tiene versiones (una nueva versión parte de la anterior y también se aprueba).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table annual_plans add column if not exists baseline_assessment_id uuid references baseline_assessments(id)');
        DB::statement('alter table annual_plans add column if not exists policy_id uuid references sst_policies(id)');
        DB::statement('alter table annual_plans add column if not exists training_program_id uuid references training_programs(id)');
        DB::statement('alter table annual_plans add column if not exists version_number integer not null default 1');
        DB::statement('alter table annual_plans add column if not exists previous_plan_id uuid references annual_plans(id)');
    }

    public function down(): void
    {
        foreach (['previous_plan_id', 'version_number', 'training_program_id', 'policy_id', 'baseline_assessment_id'] as $c) {
            DB::statement("alter table annual_plans drop column if exists {$c}");
        }
    }
};
