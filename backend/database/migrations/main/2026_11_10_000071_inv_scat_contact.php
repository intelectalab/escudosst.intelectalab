<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Asistente SCAT de la investigación (M-INV SCR-INV-04): el tipo de contacto o evento elegido (códigos del catálogo
 * SCAT de EscudoSST). Las causas inmediatas, básicas y las necesidades de acción de control se guardan en
 * investigation_causes con su scat_code. Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE investigations ADD COLUMN IF NOT EXISTS scat_contact jsonb NOT NULL DEFAULT '[]'::jsonb");
        DB::statement("COMMENT ON COLUMN investigations.scat_contact IS '[INV] Tipos de contacto o evento del método SCAT (códigos del catálogo)'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE investigations DROP COLUMN IF EXISTS scat_contact');
    }
};
