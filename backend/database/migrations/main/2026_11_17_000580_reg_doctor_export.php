<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-REG §4: el médico ocupacional genera la versión completa del registro de EMO (reg.register_exports.create).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('platform')->statement(
            "INSERT INTO role_permissions (account_id, role_id, permission_id) SELECT NULL, r.id, p.id FROM roles r JOIN permissions p ON p.code = 'reg.register_exports.create'
              WHERE r.account_id IS NULL AND r.code = 'occupational_doctor' ON CONFLICT DO NOTHING");
    }

    public function down(): void
    {
        DB::connection('platform')->statement(
            "DELETE FROM role_permissions rp USING roles r, permissions p WHERE rp.role_id = r.id AND rp.permission_id = p.id AND p.code = 'reg.register_exports.create'
              AND r.account_id IS NULL AND r.code = 'occupational_doctor'");
    }
};
