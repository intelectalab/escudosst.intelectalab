<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Credencial de voto por enlace para los electores sin usuario de la app (M-COM RN-COM-03, docs/07 §5.2):
 *  - election_roll.vote_token_hash: hash SHA-256 del token de un solo uso del elector (el enlace /votar/{empresa}/{token}).
 *  - election_roll.vote_token_enc: el mismo token cifrado con la llave de la aplicación, para reimprimir la lista de
 *    credenciales de la junta electoral. El voto se sigue guardando en election_ballots con un identificador aleatorio
 *    y sin worker_id: el token marca al elector como «ya votó» en el padrón, nunca viaja al voto.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE election_roll ADD COLUMN IF NOT EXISTS vote_token_hash bytea');
        DB::statement('ALTER TABLE election_roll ADD COLUMN IF NOT EXISTS vote_token_enc text');
        DB::statement('ALTER TABLE election_roll ADD COLUMN IF NOT EXISTS vote_token_issued_at timestamptz');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_election_roll_vote_token ON election_roll (vote_token_hash) WHERE vote_token_hash IS NOT NULL');
        DB::statement("COMMENT ON COLUMN election_roll.vote_token_hash IS '[COM] Hash del token de voto por enlace (un solo uso, RN-COM-03)'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_election_roll_vote_token');
        DB::statement('ALTER TABLE election_roll DROP COLUMN IF EXISTS vote_token_issued_at');
        DB::statement('ALTER TABLE election_roll DROP COLUMN IF EXISTS vote_token_enc');
        DB::statement('ALTER TABLE election_roll DROP COLUMN IF EXISTS vote_token_hash');
    }
};
