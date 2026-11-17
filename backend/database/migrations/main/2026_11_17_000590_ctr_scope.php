<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-CTR §5: la acreditación cubre varias sedes (site_ids) y, si se indica, solo a ciertos trabajadores (worker_ids);
 * RN-CTR-06: fuera de esas sedes no habilita el ingreso. RN-CTR-05: el requisito EMO pide solo la constancia de aptitud.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("alter table contractor_accreditations add column if not exists site_ids uuid[] not null default '{}', add column if not exists contractor_worker_ids uuid[] not null default '{}'");
        DB::statement("update contractor_accreditations set site_ids = array[site_id] where site_id is not null and site_ids = '{}'");
        DB::statement('alter table contractor_documents add column if not exists fitness_result text');
        DB::statement('alter table contractor_documents drop constraint if exists ck_contractor_documents_fitness');
        DB::statement("alter table contractor_documents add constraint ck_contractor_documents_fitness check (fitness_result is null or fitness_result in ('fit', 'fit_with_restrictions', 'unfit'))");
    }

    public function down(): void
    {
        DB::statement('alter table contractor_accreditations drop column if exists site_ids, drop column if exists contractor_worker_ids');
        DB::statement('alter table contractor_documents drop constraint if exists ck_contractor_documents_fitness, drop column if exists fitness_result');
    }
};
