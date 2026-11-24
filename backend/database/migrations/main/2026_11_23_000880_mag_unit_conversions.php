<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-MAG RN-MAG-04: tabla de conversión de unidades registrada (por agente o general). Una medición en otra unidad que la del
 * límite se convierte con ella y guarda el valor y la unidad originales; sin conversión registrada se rechaza (criterio 3).
 * No se precargan factores: la ficha no los da; cada empresa (o la plataforma) los registra con su fuente.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
create table if not exists unit_conversions (
    id uuid primary key default uuidv7(),
    company_id uuid references companies(id),
    agent_type_id uuid,
    from_unit text not null,
    to_unit text not null,
    factor numeric(18, 8) not null check (factor > 0),
    source text,
    created_at timestamptz not null default now(),
    created_by uuid references users(id),
    updated_at timestamptz not null default now(),
    updated_by uuid references users(id),
    deleted_at timestamptz,
    check (lower(trim(from_unit)) <> lower(trim(to_unit)))
)
SQL);
        DB::statement('create unique index if not exists uq_unit_conversions_1 on unit_conversions (coalesce(company_id, \'00000000-0000-0000-0000-000000000000\'::uuid), coalesce(agent_type_id, \'00000000-0000-0000-0000-000000000000\'::uuid), lower(trim(from_unit)), lower(trim(to_unit))) where deleted_at is null');
        DB::statement('alter table unit_conversions enable row level security');
        DB::statement('alter table unit_conversions force row level security');
        $co = "(NULLIF(current_setting('app.company_id'::text, true), ''::text))::uuid";
        DB::statement('drop policy if exists rls_unit_conversions_company_read on unit_conversions');
        DB::statement("create policy rls_unit_conversions_company_read on unit_conversions for select to escudo_app using (company_id = {$co} or company_id is null)");
        DB::statement('drop policy if exists rls_unit_conversions_company_insert on unit_conversions');
        DB::statement("create policy rls_unit_conversions_company_insert on unit_conversions for insert to escudo_app with check (company_id = {$co})");
        DB::statement('drop policy if exists rls_unit_conversions_company_update on unit_conversions');
        DB::statement("create policy rls_unit_conversions_company_update on unit_conversions for update to escudo_app using (company_id = {$co}) with check (company_id = {$co})");
        DB::statement('drop policy if exists rls_unit_conversions_platform on unit_conversions');
        DB::statement('create policy rls_unit_conversions_platform on unit_conversions for all to escudo_platform using (company_id is null) with check (company_id is null)');
        DB::statement('grant select, insert, update on unit_conversions to escudo_app');
        DB::statement('grant select, insert, update, delete on unit_conversions to escudo_platform');

        DB::statement('alter table measurements add column if not exists original_value numeric, add column if not exists original_unit text, add column if not exists unit_conversion_id uuid references unit_conversions(id)');
    }

    public function down(): void
    {
    }
};
