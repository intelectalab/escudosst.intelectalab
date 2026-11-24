<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-PAS §5, SCR-PAS-02 y RN-PAS-05: la actividad del programa lleva su sede (y área) y su regla de ejecución automática.
 * Con la regla «al registrarse en el módulo origen», el registro del módulo vinculado (inspección finalizada, simulacro
 * evaluado, capacitación realizada) de la misma sede/área y de un mes programado marca la actividad como ejecutada,
 * pendiente de validación, aunque no se haya vinculado a mano.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('alter table program_activities add column if not exists site_id uuid');
        DB::statement('alter table program_activities add column if not exists area_id uuid');
        DB::statement("alter table program_activities add column if not exists auto_execution_rule text not null default 'manual'");
        DB::statement('alter table program_activities drop constraint if exists ck_program_activities_auto_execution_rule');
        DB::statement("alter table program_activities add constraint ck_program_activities_auto_execution_rule check (auto_execution_rule in ('manual', 'on_source_record'))");
        DB::statement('alter table program_activities drop constraint if exists fk_program_activities_site_id');
        DB::statement('alter table program_activities add constraint fk_program_activities_site_id foreign key (company_id, site_id) references sites (company_id, id)');
        DB::statement('alter table program_activities drop constraint if exists fk_program_activities_area_id');
        DB::statement('alter table program_activities add constraint fk_program_activities_area_id foreign key (company_id, area_id) references areas (company_id, id)');
        DB::statement('create index if not exists idx_program_activities_fk_site_id on program_activities (company_id, site_id)');
        DB::statement('create index if not exists idx_program_activities_fk_area_id on program_activities (company_id, area_id)');
    }

    public function down(): void
    {
        DB::statement('alter table program_activities drop constraint if exists fk_program_activities_area_id');
        DB::statement('alter table program_activities drop constraint if exists fk_program_activities_site_id');
        DB::statement('alter table program_activities drop column if exists auto_execution_rule');
        DB::statement('alter table program_activities drop column if exists area_id');
        DB::statement('alter table program_activities drop column if exists site_id');
    }
};
