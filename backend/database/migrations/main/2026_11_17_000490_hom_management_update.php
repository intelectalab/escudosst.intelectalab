<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-HOM flujo 2: la gerencia aprueba o rechaza la homologación cambiando su estado; para eso necesita también
 * hom.vendor_evaluations.update (la regla del registro solo le deja aprobar, rechazar o poner vencida).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('platform')->statement(
            "INSERT INTO role_permissions (account_id, role_id, permission_id) SELECT NULL, r.id, p.id FROM roles r JOIN permissions p ON p.code = 'hom.vendor_evaluations.update'
              WHERE r.account_id IS NULL AND r.code = 'management' ON CONFLICT DO NOTHING");
    }

    public function down(): void
    {
        DB::connection('platform')->statement(
            "DELETE FROM role_permissions rp USING roles r, permissions p WHERE rp.role_id = r.id AND rp.permission_id = p.id AND p.code = 'hom.vendor_evaluations.update' AND r.account_id IS NULL AND r.code = 'management'");
    }
};
