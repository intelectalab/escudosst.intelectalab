<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-AUD RN-AUD-06: pasada la retención (audit_retention_years, a confirmar), los meses de la bitácora se archivan cifrados
 * fuera de la base (almacenamiento externo de las copias). Nada se borra sin aprobación: esta tabla registra cada archivo
 * con su huella y cuántos registros lleva.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create table if not exists audit_archives (
                id uuid primary key default uuidv7(),
                company_id uuid references companies(id),
                period date not null,
                rows_count integer not null,
                sha256 text not null,
                local_key text not null,
                remote_key text,
                remote_status text not null default 'skipped' check (remote_status in ('ok', 'skipped', 'failed')),
                archived_at timestamptz not null default now(),
                purge_approved_by uuid,
                purge_approved_at timestamptz,
                unique (company_id, period)
            )
        SQL);
        foreach (['escudo_platform', 'escudo_app', 'escudo_definer'] as $role) {
            if (DB::selectOne('select 1 as ok from pg_roles where rolname = ?', [$role])) {
                DB::statement("grant select, insert, update on audit_archives to {$role}");
            }
        }
        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
             select null, 'audit_retention_years', '5', 'years', 'años', 'Años que la bitácora queda en la base antes de archivarse cifrada fuera de ella', 'M-AUD RN-AUD-06', 'M-AUD', date '2012-04-25', true
              where not exists (select 1 from legal_parameters where company_id is null and key = 'audit_retention_years')");
    }

    public function down(): void
    {
        DB::statement('drop table if exists audit_archives');
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key = 'audit_retention_years'");
    }
};
