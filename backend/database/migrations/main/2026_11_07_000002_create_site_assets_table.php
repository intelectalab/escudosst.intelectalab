<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Archivos de marca del sitio (logo, logo para fondo oscuro y favicon) que sube el dueño (SCR-WEB-21).
 * La tabla `files` exige company_id (es de las empresas); estos archivos son de la plataforma, por eso van aparte.
 * site_settings.logo_file_id / logo_dark_file_id / favicon_file_id apuntan a site_assets.id.
 * El binario vive en el disco configurado (escudo.site_assets_disk: local en desarrollo, B2 en producción).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS site_assets (
              id uuid NOT NULL DEFAULT uuidv7(),
              kind text NOT NULL CHECK (kind IN ('logo', 'logo_dark', 'favicon')),
              disk text NOT NULL,
              path text NOT NULL,
              mime_type text NOT NULL,
              size_bytes bigint NOT NULL CHECK (size_bytes >= 0),
              width integer,
              height integer,
              original_name text,
              created_at timestamptz NOT NULL DEFAULT now(),
              created_by uuid REFERENCES users (id),
              PRIMARY KEY (id)
            )
        SQL);
        DB::statement("COMMENT ON TABLE site_assets IS '[WEB] Logo, logo para fondo oscuro y favicon del sitio (archivos de la plataforma, sin company_id)'");
        DB::statement('GRANT SELECT, INSERT ON site_assets TO escudo_platform');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS site_assets');
    }
};
