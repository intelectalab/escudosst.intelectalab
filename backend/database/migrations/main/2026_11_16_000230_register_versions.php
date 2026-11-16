<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-REG RN-REG-03/05/08 y §9: cada registro generado es una versión con sus datos congelados (snapshot: empleador al corte,
 * columnas y filas), format_version, data_hash (SHA-256 de los datos canónicos) y el flujo
 * generated → pending_signature → signed → superseded. Las descargas antiguas («ready») se conservan como estaban.
 * M-REG §4: el Jefe SST firma los registros («reg.register_exports.approve»).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table register_exports add column if not exists snapshot jsonb');
        DB::statement('alter table register_exports add column if not exists data_hash text');
        DB::statement('alter table register_exports add column if not exists format_version text');
        DB::statement('alter table register_exports add column if not exists superseded_by uuid references register_exports(id)');
        DB::statement('alter table register_exports drop constraint if exists register_exports_status_check');
        DB::statement("alter table register_exports add constraint register_exports_status_check
                       check (status in ('queued', 'generating', 'ready', 'failed', 'expired', 'generated', 'pending_signature', 'signed', 'superseded'))");

        DB::connection('platform')->statement(<<<'SQL'
            INSERT INTO permissions (code, module_code, resource, action, is_sensitive, is_special, description)
            VALUES ('reg.register_exports.approve', 'REG', 'register_exports', 'approve', false, false, 'Firmar · register_exports (Registros obligatorios)')
            ON CONFLICT (code) DO NOTHING
        SQL);
        DB::connection('platform')->statement(<<<'SQL'
            INSERT INTO role_permissions (account_id, role_id, permission_id)
            SELECT NULL, r.id, p.id FROM roles r JOIN permissions p ON p.code = 'reg.register_exports.approve'
             WHERE r.account_id IS NULL AND r.code = 'sst_manager'
            ON CONFLICT DO NOTHING
        SQL);
    }

    public function down(): void
    {
        DB::connection('platform')->statement(<<<'SQL'
            DELETE FROM role_permissions rp USING permissions p WHERE rp.permission_id = p.id AND p.code = 'reg.register_exports.approve'
        SQL);
        DB::connection('platform')->statement("DELETE FROM permissions WHERE code = 'reg.register_exports.approve'");
        DB::statement('alter table register_exports drop constraint if exists register_exports_status_check');
        DB::statement("update register_exports set status = 'ready' where status in ('generated', 'pending_signature', 'signed', 'superseded')");
        DB::statement("alter table register_exports add constraint register_exports_status_check check (status in ('queued', 'generating', 'ready', 'failed', 'expired'))");
        foreach (['superseded_by', 'format_version', 'data_hash', 'snapshot'] as $c) {
            DB::statement("alter table register_exports drop column if exists {$c}");
        }
    }
};
