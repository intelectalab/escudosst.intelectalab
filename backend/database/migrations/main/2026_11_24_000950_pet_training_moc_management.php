<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-PET RN-PET-03: al aprobarse una versión del PETS se crea su capacitación de difusión (training_id) y queda exigida
 * a los puestos vinculados en la matriz de CAP; una versión nueva trae su propia capacitación (hay que volver a capacitar).
 * M-MOC RN-MOC-03 / criterio 3: el cambio de impacto alto aprobado por el Jefe SST queda «pendiente de gerencia».
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('alter table procedure_versions add column if not exists training_id uuid');
        DB::statement('alter table procedure_versions drop constraint if exists fk_procedure_versions_training_id');
        DB::statement('alter table procedure_versions add constraint fk_procedure_versions_training_id foreign key (company_id, training_id) references trainings (company_id, id)');
        DB::statement('create index if not exists idx_procedure_versions_fk_training_id on procedure_versions (company_id, training_id)');
        DB::statement("comment on column procedure_versions.training_id is 'RN-PET-03: capacitación de difusión creada al aprobar la versión'");

        DB::statement('alter table change_requests drop constraint if exists change_requests_status_check');
        DB::statement("alter table change_requests add constraint change_requests_status_check check (status in ('draft', 'submitted', 'under_review', 'pending_management', 'approved', 'rejected', 'implemented', 'verified', 'closed', 'cancelled'))");
    }

    public function down(): void
    {
        DB::statement("update change_requests set status = 'under_review' where status = 'pending_management'");
        DB::statement('alter table change_requests drop constraint if exists change_requests_status_check');
        DB::statement("alter table change_requests add constraint change_requests_status_check check (status in ('draft', 'submitted', 'under_review', 'approved', 'rejected', 'implemented', 'verified', 'closed', 'cancelled'))");
        DB::statement('alter table procedure_versions drop constraint if exists fk_procedure_versions_training_id');
        DB::statement('alter table procedure_versions drop column if exists training_id');
    }
};
