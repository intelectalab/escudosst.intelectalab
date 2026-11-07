<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aprendizaje del asistente: un sinónimo sugerido se activa con 3 PERSONAS distintas, no con 3 conversaciones de la
 * misma persona. voter_hash es un hash de la conexión con la llave de la app (nunca la IP en claro).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE chatbot_alias_votes ADD COLUMN IF NOT EXISTS voter_hash text');
        DB::statement("COMMENT ON COLUMN chatbot_alias_votes.voter_hash IS 'Hash de la conexión de quien eligió (para contar personas distintas); sin la IP en claro'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE chatbot_alias_votes DROP COLUMN IF EXISTS voter_hash');
    }
};
