<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Elección electrónica del comité (M-COM RN-COM-02/03/06):
 *  - election_roll: padrón de cada elección (trabajadores activos al corte, con exclusión justificada del personal de
 *    dirección y de confianza). Es también la «tabla separada» que marca que el elector ya votó: el voto se guarda en
 *    election_ballots sin worker_id y con un identificador aleatorio, por lo que no se puede ligar el voto al elector.
 *    Solo se guarda el día en que votó (no la hora) para no permitir cruzarlo con la hora del voto.
 *  - elections.tie_break_rule: regla de desempate configurada (antigüedad o sorteo registrado).
 *  - elections.roll_snapshot_at: momento en que se congeló el padrón (al abrir la votación).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS election_roll (
              id uuid NOT NULL DEFAULT uuidv7() PRIMARY KEY,
              company_id uuid NOT NULL REFERENCES companies (id),
              election_id uuid NOT NULL,
              worker_id uuid NOT NULL,
              excluded boolean NOT NULL DEFAULT false,
              exclusion_reason text,
              has_voted boolean NOT NULL DEFAULT false,
              voted_on date,
              created_at timestamptz NOT NULL DEFAULT now(),
              created_by uuid REFERENCES users (id),
              updated_at timestamptz NOT NULL DEFAULT now(),
              updated_by uuid REFERENCES users (id),
              CONSTRAINT fk_election_roll_election FOREIGN KEY (company_id, election_id) REFERENCES elections (company_id, id),
              CONSTRAINT fk_election_roll_worker FOREIGN KEY (company_id, worker_id) REFERENCES workers (company_id, id),
              CONSTRAINT ck_election_roll_reason CHECK (NOT excluded OR exclusion_reason IS NOT NULL),
              CONSTRAINT ck_election_roll_vote CHECK (NOT (excluded AND has_voted))
            )
            SQL);
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_election_roll_worker ON election_roll (company_id, election_id, worker_id)');
        DB::statement('ALTER TABLE election_roll ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE election_roll FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS rls_election_roll_company ON election_roll');
        DB::statement("CREATE POLICY rls_election_roll_company ON election_roll TO escudo_app
            USING (company_id = (NULLIF(current_setting('app.company_id', true), ''))::uuid)
            WITH CHECK (company_id = (NULLIF(current_setting('app.company_id', true), ''))::uuid)");
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON election_roll TO escudo_app');

        DB::statement("ALTER TABLE elections ADD COLUMN IF NOT EXISTS tie_break_rule text NOT NULL DEFAULT 'seniority'");
        DB::statement('ALTER TABLE elections DROP CONSTRAINT IF EXISTS ck_elections_tie_break_rule');
        DB::statement("ALTER TABLE elections ADD CONSTRAINT ck_elections_tie_break_rule CHECK (tie_break_rule IN ('seniority', 'lottery'))");
        DB::statement('ALTER TABLE elections ADD COLUMN IF NOT EXISTS roll_snapshot_at timestamptz');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS election_roll');
        DB::statement('ALTER TABLE elections DROP CONSTRAINT IF EXISTS ck_elections_tie_break_rule');
        DB::statement('ALTER TABLE elections DROP COLUMN IF EXISTS tie_break_rule');
        DB::statement('ALTER TABLE elections DROP COLUMN IF EXISTS roll_snapshot_at');
    }
};
