<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-SUN §4, §5 y RN-SUN-07: la constancia de aptitud (ítem W3 del expediente por trabajador) entra al expediente solo si la
 * aprueba el médico ocupacional (sun.dossier_items.approve). dossier_items.is_medical_certificate, approved_by y approved_at;
 * el estado «pending_approval» (por aprobar) mientras el médico no la aprueba.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('alter table dossier_items add column if not exists is_medical_certificate boolean not null default false');
        DB::statement('alter table dossier_items add column if not exists approved_by uuid');
        DB::statement('alter table dossier_items add column if not exists approved_at timestamptz');
        DB::statement('alter table dossier_items drop constraint if exists fk_dossier_items_approved_by');
        DB::statement('alter table dossier_items add constraint fk_dossier_items_approved_by foreign key (approved_by) references users (id)');
        DB::statement('alter table dossier_items drop constraint if exists ck_dossier_items_status');
        DB::statement("alter table dossier_items add constraint ck_dossier_items_status check (status in ('included', 'missing', 'expired', 'not_applicable', 'excluded', 'pending_approval'))");
        DB::statement("comment on column dossier_items.is_medical_certificate is 'constancia de aptitud médica: entra al expediente solo con la aprobación del médico ocupacional (RN-SUN-07)'");
        DB::statement("comment on column dossier_items.approved_by is 'médico ocupacional que aprobó incluir la constancia de aptitud'");
        DB::connection('platform')->statement(<<<'SQL'
            INSERT INTO permissions (code, module_code, resource, action, is_sensitive, is_special, description)
            VALUES ('sun.dossier_items.approve', 'SUN', 'dossier_items', 'approve', false, true, 'Aprobar · dossier_items (Modo Inspección SUNAFIL)')
            ON CONFLICT (code) DO NOTHING
        SQL);
        DB::connection('platform')->statement(<<<'SQL'
            INSERT INTO role_permissions (account_id, role_id, permission_id)
            SELECT NULL, r.id, p.id FROM roles r JOIN permissions p ON p.code = 'sun.dossier_items.approve'
             WHERE r.account_id IS NULL AND r.code = 'occupational_doctor'
            ON CONFLICT DO NOTHING
        SQL);
    }

    public function down(): void
    {
        DB::connection('platform')->statement(<<<'SQL'
            DELETE FROM role_permissions rp USING permissions p WHERE rp.permission_id = p.id AND p.code = 'sun.dossier_items.approve'
        SQL);
        DB::connection('platform')->statement("DELETE FROM permissions WHERE code = 'sun.dossier_items.approve'");
        DB::statement("update dossier_items set status = 'missing' where status = 'pending_approval'");
        DB::statement('alter table dossier_items drop constraint if exists ck_dossier_items_status');
        DB::statement("alter table dossier_items add constraint ck_dossier_items_status check (status in ('included', 'missing', 'expired', 'not_applicable', 'excluded'))");
        DB::statement('alter table dossier_items drop constraint if exists fk_dossier_items_approved_by');
        DB::statement('alter table dossier_items drop column if exists approved_at');
        DB::statement('alter table dossier_items drop column if exists approved_by');
        DB::statement('alter table dossier_items drop column if exists is_medical_certificate');
    }
};
