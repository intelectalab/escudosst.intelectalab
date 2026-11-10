<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Proveedores externos de la plataforma (Panel › Proveedores y claves): inteligencia artificial, pagos en línea,
 * facturación electrónica (OSE), WhatsApp y firma digital certificada. El equipo pega las claves cuando contrata cada
 * servicio; mientras falten, los módulos que los usan funcionan en «modo de prueba» (avisado en pantalla).
 * Las claves se guardan cifradas por la aplicación (secret_enc); nunca salen al navegador.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS platform_providers (
              code text PRIMARY KEY CHECK (code IN ('ai', 'payments', 'einvoice', 'whatsapp', 'esign')),
              provider text,
              mode text NOT NULL DEFAULT 'test' CHECK (mode IN ('test', 'live')),
              settings jsonb NOT NULL DEFAULT '{}'::jsonb,
              secret_enc text,
              secret_hint text,
              last_tested_at timestamptz,
              last_test_ok boolean,
              last_test_message text,
              updated_at timestamptz NOT NULL DEFAULT now(),
              updated_by uuid
            )
            SQL);
        DB::statement("INSERT INTO platform_providers (code) VALUES ('ai'), ('payments'), ('einvoice'), ('whatsapp'), ('esign') ON CONFLICT (code) DO NOTHING");
        DB::statement('GRANT SELECT, UPDATE ON platform_providers TO escudo_platform');
        DB::statement('GRANT SELECT ON platform_providers TO escudo_app');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS platform_providers');
    }
};
