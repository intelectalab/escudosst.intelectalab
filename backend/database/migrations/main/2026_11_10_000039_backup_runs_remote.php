<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Copia externa de los respaldos (docs/15 §9, docs/10 §8): después de guardar el volcado cifrado, se sube a un
 * almacenamiento de objetos compatible con S3 (Backblaze B2 u otro). Cada copia registra si se subió, dónde y cuándo.
 * remote_status: skipped = sin configurar · ok = subida · failed = no se pudo subir (la copia local sigue siendo válida).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE backup_runs
              ADD COLUMN IF NOT EXISTS remote_status text CHECK (remote_status IS NULL OR remote_status IN ('skipped', 'ok', 'failed')),
              ADD COLUMN IF NOT EXISTS remote_key text,
              ADD COLUMN IF NOT EXISTS remote_uploaded_at timestamptz,
              ADD COLUMN IF NOT EXISTS remote_error text,
              ADD COLUMN IF NOT EXISTS remote_deleted_at timestamptz
        SQL);
        DB::statement("COMMENT ON COLUMN backup_runs.remote_status IS 'Copia externa (S3/B2): skipped = sin configurar, ok = subida, failed = no se pudo subir'");
        DB::statement("COMMENT ON COLUMN backup_runs.remote_deleted_at IS 'Copia externa borrada por retención (BACKUP_REMOTE_RETENTION_DAYS)'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE backup_runs DROP COLUMN IF EXISTS remote_status, DROP COLUMN IF EXISTS remote_key, DROP COLUMN IF EXISTS remote_uploaded_at, DROP COLUMN IF EXISTS remote_error, DROP COLUMN IF EXISTS remote_deleted_at');
    }
};
