<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-VTA §4: el participante externo que representa a una empresa compradora asigna y libera sus cupos
 * (vta.license_assignments.update). Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('platform')->statement(
            "INSERT INTO role_permissions (account_id, role_id, permission_id) SELECT NULL, r.id, p.id FROM roles r JOIN permissions p ON p.code = 'vta.license_assignments.update'
              WHERE r.account_id IS NULL AND r.code = 'external_learner' ON CONFLICT DO NOTHING");
    }

    public function down(): void
    {
        DB::connection('platform')->statement(
            "DELETE FROM role_permissions rp USING roles r, permissions p WHERE rp.role_id = r.id AND rp.permission_id = p.id AND p.code = 'vta.license_assignments.update'
              AND r.account_id IS NULL AND r.code = 'external_learner'");
    }
};
