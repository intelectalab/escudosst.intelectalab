<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 1. Equipo de la plataforma: users.platform_role distingue Dueño (owner) de Soporte (support) (docs/05 §4).
 *    Quien ya era personal de la plataforma queda como Dueño.
 * 2. Casos de éxito: firma dibujada del cliente (site_assets kind story_signature, privada) en la evidencia, y el
 *    texto de la autorización editable desde el panel (site_settings), con número de versión.
 *
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS platform_role text');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_platform_role_check');
        DB::statement("UPDATE users SET platform_role = 'owner' WHERE is_platform_staff AND platform_role IS NULL");
        // coalesce: un CHECK con NULL pasaría sin querer.
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_platform_role_check CHECK (
            CASE WHEN is_platform_staff THEN coalesce(platform_role IN ('owner', 'support'), false) ELSE platform_role IS NULL END)");
        DB::statement("COMMENT ON COLUMN users.platform_role IS 'Rol en el panel de la plataforma: owner (Dueño) o support (Soporte). Solo si is_platform_staff'");

        DB::statement('ALTER TABLE site_assets DROP CONSTRAINT IF EXISTS site_assets_kind_check');
        DB::statement("ALTER TABLE site_assets ADD CONSTRAINT site_assets_kind_check CHECK (kind IN ('logo', 'logo_dark', 'favicon', 'story_logo', 'story_consent', 'story_signature'))");
        DB::statement('ALTER TABLE success_story_consents ADD COLUMN IF NOT EXISTS signature_file_id uuid REFERENCES site_assets (id)');

        DB::statement('ALTER TABLE site_settings ADD COLUMN IF NOT EXISTS story_authorization_template text');
        DB::statement('ALTER TABLE site_settings ADD COLUMN IF NOT EXISTS story_authorization_version integer NOT NULL DEFAULT 1');
        DB::statement("COMMENT ON COLUMN site_settings.story_authorization_template IS 'Texto de la autorización de casos de éxito con marcadores {firmante}, {documento}, etc. Null = texto modelo'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE site_settings DROP COLUMN IF EXISTS story_authorization_version');
        DB::statement('ALTER TABLE site_settings DROP COLUMN IF EXISTS story_authorization_template');
        DB::statement('ALTER TABLE success_story_consents DROP COLUMN IF EXISTS signature_file_id');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_platform_role_check');
        DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS platform_role');
    }
};
