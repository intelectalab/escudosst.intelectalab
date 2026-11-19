<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-PLA:
 * - §5 y RN-PLA-07: cada componente del plan tiene su tipo (objetivo, meta, recursos, programa, indicador) y, si es objetivo o
 *   programa, la referencia al registro de la misma empresa y año (reference_type, reference_id).
 * - RN-PLA-04: el plan aprobado guarda la versión del documento generada en la gestión documental (document_version_id).
 * - RN-PLA-08: al cerrar el año queda la fecha, quién lo cerró y el informe de cumplimiento.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("alter table annual_plan_items
            add column if not exists item_type text,
            add column if not exists reference_type text,
            add column if not exists reference_id uuid");
        DB::statement("alter table annual_plan_items drop constraint if exists annual_plan_items_item_type_check");
        DB::statement("alter table annual_plan_items add constraint annual_plan_items_item_type_check
            check (item_type is null or item_type in ('objective', 'goal', 'human_resource', 'material_resource', 'financial_resource', 'program', 'indicator'))");
        DB::statement("alter table annual_plan_items drop constraint if exists annual_plan_items_reference_type_check");
        DB::statement("alter table annual_plan_items add constraint annual_plan_items_reference_type_check
            check (reference_type is null or reference_type in ('sst_objectives', 'annual_programs', 'training_programs'))");
        // Los ítems que ya apuntan a un objetivo quedan como componentes de tipo objetivo con su referencia.
        DB::statement("update annual_plan_items set item_type = 'objective', reference_type = 'sst_objectives', reference_id = objective_id
                        where objective_id is not null and item_type is null");

        DB::statement('alter table annual_plans
            add column if not exists document_version_id uuid,
            add column if not exists closed_at timestamptz,
            add column if not exists closed_by uuid references users(id),
            add column if not exists closure_report_file_id uuid');
        DB::statement('alter table annual_plans drop constraint if exists annual_plans_document_version_fk');
        DB::statement('alter table annual_plans add constraint annual_plans_document_version_fk foreign key (company_id, document_version_id) references document_versions (company_id, id)');
        DB::statement('alter table annual_plans drop constraint if exists annual_plans_closure_report_fk');
        DB::statement('alter table annual_plans add constraint annual_plans_closure_report_fk foreign key (company_id, closure_report_file_id) references files (company_id, id)');
    }

    public function down(): void
    {
    }
};
