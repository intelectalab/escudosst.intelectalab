<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Casos de éxito por invitación (amplía SCR-WEB-44 y RN-WEB-41).
 *
 * success_story_invites: el equipo invita a un cliente por correo; el enlace (token, solo se guarda su sha256) abre
 *   un formulario donde el cliente cuenta su historia y firma la autorización. Vence a los 30 días.
 * success_story_consents: evidencia de la autorización electrónica. Guarda el texto exacto aceptado y su sha256,
 *   quién la firmó (documento cifrado), cuándo, desde qué IP (hash) y la constancia generada (site_assets).
 *   No se edita ni se borra: solo se marca el retiro (withdrawn_at). El retiro llega con un enlace propio.
 *
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS success_story_invites (
              id uuid NOT NULL DEFAULT uuidv7(),
              client_name text NOT NULL,
              sector text,
              contact_name text NOT NULL,
              contact_email text NOT NULL,
              message text,
              token_hash text NOT NULL UNIQUE,
              expires_at timestamptz NOT NULL,
              status text NOT NULL DEFAULT 'sent' CHECK (status IN ('sent', 'submitted', 'cancelled')),
              story_id uuid REFERENCES success_stories (id),
              sent_count integer NOT NULL DEFAULT 1,
              last_sent_at timestamptz NOT NULL DEFAULT now(),
              submitted_at timestamptz,
              created_at timestamptz NOT NULL DEFAULT now(),
              created_by uuid REFERENCES users (id),
              updated_at timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (id)
            )
        SQL);
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS success_story_consents (
              id uuid NOT NULL DEFAULT uuidv7(),
              story_id uuid NOT NULL REFERENCES success_stories (id),
              invite_id uuid REFERENCES success_story_invites (id),
              company_name text NOT NULL,
              company_ruc text CHECK (company_ruc IS NULL OR company_ruc ~ '^[0-9]{11}$'),
              signer_name text NOT NULL,
              signer_role text NOT NULL,
              signer_doc_type text NOT NULL CHECK (signer_doc_type IN ('DNI', 'CE', 'PAS')),
              signer_doc_number text NOT NULL,
              signer_email text NOT NULL,
              authorization_version text NOT NULL,
              authorization_text text NOT NULL,
              text_sha256 text NOT NULL,
              file_id uuid REFERENCES site_assets (id),
              accepted_at timestamptz NOT NULL DEFAULT now(),
              ip_hash text,
              user_agent text,
              withdraw_token_hash text NOT NULL UNIQUE,
              withdrawn_at timestamptz,
              withdraw_reason text,
              created_at timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (id)
            )
        SQL);
        DB::statement('CREATE INDEX IF NOT EXISTS idx_success_story_consents_story ON success_story_consents (story_id)');
        DB::statement("COMMENT ON TABLE success_story_invites IS '[WEB] Invitaciones a clientes para contar su caso de éxito (enlace con token, vence en 30 días)'");
        DB::statement("COMMENT ON TABLE success_story_consents IS '[WEB] Evidencia de la autorización electrónica de un caso de éxito: texto aceptado, firmante (documento cifrado), fecha, IP (hash) y constancia. Inalterable salvo el retiro'");
        DB::statement("COMMENT ON COLUMN success_story_consents.signer_doc_number IS 'Cifrado por la aplicación (Crypt)'");

        foreach (['success_story_invites', 'success_story_consents'] as $t) {
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS rls_{$t}_platform ON {$t}");
            DB::statement("CREATE POLICY rls_{$t}_platform ON {$t} TO escudo_platform USING (true) WITH CHECK (true)");
        }
        DB::statement('GRANT SELECT, INSERT, UPDATE ON success_story_invites TO escudo_platform');
        // La evidencia no se corrige: solo se registra el retiro.
        DB::statement('GRANT SELECT, INSERT ON success_story_consents TO escudo_platform');
        DB::statement('GRANT UPDATE (withdrawn_at, withdraw_reason) ON success_story_consents TO escudo_platform');
        DB::statement('GRANT SELECT ON site_assets TO escudo_platform');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS success_story_consents');
        DB::statement('DROP TABLE IF EXISTS success_story_invites');
    }
};
