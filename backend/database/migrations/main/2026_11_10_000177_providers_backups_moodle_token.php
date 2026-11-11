<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Integraciones configurables desde el sistema (sin tocar el código ni el servidor):
 *  - platform_providers acepta «backups»: copia externa de respaldos (S3/B2) configurada en Panel › Proveedores y claves.
 *  - lms_connections.token_enc: token del servicio web de Moodle cifrado por la aplicación (nunca vuelve al navegador);
 *    credentials_secret_ref apunta a esa columna («app-encrypted://lms_connections/token_enc»).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE platform_providers DROP CONSTRAINT IF EXISTS platform_providers_code_check');
        DB::statement("ALTER TABLE platform_providers ADD CONSTRAINT platform_providers_code_check CHECK (code IN ('ai', 'payments', 'einvoice', 'whatsapp', 'esign', 'backups'))");
        DB::statement("INSERT INTO platform_providers (code) VALUES ('backups') ON CONFLICT (code) DO NOTHING");

        DB::statement('ALTER TABLE lms_connections ADD COLUMN IF NOT EXISTS token_enc text');
        DB::statement("COMMENT ON COLUMN lms_connections.token_enc IS '[CIFRADO EN APLICACIÓN] token del servicio web de Moodle'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE lms_connections DROP COLUMN IF EXISTS token_enc');
        DB::statement("DELETE FROM platform_providers WHERE code = 'backups'");
        DB::statement('ALTER TABLE platform_providers DROP CONSTRAINT IF EXISTS platform_providers_code_check');
        DB::statement("ALTER TABLE platform_providers ADD CONSTRAINT platform_providers_code_check CHECK (code IN ('ai', 'payments', 'einvoice', 'whatsapp', 'esign'))");
    }
};
