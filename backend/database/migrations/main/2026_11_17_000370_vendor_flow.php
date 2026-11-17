<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-HOM §5/§9: la plantilla tiene vigencia en meses (validity_months, RN-HOM-03); la evaluación sigue draft → submitted (autoevaluación
 * del proveedor) → completed (validada y puntuada por SST) → approved / rejected (gerencia) → expired.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table vendor_evaluation_templates add column if not exists validity_months integer check (validity_months is null or validity_months > 0)');
        DB::statement('alter table vendor_evaluations drop constraint if exists vendor_evaluations_status_check');
        DB::statement("alter table vendor_evaluations add constraint vendor_evaluations_status_check check (status in ('draft', 'submitted', 'completed', 'approved', 'rejected', 'expired'))");
        DB::statement('alter table vendor_evaluations add column if not exists approved_by uuid');
        DB::statement('alter table vendor_evaluations add column if not exists approved_at timestamptz');
    }

    public function down(): void
    {
        DB::statement('alter table vendor_evaluation_templates drop column if exists validity_months');
        DB::statement("update vendor_evaluations set status = 'draft' where status in ('submitted', 'rejected')");
        DB::statement('alter table vendor_evaluations drop constraint if exists vendor_evaluations_status_check');
        DB::statement("alter table vendor_evaluations add constraint vendor_evaluations_status_check check (status in ('draft', 'completed', 'approved', 'expired'))");
        DB::statement('alter table vendor_evaluations drop column if exists approved_by');
        DB::statement('alter table vendor_evaluations drop column if exists approved_at');
    }
};
