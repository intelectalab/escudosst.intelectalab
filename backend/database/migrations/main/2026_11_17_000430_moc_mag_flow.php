<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-MOC §9 y RN-MOC-01/03: nivel de impacto (los de impacto alto los aprueba gerencia), revisores obligatorios por especialidad
 * (una revisión asignada queda pendiente hasta que el revisor decide) y estado «verificado» antes de cerrar.
 * M-MAG §9 y RN-MAG-05/08: la medición pasa por borrador → aprobada → anulada (con motivo). Las registradas antes quedan aprobadas.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("alter table change_requests add column if not exists impact_level text check (impact_level is null or impact_level in ('low', 'medium', 'high'))");
        DB::statement('alter table change_requests add column if not exists management_approved_by uuid');
        DB::statement('alter table change_requests add column if not exists management_approved_at timestamptz');
        DB::statement('alter table change_requests add column if not exists verified_by uuid');
        DB::statement('alter table change_requests add column if not exists verified_at timestamptz');
        DB::statement('alter table change_requests add column if not exists verification_notes text');
        DB::statement('alter table change_requests drop constraint if exists change_requests_status_check');
        DB::statement("alter table change_requests add constraint change_requests_status_check check (status in ('draft', 'submitted', 'under_review', 'approved', 'rejected', 'implemented', 'verified', 'closed', 'cancelled'))");

        DB::statement('alter table change_reviews add column if not exists is_required boolean not null default true');
        DB::statement('alter table change_reviews alter column decision drop not null');
        DB::statement('alter table change_reviews alter column reviewed_at drop not null');

        DB::statement("alter table measurements add column if not exists status text not null default 'approved' check (status in ('draft', 'approved', 'voided'))");
        DB::statement("alter table measurements alter column status set default 'draft'");
        DB::statement('alter table measurements add column if not exists void_reason text');
        DB::statement('alter table measurements add column if not exists approved_by uuid');
        DB::statement('alter table measurements add column if not exists approved_at timestamptz');
    }

    public function down(): void
    {
        foreach (['impact_level', 'management_approved_by', 'management_approved_at', 'verified_by', 'verified_at', 'verification_notes'] as $c) {
            DB::statement("alter table change_requests drop column if exists {$c}");
        }
        DB::statement("update change_requests set status = 'implemented' where status = 'verified'");
        DB::statement('alter table change_requests drop constraint if exists change_requests_status_check');
        DB::statement("alter table change_requests add constraint change_requests_status_check check (status in ('draft', 'submitted', 'under_review', 'approved', 'rejected', 'implemented', 'closed', 'cancelled'))");
        DB::statement('alter table change_reviews drop column if exists is_required');
        foreach (['status', 'void_reason', 'approved_by', 'approved_at'] as $c) {
            DB::statement("alter table measurements drop column if exists {$c}");
        }
    }
};
