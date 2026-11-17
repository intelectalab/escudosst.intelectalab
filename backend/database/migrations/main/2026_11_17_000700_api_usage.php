<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-API §11: uso de la API por cliente (consultas por día y recurso, y las rechazadas por falta de permiso).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create table if not exists api_usage (
                id uuid primary key default uuidv7(),
                company_id uuid not null references companies(id),
                api_client_id uuid not null references api_clients(id),
                day date not null,
                resource text not null,
                calls integer not null default 0,
                denied integer not null default 0,
                updated_at timestamptz not null default now(),
                unique (api_client_id, day, resource)
            )
        SQL);
        DB::statement('alter table api_usage enable row level security');
        DB::statement('alter table api_usage force row level security');
        DB::statement('drop policy if exists rls_api_usage_company on api_usage');
        DB::statement("create policy rls_api_usage_company on api_usage using (company_id = (nullif(current_setting('app.company_id', true), ''))::uuid)
                       with check (company_id = (nullif(current_setting('app.company_id', true), ''))::uuid)");
        foreach (['escudo_app', 'escudo_definer'] as $role) {
            if (DB::selectOne('select 1 as ok from pg_roles where rolname = ?', [$role])) {
                DB::statement("grant select, insert, update on api_usage to {$role}");
            }
        }
    }

    public function down(): void
    {
        DB::statement('drop table if exists api_usage');
    }
};
