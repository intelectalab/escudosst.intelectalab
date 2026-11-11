<?php

use App\Modules\INV\ScatCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Contenido que valida un especialista SST/legal, editable desde el panel del dueño (SCR-SUS-08):
 *  - legal_norm_requirements: aplicabilidad y marca «por confirmar / confirmado» de cada requisito del catálogo
 *    (los 26 requisitos iniciales quedan «por confirmar»).
 *  - scat_catalog_items: catálogo SCAT global (tipo de contacto, actos y condiciones subestándar, factores personales y
 *    del trabajo, necesidades de acción de control), cargado desde la constante App\Modules\INV\ScatCatalog. Las
 *    investigaciones ya hechas conservan el código y el texto que guardaron.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE legal_norm_requirements ADD COLUMN IF NOT EXISTS applicability text');
        DB::statement('ALTER TABLE legal_norm_requirements ADD COLUMN IF NOT EXISTS to_confirm boolean NOT NULL DEFAULT true');
        DB::statement('ALTER TABLE legal_norm_requirements ADD COLUMN IF NOT EXISTS confirmed_by text');
        DB::statement('ALTER TABLE legal_norm_requirements ADD COLUMN IF NOT EXISTS confirmed_at timestamptz');
        DB::statement("COMMENT ON COLUMN legal_norm_requirements.applicability IS '[MRL] A quién aplica el requisito (sector, tamaño, actividad)'");
        DB::statement("COMMENT ON COLUMN legal_norm_requirements.to_confirm IS '[MRL] Texto referencial pendiente de validación por especialista SST/legal'");

        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS scat_catalog_items (
              id uuid NOT NULL DEFAULT uuidv7() PRIMARY KEY,
              level text NOT NULL CHECK (level IN ('contact', 'immediate_act', 'immediate_condition', 'basic_personal', 'basic_job', 'nac')),
              code text NOT NULL CHECK (code ~ '^[A-Z]{1,4}[0-9]{2,3}$'),
              label text NOT NULL,
              explanation text,
              category text,
              sort_order integer NOT NULL DEFAULT 0,
              is_active boolean NOT NULL DEFAULT true,
              to_confirm boolean NOT NULL DEFAULT true,
              confirmed_by text,
              confirmed_at timestamptz,
              created_at timestamptz NOT NULL DEFAULT now(),
              created_by uuid,
              updated_at timestamptz NOT NULL DEFAULT now(),
              updated_by uuid
            )
            SQL);
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_scat_catalog_items_code ON scat_catalog_items (code)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_scat_catalog_items_level ON scat_catalog_items (level, sort_order)');
        DB::statement("COMMENT ON TABLE scat_catalog_items IS '[INV] Catálogo SCAT global de EscudoSST (a confirmar por especialista SST); lo edita el dueño de la plataforma'");
        DB::statement('GRANT SELECT ON scat_catalog_items TO escudo_app');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON scat_catalog_items TO escudo_platform');

        $groups = ['contact' => ScatCatalog::CONTACT, 'immediate_act' => ScatCatalog::ACTS, 'immediate_condition' => ScatCatalog::CONDITIONS,
            'basic_personal' => ScatCatalog::PERSONAL, 'basic_job' => ScatCatalog::JOB, 'nac' => ScatCatalog::NAC];
        foreach ($groups as $level => $items) {
            $i = 0;
            foreach ($items as $code => $label) {
                DB::insert('insert into scat_catalog_items (level, code, label, sort_order) values (?, ?, ?, ?) on conflict (code) do nothing',
                    [$level, $code, $label, ++$i * 10]);
            }
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS scat_catalog_items');
        DB::statement('ALTER TABLE legal_norm_requirements DROP COLUMN IF EXISTS confirmed_at, DROP COLUMN IF EXISTS confirmed_by,
                       DROP COLUMN IF EXISTS to_confirm, DROP COLUMN IF EXISTS applicability');
    }
};
