<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-API: tokens OAuth2 «client credentials» del cliente (se guarda solo su huella) y llaves de idempotencia de las
 * escrituras por API (RN-API-06: la misma Idempotency-Key devuelve la respuesta original; con otro cuerpo, 409).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create table if not exists api_access_tokens (
                id uuid primary key default uuidv7(),
                company_id uuid not null references companies(id),
                api_client_id uuid not null references api_clients(id),
                token_hash text not null unique,
                scopes text[] not null default '{}',
                expires_at timestamptz not null,
                revoked_at timestamptz,
                created_at timestamptz not null default now()
            )
        SQL);
        DB::statement(<<<'SQL'
            create table if not exists api_idempotency_keys (
                id uuid primary key default uuidv7(),
                company_id uuid not null references companies(id),
                api_client_id uuid not null references api_clients(id),
                idempotency_key text not null,
                request_hash text not null,
                response_status integer not null,
                response_body jsonb not null,
                created_at timestamptz not null default now(),
                unique (api_client_id, idempotency_key)
            )
        SQL);
        foreach (['api_access_tokens', 'api_idempotency_keys'] as $t) {
            DB::statement("alter table {$t} enable row level security");
            DB::statement("alter table {$t} force row level security");
            DB::statement("drop policy if exists rls_{$t}_company on {$t}");
            DB::statement("create policy rls_{$t}_company on {$t} using (company_id = (nullif(current_setting('app.company_id', true), ''))::uuid)
                           with check (company_id = (nullif(current_setting('app.company_id', true), ''))::uuid)");
            foreach (['escudo_app', 'escudo_definer'] as $role) {
                if (DB::selectOne('select 1 as ok from pg_roles where rolname = ?', [$role])) {
                    DB::statement("grant select, insert, update on {$t} to {$role}");
                }
            }
        }
    }

    public function down(): void
    {
        DB::statement('drop table if exists api_idempotency_keys');
        DB::statement('drop table if exists api_access_tokens');
    }
};
