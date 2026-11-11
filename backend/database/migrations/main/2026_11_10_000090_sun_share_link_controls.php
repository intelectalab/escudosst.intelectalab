<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Modo SUNAFIL (M-SUN SCR-SUN-06/08, RN-SUN-08/09): el enlace del inspector admite un máximo de descargas, puede quedar
 * «pendiente de aprobación» de gerencia antes de activarse (los días de vigencia corren desde la aprobación) y cada
 * acceso queda en un historial inalterable (fecha, desde dónde con la IP abreviada, acción y documento visto).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE dossier_share_links ADD COLUMN IF NOT EXISTS max_downloads integer CHECK (max_downloads IS NULL OR max_downloads > 0)');
        DB::statement('ALTER TABLE dossier_share_links ADD COLUMN IF NOT EXISTS download_count integer NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE dossier_share_links ADD COLUMN IF NOT EXISTS valid_days integer');
        DB::statement('ALTER TABLE dossier_share_links ADD COLUMN IF NOT EXISTS requires_approval boolean NOT NULL DEFAULT false');
        DB::statement('ALTER TABLE dossier_share_links ADD COLUMN IF NOT EXISTS approved_by uuid REFERENCES users (id)');
        DB::statement('ALTER TABLE dossier_share_links ADD COLUMN IF NOT EXISTS approved_at timestamptz');
        DB::statement('ALTER TABLE dossier_share_links DROP CONSTRAINT IF EXISTS dossier_share_links_status_check');
        DB::statement("ALTER TABLE dossier_share_links ADD CONSTRAINT dossier_share_links_status_check CHECK (status IN ('pending_approval', 'active', 'expired', 'exhausted', 'revoked', 'rejected'))");
        DB::statement('ALTER TABLE dossier_share_links DROP CONSTRAINT IF EXISTS ck_dossier_share_links_3');
        DB::statement('ALTER TABLE dossier_share_links ADD CONSTRAINT ck_dossier_share_links_3 CHECK (max_downloads IS NULL OR download_count <= max_downloads)');

        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS dossier_share_accesses (
              id uuid NOT NULL DEFAULT uuidv7() PRIMARY KEY,
              company_id uuid NOT NULL REFERENCES companies (id),
              share_link_id uuid NOT NULL,
              action text NOT NULL CHECK (action IN ('created', 'approved', 'rejected', 'view', 'download', 'denied', 'revoked')),
              detail text,
              ip_prefix text,
              user_agent text,
              user_id uuid REFERENCES users (id),
              occurred_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT fk_dossier_share_accesses_link FOREIGN KEY (company_id, share_link_id) REFERENCES dossier_share_links (company_id, id)
            )
            SQL);
        DB::statement('COMMENT ON TABLE dossier_share_accesses IS \'[SUN] Historial de accesos y cambios del enlace del inspector · INALTERABLE: solo INSERT.\'');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_dossier_share_accesses_link ON dossier_share_accesses (company_id, share_link_id, occurred_at)');
        DB::statement('ALTER TABLE dossier_share_accesses ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE dossier_share_accesses FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS rls_dossier_share_accesses_company ON dossier_share_accesses');
        DB::statement("CREATE POLICY rls_dossier_share_accesses_company ON dossier_share_accesses TO escudo_app
            USING (company_id = (NULLIF(current_setting('app.company_id', true), ''))::uuid)
            WITH CHECK (company_id = (NULLIF(current_setting('app.company_id', true), ''))::uuid)");
        DB::statement('GRANT SELECT, INSERT ON dossier_share_accesses TO escudo_app');
        DB::statement('DROP TRIGGER IF EXISTS trg_dossier_share_accesses_immutable ON dossier_share_accesses');
        DB::statement('CREATE TRIGGER trg_dossier_share_accesses_immutable BEFORE UPDATE OR DELETE ON dossier_share_accesses FOR EACH STATEMENT EXECUTE FUNCTION trg_forbid_mutation()');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS dossier_share_accesses');
        DB::statement("UPDATE dossier_share_links SET status = 'revoked', revoked_at = coalesce(revoked_at, now()) WHERE status IN ('pending_approval', 'rejected')");
        DB::statement('ALTER TABLE dossier_share_links DROP CONSTRAINT IF EXISTS ck_dossier_share_links_3');
        DB::statement('ALTER TABLE dossier_share_links DROP CONSTRAINT IF EXISTS dossier_share_links_status_check');
        DB::statement("ALTER TABLE dossier_share_links ADD CONSTRAINT dossier_share_links_status_check CHECK (status IN ('active', 'expired', 'exhausted', 'revoked'))");
        DB::statement('ALTER TABLE dossier_share_links DROP COLUMN IF EXISTS max_downloads, DROP COLUMN IF EXISTS download_count, DROP COLUMN IF EXISTS valid_days,
            DROP COLUMN IF EXISTS requires_approval, DROP COLUMN IF EXISTS approved_by, DROP COLUMN IF EXISTS approved_at');
    }
};
