<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-PRE RN-PRE-02/05: budgets.total_approved (monto global que la suma de líneas debe igualar para aprobar) y budget_transfers
 * (reasignación entre líneas de un presupuesto aprobado, la aprueba gerencia y queda auditada).
 * M-RIS §5 y RN-RIS-02: risst_versions.content_outline con las secciones mínimas del art. 74 marcadas; la lista es un parámetro
 * con vigencia (ris_risst_sections, a confirmar). RN-RIS-08: entrega a trabajadores de contratistas (contractor_worker_id).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    /** Las secciones que nombra la ficha M-RIS §2 (D.S. 005-2012-TR, art. 74). */
    private const SECTIONS = [
        'Objetivos y alcance',
        'Liderazgo',
        'Organización',
        'Estándares de seguridad',
        'Preparación y respuesta a emergencias',
    ];

    public function up(): void
    {
        DB::statement('alter table budgets add column if not exists total_approved numeric(14,2) check (total_approved is null or total_approved >= 0)');
        DB::statement(<<<'SQL'
            create table if not exists budget_transfers (
                id uuid primary key default gen_random_uuid(),
                company_id uuid not null references companies(id),
                budget_id uuid not null references budgets(id),
                from_line_id uuid not null references budget_lines(id),
                to_line_id uuid not null references budget_lines(id),
                amount numeric(14,2) not null check (amount > 0),
                reason text not null,
                status text not null default 'pending' check (status in ('pending', 'approved', 'rejected')),
                requested_by uuid,
                decided_by uuid,
                decided_at timestamptz,
                decision_note text,
                created_at timestamptz not null default now(),
                created_by uuid,
                updated_at timestamptz not null default now(),
                updated_by uuid,
                deleted_at timestamptz,
                check (from_line_id <> to_line_id)
            )
        SQL);
        DB::statement('alter table budget_transfers enable row level security');
        DB::statement('alter table budget_transfers force row level security');
        DB::statement('drop policy if exists rls_budget_transfers_company on budget_transfers');
        DB::statement("create policy rls_budget_transfers_company on budget_transfers using (company_id = (nullif(current_setting('app.company_id', true), ''))::uuid)
                       with check (company_id = (nullif(current_setting('app.company_id', true), ''))::uuid)");
        foreach (['escudo_app', 'escudo_definer'] as $role) {
            if (DB::selectOne('select 1 as ok from pg_roles where rolname = ?', [$role])) {
                DB::statement("grant select, insert, update on budget_transfers to {$role}");
            }
        }

        DB::statement("alter table risst_versions add column if not exists content_outline jsonb not null default '[]'::jsonb");
        DB::statement('alter table risst_deliveries add column if not exists contractor_worker_id uuid references contractor_workers(id)');
        DB::statement('alter table risst_deliveries alter column worker_id drop not null');
        DB::statement('alter table risst_deliveries drop constraint if exists risst_deliveries_who_check');
        DB::statement('alter table risst_deliveries add constraint risst_deliveries_who_check check (worker_id is not null or contractor_worker_id is not null)');
        DB::statement('create unique index if not exists risst_deliveries_contractor_worker on risst_deliveries (company_id, risst_version_id, contractor_worker_id) where deleted_at is null and contractor_worker_id is not null');

        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
             select null, 'ris_risst_sections', ?, 'json', null, 'Secciones mínimas que el RISST debe tener (lista; la versión no se envía a aprobación sin todas marcadas)',
                    'D.S. 005-2012-TR, art. 74 · M-RIS RN-RIS-02', 'M-RIS', date '2012-04-25', true
              where not exists (select 1 from legal_parameters where company_id is null and key = 'ris_risst_sections')",
            [json_encode(self::SECTIONS, JSON_UNESCAPED_UNICODE)]);
        DB::connection('platform')->statement(
            "INSERT INTO role_permissions (account_id, role_id, permission_id) SELECT NULL, r.id, p.id FROM roles r JOIN permissions p ON p.code = 'ris.risst_deliveries.create'
              WHERE r.account_id IS NULL AND r.code = 'contractor_admin' ON CONFLICT DO NOTHING");
    }

    public function down(): void
    {
        DB::statement('drop table if exists budget_transfers');
        DB::statement('alter table budgets drop column if exists total_approved');
        DB::statement('alter table risst_versions drop column if exists content_outline');
        DB::statement('drop index if exists risst_deliveries_contractor_worker');
        DB::statement('alter table risst_deliveries drop constraint if exists risst_deliveries_who_check');
        DB::statement('alter table risst_deliveries drop column if exists contractor_worker_id');
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key = 'ris_risst_sections'");
    }
};
