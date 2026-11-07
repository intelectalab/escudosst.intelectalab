<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Conexión del calendario de cada anfitrión de demos (RN-WEB-74). demo_hosts guarda solo la referencia
 * (calendar_secret_ref = db://demo-calendar/<host>); el token de Google va aquí cifrado con la llave de la app
 * y nunca sale por la API. Si Google lo rechaza, last_error avisa en el panel sin perder las citas.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS demo_calendar_tokens (
              host_id uuid NOT NULL REFERENCES demo_hosts (id),
              provider text NOT NULL CHECK (provider IN ('google', 'microsoft')),
              account_email text,
              refresh_token_encrypted text NOT NULL,
              access_token_encrypted text,
              access_expires_at timestamptz,
              last_error text,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (host_id)
            )
        SQL);
        DB::statement("COMMENT ON TABLE demo_calendar_tokens IS '[WEB] Token cifrado del calendario externo de un anfitrión de demos (referenciado por demo_hosts.calendar_secret_ref)'");
        DB::statement('ALTER TABLE demo_calendar_tokens ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE demo_calendar_tokens FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS rls_demo_calendar_tokens_platform ON demo_calendar_tokens');
        DB::statement('CREATE POLICY rls_demo_calendar_tokens_platform ON demo_calendar_tokens TO escudo_platform USING (true) WITH CHECK (true)');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON demo_calendar_tokens TO escudo_platform');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS demo_calendar_tokens');
    }
};
