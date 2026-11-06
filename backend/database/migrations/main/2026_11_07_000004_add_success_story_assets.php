<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Casos de éxito (SCR-WEB-09 y 44): el logo del cliente y la autorización escrita se guardan en site_assets.
 *  - story_logo:    imagen pública (sale en /casos).
 *  - story_consent: autorización firmada del cliente. Es privada: nunca se entrega por la ruta pública de archivos,
 *                   solo desde el panel (RN-WEB-41, docs/10 «Casos de éxito»).
 * success_stories.logo_file_id y consent_file_id apuntan a site_assets.id.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE site_assets DROP CONSTRAINT IF EXISTS site_assets_kind_check');
        DB::statement("ALTER TABLE site_assets ADD CONSTRAINT site_assets_kind_check CHECK (kind IN ('logo', 'logo_dark', 'favicon', 'story_logo', 'story_consent'))");
        DB::statement("COMMENT ON TABLE site_assets IS '[WEB] Archivos de la plataforma para el sitio: logo, favicon, logos de casos de éxito y autorizaciones de casos (privadas)'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE site_assets DROP CONSTRAINT IF EXISTS site_assets_kind_check');
        DB::statement("ALTER TABLE site_assets ADD CONSTRAINT site_assets_kind_check CHECK (kind IN ('logo', 'logo_dark', 'favicon'))");
    }
};
