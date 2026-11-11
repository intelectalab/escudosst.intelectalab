<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grupos vulnerables (M-VUL SCR-VUL-03, RN-VUL-02): la adecuación indica qué familias de peligro restringe
 * (sin revelar la condición, que vive en la base médica) y, si el IPERC del puesto las tiene, la justificación de
 * mantenerlo en el puesto. Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE work_adjustments ADD COLUMN IF NOT EXISTS restricted_hazard_families text[] NOT NULL DEFAULT '{}'::text[]");
        DB::statement('ALTER TABLE work_adjustments ADD COLUMN IF NOT EXISTS compatibility_justification text');
        DB::statement("COMMENT ON COLUMN work_adjustments.restricted_hazard_families IS '[VUL] Familias de peligro (hazard_catalog.hazard_family) que la adecuación restringe'");
        DB::statement("COMMENT ON COLUMN work_adjustments.compatibility_justification IS '[VUL] Justificación cuando el puesto tiene peligros restringidos y no se reubica (RN-VUL-02)'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE work_adjustments DROP COLUMN IF EXISTS compatibility_justification, DROP COLUMN IF EXISTS restricted_hazard_families');
    }
};
