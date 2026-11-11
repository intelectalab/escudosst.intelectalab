<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Matriz de requisitos legales (M-MRL RN-MRL-05, §9, SCR-MRL-05): marca «requiere revisión» del requisito cuando
 * cambia su norma en el catálogo (motivo, cuándo se marcó y quién lo revisó). Mientras esté marcado no cuenta como cumplido.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE legal_requirements ADD COLUMN IF NOT EXISTS needs_review boolean NOT NULL DEFAULT false');
        DB::statement('ALTER TABLE legal_requirements ADD COLUMN IF NOT EXISTS review_reason text');
        DB::statement('ALTER TABLE legal_requirements ADD COLUMN IF NOT EXISTS review_flagged_at timestamptz');
        DB::statement('ALTER TABLE legal_requirements ADD COLUMN IF NOT EXISTS reviewed_at timestamptz');
        DB::statement('ALTER TABLE legal_requirements ADD COLUMN IF NOT EXISTS reviewed_by uuid REFERENCES users (id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_legal_requirements_needs_review ON legal_requirements (company_id) WHERE needs_review');
        DB::statement("COMMENT ON COLUMN legal_requirements.needs_review IS '[MRL] Requiere revisión por cambio de su norma en el catálogo (RN-MRL-05)'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_legal_requirements_needs_review');
        DB::statement('ALTER TABLE legal_requirements DROP COLUMN IF EXISTS reviewed_by, DROP COLUMN IF EXISTS reviewed_at, DROP COLUMN IF EXISTS review_flagged_at,
                       DROP COLUMN IF EXISTS review_reason, DROP COLUMN IF EXISTS needs_review');
    }
};
