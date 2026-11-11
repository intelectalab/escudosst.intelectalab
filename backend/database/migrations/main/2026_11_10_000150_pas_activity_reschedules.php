<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reprogramación de una actividad-mes del Programa Anual SST (M-PAS RN-PAS-02): reprogramar exige motivo y un nuevo
 * mes del mismo año, conservando la marca original. Cada reprogramación queda como fila inalterable (solo INSERT):
 * mes original, mes nuevo, motivo y quién la hizo. program_activities.planned_months se mueve al mes nuevo y el
 * cronograma muestra el mes original como «reprogramado».
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS activity_reschedules (
              id uuid NOT NULL DEFAULT uuidv7() PRIMARY KEY,
              company_id uuid NOT NULL REFERENCES companies (id),
              activity_id uuid NOT NULL,
              year smallint NOT NULL,
              from_month smallint NOT NULL CHECK (from_month BETWEEN 1 AND 12),
              to_month smallint NOT NULL CHECK (to_month BETWEEN 1 AND 12),
              reason text NOT NULL CHECK (length(reason) BETWEEN 5 AND 500),
              created_at timestamptz NOT NULL DEFAULT now(),
              created_by uuid REFERENCES users (id),
              CONSTRAINT fk_activity_reschedules_activity FOREIGN KEY (company_id, activity_id) REFERENCES program_activities (company_id, id),
              CONSTRAINT ck_activity_reschedules_month CHECK (from_month <> to_month)
            )
            SQL);
        DB::statement('CREATE INDEX IF NOT EXISTS ix_activity_reschedules_activity ON activity_reschedules (company_id, activity_id)');
        DB::statement("COMMENT ON TABLE activity_reschedules IS '[PAS] Reprogramación de una actividad-mes con motivo (RN-PAS-02)'");
        DB::statement('ALTER TABLE activity_reschedules ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE activity_reschedules FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS rls_activity_reschedules_company ON activity_reschedules');
        DB::statement("CREATE POLICY rls_activity_reschedules_company ON activity_reschedules TO escudo_app
            USING (company_id = (NULLIF(current_setting('app.company_id', true), ''))::uuid)
            WITH CHECK (company_id = (NULLIF(current_setting('app.company_id', true), ''))::uuid)");
        DB::statement('GRANT SELECT, INSERT ON activity_reschedules TO escudo_app');
        DB::statement('DROP TRIGGER IF EXISTS trg_activity_reschedules_immutable ON activity_reschedules');
        DB::statement('CREATE TRIGGER trg_activity_reschedules_immutable BEFORE UPDATE OR DELETE ON activity_reschedules FOR EACH STATEMENT EXECUTE FUNCTION trg_forbid_mutation()');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS activity_reschedules');
    }
};
