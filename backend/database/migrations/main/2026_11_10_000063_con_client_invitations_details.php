<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Modo consultora (M-CON, SCR-CON-03 «Invitar empresa»): la invitación guarda la razón social y el plan sugerido
 * (la empresa elige y contrata su plan), y quién la invitó para darle acceso como consultor al aceptar.
 * La invitación se busca por el hash de su token antes de saber de qué empresa es: la lee y actualiza la conexión
 * de la plataforma (el código siempre la limita a la cuenta consultora o a la empresa que acepta).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE client_invitations ADD COLUMN IF NOT EXISTS target_legal_name text');
        DB::statement("ALTER TABLE client_invitations ADD COLUMN IF NOT EXISTS suggested_plan_code text CHECK (suggested_plan_code IS NULL OR suggested_plan_code IN ('E', 'P', 'C'))");
        DB::statement('ALTER TABLE client_invitations ADD COLUMN IF NOT EXISTS invited_by_user_id uuid REFERENCES users (id)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_client_invitations_account ON client_invitations (consultancy_account_id, status)');
        DB::statement('DROP POLICY IF EXISTS rls_client_invitations_platform ON client_invitations');
        DB::statement('CREATE POLICY rls_client_invitations_platform ON client_invitations TO escudo_platform USING (true) WITH CHECK (true)');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON client_invitations TO escudo_platform');
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS rls_client_invitations_platform ON client_invitations');
        DB::statement('REVOKE SELECT, INSERT, UPDATE ON client_invitations FROM escudo_platform');
        DB::statement('ALTER TABLE client_invitations DROP COLUMN IF EXISTS invited_by_user_id, DROP COLUMN IF EXISTS suggested_plan_code, DROP COLUMN IF EXISTS target_legal_name');
    }
};
