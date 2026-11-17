<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-OBS SCR-OBS-03 y RN-OBS-05: lista de comportamientos a observar (categoría y comportamiento), con versiones: se edita un
 * borrador y al publicarlo queda vigente y la anterior obsoleta. Cada observación guarda con qué versión se hizo.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create table if not exists behavior_lists (
                id uuid primary key default gen_random_uuid(),
                company_id uuid not null references companies(id),
                version_number integer not null,
                status text not null default 'draft' check (status in ('draft', 'current', 'obsolete')),
                items jsonb not null default '[]'::jsonb,
                change_summary text,
                published_at timestamptz,
                published_by uuid,
                created_at timestamptz not null default now(),
                created_by uuid,
                updated_at timestamptz not null default now(),
                updated_by uuid,
                deleted_at timestamptz
            )
        SQL);
        DB::statement('create unique index if not exists behavior_lists_version on behavior_lists (company_id, version_number) where deleted_at is null');
        DB::statement('alter table behavior_lists enable row level security');
        DB::statement('alter table behavior_lists force row level security');
        DB::statement('drop policy if exists rls_behavior_lists_company on behavior_lists');
        DB::statement("create policy rls_behavior_lists_company on behavior_lists using (company_id = (nullif(current_setting('app.company_id', true), ''))::uuid)
                       with check (company_id = (nullif(current_setting('app.company_id', true), ''))::uuid)");
        foreach (['escudo_app', 'escudo_definer'] as $role) {
            if (DB::selectOne('select 1 as ok from pg_roles where rolname = ?', [$role])) {
                DB::statement("grant select, insert, update on behavior_lists to {$role}");
            }
        }
        DB::statement('alter table behavior_observations add column if not exists behavior_list_id uuid references behavior_lists(id)');
    }

    public function down(): void
    {
        DB::statement('alter table behavior_observations drop column if exists behavior_list_id');
        DB::statement('drop table if exists behavior_lists');
    }
};
