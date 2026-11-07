<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Informe PDF del autodiagnóstico (RN-WEB-39): se guarda en site_assets con kind «report» (privado: solo se entrega
 * con un enlace firmado de corta duración). assessment_submissions.report_file_id apunta a site_assets.id.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE site_assets DROP CONSTRAINT IF EXISTS site_assets_kind_check');
        DB::statement("ALTER TABLE site_assets ADD CONSTRAINT site_assets_kind_check CHECK (kind IN ('logo', 'logo_dark', 'favicon', 'story_logo', 'story_consent', 'story_signature', 'media', 'report'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE site_assets DROP CONSTRAINT IF EXISTS site_assets_kind_check');
        DB::statement("ALTER TABLE site_assets ADD CONSTRAINT site_assets_kind_check CHECK (kind IN ('logo', 'logo_dark', 'favicon', 'story_logo', 'story_consent', 'story_signature', 'media'))");
    }
};
