<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ingreso por QR (M-ING SCR-ING-02): datos de la visita que no caben en access_events (inalterable):
 * a quién visita, motivo y aceptación de la inducción de visita. Una fila por evento de ingreso de visitante,
 * también inalterable (solo INSERT). El documento se guarda solo como HMAC para reconocer su salida.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS access_visits (
              id uuid NOT NULL DEFAULT uuidv7() PRIMARY KEY,
              company_id uuid NOT NULL REFERENCES companies (id),
              access_event_id uuid NOT NULL,
              host_name text NOT NULL CHECK (length(host_name) BETWEEN 2 AND 160),
              purpose text,
              document_hash bytea,
              induction_accepted_at timestamptz,
              created_at timestamptz NOT NULL DEFAULT now(),
              created_by uuid REFERENCES users (id),
              CONSTRAINT fk_access_visits_event FOREIGN KEY (company_id, access_event_id) REFERENCES access_events (company_id, id),
              CONSTRAINT uq_access_visits_event UNIQUE (access_event_id)
            )
            SQL);
        DB::statement('CREATE INDEX IF NOT EXISTS ix_access_visits_document ON access_visits (company_id, document_hash) WHERE document_hash IS NOT NULL');
        DB::statement('ALTER TABLE access_visits ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE access_visits FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS rls_access_visits_company ON access_visits');
        DB::statement("CREATE POLICY rls_access_visits_company ON access_visits TO escudo_app
            USING (company_id = (NULLIF(current_setting('app.company_id', true), ''))::uuid)
            WITH CHECK (company_id = (NULLIF(current_setting('app.company_id', true), ''))::uuid)");
        DB::statement('GRANT SELECT, INSERT ON access_visits TO escudo_app');
        DB::statement('DROP TRIGGER IF EXISTS trg_access_visits_immutable ON access_visits');
        DB::statement('CREATE TRIGGER trg_access_visits_immutable BEFORE UPDATE OR DELETE ON access_visits FOR EACH STATEMENT EXECUTE FUNCTION trg_forbid_mutation()');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS access_visits');
    }
};
