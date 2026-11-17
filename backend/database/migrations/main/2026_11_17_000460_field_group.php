<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Campo:
 *  - M-INS §9 / RN-INS-04/06: estado «firmada» de la inspección y versiones de las plantillas (previous_template_id).
 *  - M-ACT §9 / RN-ACT-06: el reporte se escala a un evento de ACC (escalated, escalated_event_id); el QR del punto se puede cambiar
 *    (token_rotation: el QR anterior deja de funcionar).
 *  - M-EPP §9 / RN-EPP-01/06: «para reponer» automático; capacitación de uso que exige el EPP (training_id); solicitudes de reposición
 *    desde la app del trabajador (ppe_replacement_requests).
 *  - Parámetros que nombran las fichas sin valor (a confirmar): report_triage_hours, public_report_rate_limit, ats_block_untrained,
 *    allow_negative_stock, drills_per_year.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    private const PARAMS = [
        ['report_triage_hours', '24', 'integer', 'horas', 'Horas para clasificar un reporte de acto o condición antes de avisar al Jefe SST', 'M-ACT RN-ACT-05', 'M-ACT'],
        ['public_report_rate_limit', '10', 'integer', 'reportes por hora', 'Reportes que un mismo código QR acepta por hora (evita abuso)', 'M-ACT RN-ACT-01', 'M-ACT'],
        ['ats_block_untrained', 'false', 'boolean', null, 'Si está activo, un ejecutor sin la capacitación exigida no puede firmar el ATS', 'M-ATS RN-ATS-03', 'M-ATS'],
        ['allow_negative_stock', 'false', 'boolean', null, 'Permite entregar EPP sin stock (queda en negativo y se alerta)', 'M-EPP RN-EPP-03', 'M-EPP'],
        ['drills_per_year', '1', 'integer', 'simulacros', 'Simulacros ejecutados que cada sede debe tener al año', 'M-SIM RN-SIM-02', 'M-SIM'],
    ];

    public function up(): void
    {
        DB::statement('alter table inspections drop constraint if exists inspections_status_check');
        DB::statement("alter table inspections add constraint inspections_status_check check (status in ('scheduled', 'in_progress', 'completed', 'signed', 'closed', 'overdue', 'cancelled'))");
        DB::statement('alter table inspections add column if not exists signed_at timestamptz');
        DB::statement('alter table inspection_templates add column if not exists previous_template_id uuid references inspection_templates(id)');

        DB::statement('alter table substandard_reports drop constraint if exists substandard_reports_status_check');
        DB::statement("alter table substandard_reports add constraint substandard_reports_status_check check (status in ('received', 'triaged', 'rejected', 'action_assigned', 'escalated', 'closed'))");
        DB::statement('alter table substandard_reports add column if not exists escalated_event_id uuid references sst_events(id)');
        DB::statement('alter table report_points add column if not exists token_rotation integer not null default 0');

        DB::statement('alter table ppe_items add column if not exists training_id uuid references trainings(id)');
        DB::statement('alter table ppe_deliveries drop constraint if exists ppe_deliveries_status_check');
        DB::statement("alter table ppe_deliveries add constraint ppe_deliveries_status_check check (status in ('delivered', 'due_for_replacement', 'returned', 'replaced', 'lost'))");
        DB::statement(<<<'SQL'
            create table if not exists ppe_replacement_requests (
                id uuid primary key default gen_random_uuid(),
                company_id uuid not null references companies(id),
                worker_id uuid not null references workers(id),
                ppe_delivery_id uuid references ppe_deliveries(id),
                ppe_item_id uuid not null references ppe_items(id),
                reason text not null check (reason in ('worn', 'damaged', 'lost', 'size', 'other')),
                notes text,
                status text not null default 'pending' check (status in ('pending', 'attended', 'rejected')),
                resolution_note text,
                resolved_by uuid,
                resolved_at timestamptz,
                new_delivery_id uuid references ppe_deliveries(id),
                created_at timestamptz not null default now(),
                created_by uuid,
                updated_at timestamptz not null default now(),
                updated_by uuid,
                deleted_at timestamptz
            )
        SQL);
        DB::statement('alter table ppe_replacement_requests enable row level security');
        DB::statement('alter table ppe_replacement_requests force row level security');
        DB::statement('drop policy if exists rls_ppe_replacement_requests_company on ppe_replacement_requests');
        DB::statement("create policy rls_ppe_replacement_requests_company on ppe_replacement_requests using (company_id = (nullif(current_setting('app.company_id', true), ''))::uuid)
                       with check (company_id = (nullif(current_setting('app.company_id', true), ''))::uuid)");
        foreach (['escudo_app', 'escudo_definer'] as $role) {
            if (DB::selectOne('select 1 as ok from pg_roles where rolname = ?', [$role])) {
                DB::statement("grant select, insert, update on ppe_replacement_requests to {$role}");
            }
        }
        foreach (self::PARAMS as [$key, $value, $type, $unit, $desc, $ref, $src]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, ?, ?, ?, ?, ?, date '2012-04-25', true where not exists (select 1 from legal_parameters where company_id is null and key = ?)",
                [$key, $value, $type, $unit, $desc, $ref, $src, $key]);
        }
    }

    public function down(): void
    {
        DB::statement('drop table if exists ppe_replacement_requests');
        DB::statement('alter table ppe_items drop column if exists training_id');
        DB::statement('alter table report_points drop column if exists token_rotation');
        DB::statement('alter table substandard_reports drop column if exists escalated_event_id');
        DB::statement('alter table inspection_templates drop column if exists previous_template_id');
        DB::statement('alter table inspections drop column if exists signed_at');
        DB::connection('platform')->delete('delete from legal_parameters where company_id is null and key = any(?::text[])', ['{'.implode(',', array_column(self::PARAMS, 0)).'}']);
    }
};
