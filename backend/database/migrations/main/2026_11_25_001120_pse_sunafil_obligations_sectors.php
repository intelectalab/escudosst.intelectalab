<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-PSE §5 «obligación SUNAFIL» y lista única de sectores:
 *  - sector_pack_items acepta el tipo «sunafil_obligation». Al instalarse queda en sunafil_obligations (obligaciones propias de
 *    la empresa que se suman al catálogo del autodiagnóstico SUNAFIL, M-SUN RN-SUN-01) y se evalúa con los documentos de DOC
 *    vinculados como evidencia (RN-SUN-03: sin evidencia nunca se considera cumplida).
 *  - sector_packs.sector usa la misma lista que companies.economic_sector: «clinic» pasa a «health» (Salud).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const SECTORS = "'construction', 'mining', 'agroindustry', 'manufacturing', 'retail', 'services', 'office', 'health', 'education', 'transport', 'energy', 'other'";

    public function up(): void
    {
        DB::statement('alter table sector_pack_items drop constraint if exists ck_sector_pack_items_type');
        DB::statement("alter table sector_pack_items add constraint ck_sector_pack_items_type check (item_type in ('hazard', 'inspection_template', 'permit_type', 'training_topic', 'document_template',
                       'legal_requirement', 'ppe_item', 'kpi', 'credential_requirement', 'risk_criteria', 'legal_parameter', 'pets_model', 'record_format', 'sunafil_obligation'))");

        DB::statement('alter table sector_packs drop constraint if exists sector_packs_sector_check');
        DB::statement("update sector_packs set sector = 'health' where sector = 'clinic'");
        DB::statement('alter table sector_packs add constraint sector_packs_sector_check check (sector in ('.self::SECTORS.'))');

        DB::statement(<<<'SQL'
create table if not exists sunafil_obligations (
    id uuid not null default uuidv7(),
    company_id uuid not null references companies(id),
    code text not null,
    name text not null,
    legal_basis text,
    severity text not null default 'serious' check (severity in ('minor', 'serious', 'very_serious')),
    evidence_hint text,
    sector_pack_id uuid references sector_packs(id),
    status text not null default 'active' check (status in ('active', 'inactive')),
    created_at timestamptz not null default now(),
    created_by uuid references users(id),
    updated_at timestamptz not null default now(),
    updated_by uuid references users(id),
    deleted_at timestamptz,
    primary key (id),
    constraint uq_sunafil_obligations_company_id_id unique (company_id, id),
    constraint ck_sunafil_obligations_code check (code ~ '^[a-z0-9_]{2,40}$')
)
SQL);
        DB::statement("comment on table sunafil_obligations is '[SUN] Obligación SUNAFIL propia de la empresa (instalada desde un paquete sectorial); se evalúa con evidencia de DOC'");
        DB::statement('create unique index if not exists uq_sunafil_obligations_code on sunafil_obligations (company_id, code) where deleted_at is null');
        DB::statement('drop trigger if exists trg_sunafil_obligations_updated_at on sunafil_obligations');
        DB::statement('create trigger trg_sunafil_obligations_updated_at before update on sunafil_obligations for each row execute function trg_set_updated_at()');
        DB::statement('alter table sunafil_obligations enable row level security');
        DB::statement('alter table sunafil_obligations force row level security');
        $co = "NULLIF(current_setting('app.company_id', true), '')::uuid";
        DB::statement('drop policy if exists rls_sunafil_obligations_company on sunafil_obligations');
        DB::statement("create policy rls_sunafil_obligations_company on sunafil_obligations to escudo_app using (company_id = {$co}) with check (company_id = {$co})");
        DB::statement('grant select, insert, update on sunafil_obligations to escudo_app');
        DB::statement('grant select, insert, update on sunafil_obligations to escudo_definer');
    }

    public function down(): void
    {
        DB::statement('drop table if exists sunafil_obligations');
        DB::statement('alter table sector_packs drop constraint if exists sector_packs_sector_check');
        DB::statement("update sector_packs set sector = 'clinic' where sector = 'health'");
        DB::statement("alter table sector_packs add constraint sector_packs_sector_check check (sector in ('construction', 'mining', 'agroindustry', 'manufacturing', 'retail', 'office', 'clinic'))");
        DB::statement("delete from sector_pack_items where item_type = 'sunafil_obligation'");
        DB::statement('alter table sector_pack_items drop constraint if exists ck_sector_pack_items_type');
        DB::statement("alter table sector_pack_items add constraint ck_sector_pack_items_type check (item_type in ('hazard', 'inspection_template', 'permit_type', 'training_topic', 'document_template',
                       'legal_requirement', 'ppe_item', 'kpi', 'credential_requirement', 'risk_criteria', 'legal_parameter', 'pets_model', 'record_format'))");
    }
};
