<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-HOM §4: la gerencia aprueba la homologación (hom.vendor_evaluations.approve); el Jefe SST crea plantillas y evalúa; el
 * administrador del contratista responde la autoevaluación (hom.vendor_evaluations.read y .update).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    private function grant(string $role, string $perm): void
    {
        DB::connection('platform')->statement(
            'INSERT INTO role_permissions (account_id, role_id, permission_id) SELECT NULL, r.id, p.id FROM roles r JOIN permissions p ON p.code = ?
              WHERE r.account_id IS NULL AND r.code = ? ON CONFLICT DO NOTHING', [$perm, $role]);
    }

    private function revoke(string $role, string $perm): void
    {
        DB::connection('platform')->statement(
            'DELETE FROM role_permissions rp USING roles r, permissions p WHERE rp.role_id = r.id AND rp.permission_id = p.id AND p.code = ? AND r.account_id IS NULL AND r.code = ?', [$perm, $role]);
    }

    public function up(): void
    {
        $this->revoke('sst_manager', 'hom.vendor_evaluations.approve');
        $this->grant('management', 'hom.vendor_evaluations.approve');
        $this->grant('management', 'hom.vendor_evaluations.read');
        $this->grant('contractor_admin', 'hom.vendor_evaluations.read');
        $this->grant('contractor_admin', 'hom.vendor_evaluations.update');
    }

    public function down(): void
    {
        $this->grant('sst_manager', 'hom.vendor_evaluations.approve');
        $this->revoke('management', 'hom.vendor_evaluations.approve');
        $this->revoke('contractor_admin', 'hom.vendor_evaluations.update');
    }
};
