<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registro de las copias de seguridad (docs/15 §9 y §10, docs/10 §8): cada volcado lógico cifrado de la base principal
 * o médica, con su resultado, tamaño, huella del archivo cifrado y dónde quedó. Sirve para el aviso «copia atrasada o
 * fallida» y para la pantalla de Salud del sistema. No guarda datos de las empresas, solo el registro de la copia.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS backup_runs (
              id uuid NOT NULL DEFAULT uuidv7(),
              target text NOT NULL CHECK (target IN ('main', 'medical')),
              trigger text NOT NULL CHECK (trigger IN ('schedule', 'manual')),
              status text NOT NULL DEFAULT 'running' CHECK (status IN ('running', 'ok', 'failed')),
              started_at timestamptz NOT NULL DEFAULT now(),
              finished_at timestamptz,
              disk text,
              path text,
              size_bytes bigint CHECK (size_bytes IS NULL OR size_bytes >= 0),
              sha256 text,
              error text,
              requested_by uuid REFERENCES users (id),
              deleted_at timestamptz,
              PRIMARY KEY (id)
            )
        SQL);
        DB::statement('CREATE INDEX IF NOT EXISTS idx_backup_runs_target ON backup_runs (target, started_at DESC)');
        DB::statement("COMMENT ON TABLE backup_runs IS '[SUS] Registro de copias de seguridad cifradas (base principal y médica): resultado, tamaño y ubicación. deleted_at = borrada por retención'");
        DB::statement('ALTER TABLE backup_runs ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE backup_runs FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS rls_backup_runs_platform ON backup_runs');
        DB::statement('CREATE POLICY rls_backup_runs_platform ON backup_runs TO escudo_platform USING (true) WITH CHECK (true)');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON backup_runs TO escudo_platform');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS backup_runs');
    }
};
