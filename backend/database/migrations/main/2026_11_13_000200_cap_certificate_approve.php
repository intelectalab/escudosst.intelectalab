<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-CAP §4: el Jefe SST valida los certificados (bandeja «Por validar», RN-CAP-08). Se le da «cap.training_certificates.approve».
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('platform')->statement(<<<'SQL'
            INSERT INTO role_permissions (account_id, role_id, permission_id)
            SELECT NULL, r.id, p.id FROM roles r JOIN permissions p ON p.code = 'cap.training_certificates.approve'
             WHERE r.account_id IS NULL AND r.code = 'sst_manager'
            ON CONFLICT DO NOTHING
        SQL);
    }

    public function down(): void
    {
        DB::connection('platform')->statement(<<<'SQL'
            DELETE FROM role_permissions rp USING roles r, permissions p
             WHERE rp.role_id = r.id AND rp.permission_id = p.id AND p.code = 'cap.training_certificates.approve'
               AND r.account_id IS NULL AND r.code = 'sst_manager'
        SQL);
    }
};
