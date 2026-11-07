<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Biblioteca de medios (SCR-WEB-65): los archivos de site_media se guardan en site_assets con kind «media» (públicos:
 * imágenes, videos, documentos e íconos del sitio). site_media.file_id apunta a site_assets.id.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE site_assets DROP CONSTRAINT IF EXISTS site_assets_kind_check');
        DB::statement("ALTER TABLE site_assets ADD CONSTRAINT site_assets_kind_check CHECK (kind IN ('logo', 'logo_dark', 'favicon', 'story_logo', 'story_consent', 'story_signature', 'media'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE site_assets DROP CONSTRAINT IF EXISTS site_assets_kind_check');
        DB::statement("ALTER TABLE site_assets ADD CONSTRAINT site_assets_kind_check CHECK (kind IN ('logo', 'logo_dark', 'favicon', 'story_logo', 'story_consent', 'story_signature'))");
    }
};
