<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-API RN-API-01: el alcance «fitness.read» (solo la aptitud: apto / apto con restricciones / no apto / pendiente, restricciones
 * en términos laborales y vigencia; nunca datos médicos) necesita además la aprobación expresa de la empresa.
 * api_clients.fitness_approved_by y fitness_approved_at; el permiso especial api.api_clients.approve (administrador de la
 * cuenta y médico ocupacional) para aprobar o retirar esa aprobación.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('alter table api_clients add column if not exists fitness_approved_by uuid');
        DB::statement('alter table api_clients add column if not exists fitness_approved_at timestamptz');
        DB::statement('alter table api_clients drop constraint if exists fk_api_clients_fitness_approved_by');
        DB::statement('alter table api_clients add constraint fk_api_clients_fitness_approved_by foreign key (fitness_approved_by) references users (id)');
        DB::statement("comment on column api_clients.fitness_approved_by is 'quién aprobó que el cliente lea la aptitud (alcance fitness.read, RN-API-01)'");
        DB::connection('platform')->statement(<<<'SQL'
            INSERT INTO permissions (code, module_code, resource, action, is_sensitive, is_special, description)
            VALUES ('api.api_clients.approve', 'API', 'api_clients', 'approve', true, true, 'Aprobar · api_clients (lectura de la aptitud por la API)')
            ON CONFLICT (code) DO NOTHING
        SQL);
        DB::connection('platform')->statement(<<<'SQL'
            INSERT INTO role_permissions (account_id, role_id, permission_id)
            SELECT NULL, r.id, p.id FROM roles r JOIN permissions p ON p.code = 'api.api_clients.approve'
             WHERE r.account_id IS NULL AND r.code IN ('account_admin', 'occupational_doctor')
            ON CONFLICT DO NOTHING
        SQL);
    }

    public function down(): void
    {
        DB::connection('platform')->statement(<<<'SQL'
            DELETE FROM role_permissions rp USING permissions p WHERE rp.permission_id = p.id AND p.code = 'api.api_clients.approve'
        SQL);
        DB::connection('platform')->statement("DELETE FROM permissions WHERE code = 'api.api_clients.approve'");
        DB::statement('alter table api_clients drop constraint if exists fk_api_clients_fitness_approved_by');
        DB::statement('alter table api_clients drop column if exists fitness_approved_at');
        DB::statement('alter table api_clients drop column if exists fitness_approved_by');
    }
};
