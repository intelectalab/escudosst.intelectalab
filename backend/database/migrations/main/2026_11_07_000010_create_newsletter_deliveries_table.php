<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envíos del boletín por suscriptor (RN-WEB-51): idempotencia por edición y suscriptor. Si el envío se corta a la
 * mitad, al reintentar solo se manda a quienes no lo recibieron. No guarda aperturas ni clics por persona.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS newsletter_deliveries (
              id uuid NOT NULL DEFAULT uuidv7(),
              issue_id uuid NOT NULL REFERENCES newsletter_issues (id),
              subscriber_id uuid NOT NULL REFERENCES newsletter_subscribers (id),
              status text NOT NULL CHECK (status IN ('sent', 'failed')),
              error text,
              created_at timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (id),
              CONSTRAINT uq_newsletter_deliveries UNIQUE (issue_id, subscriber_id)
            )
        SQL);
        DB::statement("COMMENT ON TABLE newsletter_deliveries IS '[WEB] Envío de una edición del boletín a un suscriptor (idempotencia por edición y suscriptor)'");
        DB::statement('ALTER TABLE newsletter_deliveries ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE newsletter_deliveries FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS rls_newsletter_deliveries_platform ON newsletter_deliveries');
        DB::statement('CREATE POLICY rls_newsletter_deliveries_platform ON newsletter_deliveries TO escudo_platform USING (true) WITH CHECK (true)');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON newsletter_deliveries TO escudo_platform');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS newsletter_deliveries');
    }
};
