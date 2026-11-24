<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-PTR §4 y RN-PTR-06: el supervisor SST y el trabajador (ejecutor o vigía del permiso) pueden suspender un permiso activo
 * por condición insegura (ptr.work_permits.suspend), desde el PETAR en campo (SCR-PTR-05).
 * Agrega la acción «suspend» a las acciones de permisos.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const ACTIONS = "'read', 'create', 'update', 'delete', 'approve', 'sign', 'export', 'close', 'vote', 'verify', 'generate', 'share', 'sync', 'read_sensitive', 'publish', 'revoke', 'import', 'respond', 'track', 'assign', 'send', 'confirm', 'manage'";

    public function up(): void
    {
        DB::statement('alter table permissions drop constraint if exists permissions_action_check');
        DB::statement('alter table permissions add constraint permissions_action_check check (action in ('.self::ACTIONS.", 'suspend'))");
        DB::connection('platform')->statement(<<<'SQL'
            INSERT INTO permissions (code, module_code, resource, action, is_sensitive, is_special, description)
            VALUES ('ptr.work_permits.suspend', 'PTR', 'work_permits', 'suspend', false, true, 'Suspender · work_permits (PETAR y permisos de alto riesgo)')
            ON CONFLICT (code) DO NOTHING
        SQL);
        DB::connection('platform')->statement(<<<'SQL'
            INSERT INTO role_permissions (account_id, role_id, permission_id)
            SELECT NULL, r.id, p.id FROM roles r JOIN permissions p ON p.code = 'ptr.work_permits.suspend'
             WHERE r.account_id IS NULL AND r.code IN ('sst_supervisor', 'worker')
            ON CONFLICT DO NOTHING
        SQL);
    }

    public function down(): void
    {
        DB::connection('platform')->statement(<<<'SQL'
            DELETE FROM role_permissions rp USING permissions p WHERE rp.permission_id = p.id AND p.code = 'ptr.work_permits.suspend'
        SQL);
        DB::connection('platform')->statement("DELETE FROM permissions WHERE code = 'ptr.work_permits.suspend'");
        DB::statement('alter table permissions drop constraint if exists permissions_action_check');
        DB::statement('alter table permissions add constraint permissions_action_check check (action in ('.self::ACTIONS.'))');
    }
};
