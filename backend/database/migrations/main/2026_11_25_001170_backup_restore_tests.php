<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pruebas de restauración de las copias de seguridad (docs/15 §9 «Restauración probada», docs/12 §9): cada vez que se
 * restaura la última copia buena en una base temporal se guarda el resultado, la duración y lo que se revisó (tablas,
 * tablas con RLS, migraciones y filas de tablas clave). La base temporal se borra al terminar.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS backup_restore_tests (
              id uuid NOT NULL DEFAULT uuidv7(),
              backup_run_id uuid REFERENCES backup_runs (id),
              target text NOT NULL CHECK (target IN ('main', 'medical')),
              trigger text NOT NULL CHECK (trigger IN ('schedule', 'manual')),
              status text NOT NULL DEFAULT 'running' CHECK (status IN ('running', 'ok', 'failed')),
              started_at timestamptz NOT NULL DEFAULT now(),
              finished_at timestamptz,
              duration_ms integer CHECK (duration_ms IS NULL OR duration_ms >= 0),
              temp_database text,
              tables_count integer,
              rls_tables_count integer,
              migrations_count integer,
              row_counts jsonb NOT NULL DEFAULT '{}'::jsonb,
              checks jsonb NOT NULL DEFAULT '[]'::jsonb,
              error text,
              requested_by uuid REFERENCES users (id),
              PRIMARY KEY (id)
            )
        SQL);
        DB::statement('CREATE INDEX IF NOT EXISTS idx_backup_restore_tests_target ON backup_restore_tests (target, started_at DESC)');
        DB::statement("COMMENT ON TABLE backup_restore_tests IS '[SUS] Pruebas de restauración de copias de seguridad en una base temporal: resultado, duración y revisiones (tablas, RLS, migraciones, filas)'");
        DB::statement('ALTER TABLE backup_restore_tests ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE backup_restore_tests FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS rls_backup_restore_tests_platform ON backup_restore_tests');
        DB::statement('CREATE POLICY rls_backup_restore_tests_platform ON backup_restore_tests TO escudo_platform USING (true) WITH CHECK (true)');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON backup_restore_tests TO escudo_platform');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS backup_restore_tests');
    }
};
