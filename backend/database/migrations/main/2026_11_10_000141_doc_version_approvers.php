<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gestión documental (M-DOC §4, RN-DOC-03): el Comité SST y la Gerencia aprueban las versiones de los documentos cuya
 * categoría exige su aprobación. Se les da el permiso «doc.document_versions.approve» (roles del sistema).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('platform')->statement(<<<'SQL'
            INSERT INTO role_permissions (account_id, role_id, permission_id)
            SELECT NULL, r.id, p.id FROM roles r JOIN permissions p ON p.code = 'doc.document_versions.approve'
             WHERE r.account_id IS NULL AND r.code IN ('committee_member', 'management')
            ON CONFLICT DO NOTHING
        SQL);
    }

    public function down(): void
    {
        DB::connection('platform')->statement(<<<'SQL'
            DELETE FROM role_permissions rp USING roles r, permissions p
             WHERE rp.role_id = r.id AND rp.permission_id = p.id AND p.code = 'doc.document_versions.approve'
               AND r.account_id IS NULL AND r.code IN ('committee_member', 'management')
        SQL);
    }
};
