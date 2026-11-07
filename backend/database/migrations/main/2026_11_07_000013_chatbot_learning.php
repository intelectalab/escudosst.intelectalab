<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Asistente del sitio que aprende (propuesta aprobada, PROPUESTA-ASISTENTE).
 *  - chatbot_aliases: «término → respuesta» aprendido. Lo de visitantes nace como sugerencia y se activa al repetirse en
 *    3 conversaciones distintas o cuando el Dueño lo aprueba; lo que enseña el Dueño nace activo.
 *  - chatbot_alias_votes: una fila por conversación que eligió esa respuesta (para contar conversaciones distintas).
 *  - chatbot_messages.answer_kind: cómo se respondió (guided, assistant:<tipo>, fallback) para hallar lo no respondido.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS chatbot_aliases (
              id uuid NOT NULL DEFAULT uuidv7(),
              term text NOT NULL CHECK (length(term) BETWEEN 2 AND 120),
              target_type text NOT NULL CHECK (target_type IN ('intent', 'faq', 'help_article', 'resource', 'story', 'page')),
              target_key text NOT NULL CHECK (length(target_key) BETWEEN 1 AND 300),
              status text NOT NULL DEFAULT 'suggested' CHECK (status IN ('suggested', 'active', 'disabled')),
              source text NOT NULL CHECK (source IN ('visitor', 'owner')),
              uses integer NOT NULL DEFAULT 0 CHECK (uses >= 0),
              created_by uuid REFERENCES users (id),
              updated_by uuid REFERENCES users (id),
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (id),
              CONSTRAINT uq_chatbot_aliases UNIQUE (term, target_type, target_key)
            )
        SQL);
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS chatbot_alias_votes (
              alias_id uuid NOT NULL REFERENCES chatbot_aliases (id) ON DELETE CASCADE,
              conversation_id uuid NOT NULL,
              created_at timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (alias_id, conversation_id)
            )
        SQL);
        DB::statement('CREATE INDEX IF NOT EXISTS idx_chatbot_aliases_term ON chatbot_aliases (term) WHERE status = \'active\'');
        DB::statement("COMMENT ON TABLE chatbot_aliases IS '[WEB] Sinónimos aprendidos por el asistente del sitio (término normalizado → respuesta publicada)'");
        DB::statement("COMMENT ON TABLE chatbot_alias_votes IS '[WEB] Conversaciones que eligieron un alias sugerido (se activa con 3 distintas)'");
        foreach (['chatbot_aliases', 'chatbot_alias_votes'] as $t) {
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS rls_{$t}_platform ON {$t}");
            DB::statement("CREATE POLICY rls_{$t}_platform ON {$t} TO escudo_platform USING (true) WITH CHECK (true)");
        }
        DB::statement('GRANT SELECT, INSERT, UPDATE ON chatbot_aliases TO escudo_platform');
        DB::statement('GRANT SELECT, INSERT ON chatbot_alias_votes TO escudo_platform');
        DB::statement('ALTER TABLE chatbot_messages ADD COLUMN IF NOT EXISTS answer_kind text');
        DB::statement("COMMENT ON COLUMN chatbot_messages.answer_kind IS 'Cómo respondió el bot: guided (respuesta del panel), assistant:<tipo> (asistente con datos del sitio) o fallback (no supo)'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE chatbot_messages DROP COLUMN IF EXISTS answer_kind');
        DB::statement('DROP TABLE IF EXISTS chatbot_alias_votes');
        DB::statement('DROP TABLE IF EXISTS chatbot_aliases');
    }
};
