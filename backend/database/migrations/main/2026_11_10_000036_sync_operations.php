<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * App offline (M-OFF, docs/16 §4-§5): resultado de cada operación sincronizada, por su idempotency_key.
 * Si el dispositivo reenvía una operación ya procesada, el servidor devuelve el resultado guardado sin aplicarla de
 * nuevo (también para ediciones, rechazos y conflictos). Guarda además el id definitivo del registro creado para
 * traducir los ids generados en el dispositivo (depends_on: primero la inspección, luego sus respuestas).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS sync_operations (
              id uuid NOT NULL DEFAULT uuidv7() PRIMARY KEY,
              company_id uuid NOT NULL REFERENCES companies (id),
              sync_batch_id uuid NOT NULL,
              device_id uuid NOT NULL,
              idempotency_key text NOT NULL CHECK (length(idempotency_key) BETWEEN 8 AND 80),
              entity_type text NOT NULL,
              operation text NOT NULL CHECK (operation IN ('create', 'update')),
              client_entity_id uuid,
              entity_id uuid,
              result text NOT NULL CHECK (result IN ('applied', 'conflict', 'rejected')),
              reason text,
              needs_review boolean NOT NULL DEFAULT false,
              captured_at timestamptz,
              created_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT fk_sync_operations_batch FOREIGN KEY (company_id, sync_batch_id) REFERENCES sync_batches (company_id, id),
              CONSTRAINT fk_sync_operations_device FOREIGN KEY (company_id, device_id) REFERENCES devices (company_id, id)
            )
            SQL);
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_sync_operations_key ON sync_operations (company_id, idempotency_key)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_sync_operations_client_entity ON sync_operations (company_id, client_entity_id) WHERE client_entity_id IS NOT NULL');
        DB::statement('ALTER TABLE sync_operations ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE sync_operations FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS rls_sync_operations_company ON sync_operations');
        DB::statement("CREATE POLICY rls_sync_operations_company ON sync_operations TO escudo_app
            USING (company_id = (NULLIF(current_setting('app.company_id', true), ''))::uuid)
            WITH CHECK (company_id = (NULLIF(current_setting('app.company_id', true), ''))::uuid)");
        DB::statement('GRANT SELECT, INSERT ON sync_operations TO escudo_app');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS sync_operations');
    }
};
