<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-EMO SCR-EMO-06 «Protocolos por puesto»: exámenes que incluye cada protocolo (R.M. 312-2011-MINSA). El puesto ya guarda
 * requires_emo, emo_protocol_code y emo_periodicity_param (docs/04); aquí se guarda el contenido del protocolo por código.
 * Sin datos clínicos: solo nombres de exámenes y la exposición que los justifica.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create table if not exists emo_protocols (
                id uuid primary key default gen_random_uuid(),
                company_id uuid not null references companies(id),
                code text not null,
                name text not null,
                exams jsonb not null default '[]'::jsonb,
                exposures text,
                notes text,
                created_at timestamptz not null default now(),
                created_by uuid,
                updated_at timestamptz not null default now(),
                updated_by uuid,
                deleted_at timestamptz
            )
        SQL);
        DB::statement('create unique index if not exists emo_protocols_company_code on emo_protocols (company_id, lower(code)) where deleted_at is null');
        DB::statement('alter table emo_protocols enable row level security');
        DB::statement('alter table emo_protocols force row level security');
        DB::statement('drop policy if exists rls_emo_protocols_company on emo_protocols');
        DB::statement("create policy rls_emo_protocols_company on emo_protocols using (company_id = (nullif(current_setting('app.company_id', true), ''))::uuid)
                       with check (company_id = (nullif(current_setting('app.company_id', true), ''))::uuid)");
        foreach (['escudo_app', 'escudo_definer'] as $role) {
            if (DB::selectOne('select 1 as ok from pg_roles where rolname = ?', [$role])) {
                DB::statement("grant select, insert, update on emo_protocols to {$role}");
            }
        }
    }

    public function down(): void
    {
        DB::statement('drop table if exists emo_protocols');
    }
};
