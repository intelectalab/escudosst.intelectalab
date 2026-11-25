<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/08-api.md §8: Idempotency-Key en los POST de creación y en las acciones que generan trabajo. La API guarda clave +
 * usuario (sesión) + empresa + ruta + hash del cuerpo y la respuesta; repetir la misma petición devuelve la respuesta
 * original (idempotency_replay, docs/02 §11) y la misma clave con otro cuerpo responde 409 conflict.
 * Vigencia de las claves: parámetro idempotency_key_hours (docs/08 §8 «Vigencia de las claves: parámetro»).
 * Tabla de plataforma: la escribe el middleware con la conexión de plataforma (no se revierte con la transacción de la
 * petición) y solo guarda la respuesta de las peticiones exitosas.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
create table if not exists idempotency_keys (
    id uuid not null default uuidv7(),
    scope_hash text not null,
    idempotency_key text not null,
    company_id uuid,
    user_id uuid,
    method text not null,
    route text not null,
    request_hash text not null,
    response_status smallint,
    response_body text,
    response_content_type text,
    created_at timestamptz not null default now(),
    completed_at timestamptz,
    expires_at timestamptz not null,
    primary key (id),
    constraint uq_idempotency_keys_scope unique (scope_hash),
    constraint ck_idempotency_keys_key check (length(idempotency_key) between 1 and 100)
)
SQL);
        DB::statement("comment on table idempotency_keys is '[API] Claves Idempotency-Key de los POST (docs/08 §8): respuesta original por clave, sesión, empresa y ruta; vence según idempotency_key_hours'");
        DB::statement("comment on column idempotency_keys.scope_hash is 'sha256 de clave + sesión (o IP en hash si es público) + empresa + método y ruta'");
        DB::statement('create index if not exists idx_idempotency_keys_expires on idempotency_keys (expires_at)');
        DB::statement('alter table idempotency_keys enable row level security');
        DB::statement('alter table idempotency_keys force row level security');
        DB::statement('drop policy if exists rls_idempotency_keys_platform on idempotency_keys');
        DB::statement('create policy rls_idempotency_keys_platform on idempotency_keys to escudo_platform using (true) with check (true)');
        DB::statement('revoke all on idempotency_keys from public, escudo_app');
        DB::statement('grant select, insert, update, delete on idempotency_keys to escudo_platform');

        DB::connection('platform')->statement(<<<'SQL'
            INSERT INTO legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
            SELECT NULL, 'idempotency_key_hours', '24', 'hours', 'horas',
                   'Vigencia de una Idempotency-Key: dentro de este plazo, repetir la misma petición devuelve la respuesta original (docs/08 §8; valor interno de plataforma, configurable)',
                   'Parámetro de plataforma', 'docs/08 §8', '2026-01-01', true
             WHERE NOT EXISTS (SELECT 1 FROM legal_parameters WHERE key = 'idempotency_key_hours' AND company_id IS NULL AND deleted_at IS NULL)
        SQL);
    }

    public function down(): void
    {
        DB::connection('platform')->statement("DELETE FROM legal_parameters WHERE key = 'idempotency_key_hours' AND company_id IS NULL");
        DB::statement('drop table if exists idempotency_keys');
    }
};
