<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-ACR §4: el trabajador ejecuta las acciones asignadas a él y sube su evidencia (acr.action_evidences.create), SCR-ACR-03 «Mis acciones».
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('platform')->statement(
            "INSERT INTO role_permissions (account_id, role_id, permission_id) SELECT NULL, r.id, p.id FROM roles r JOIN permissions p ON p.code = 'acr.action_evidences.create'
              WHERE r.account_id IS NULL AND r.code = 'worker' ON CONFLICT DO NOTHING");
    }

    public function down(): void
    {
        DB::connection('platform')->statement(
            "DELETE FROM role_permissions rp USING roles r, permissions p WHERE rp.role_id = r.id AND rp.permission_id = p.id AND p.code = 'acr.action_evidences.create'
              AND r.account_id IS NULL AND r.code = 'worker'");
    }
};
