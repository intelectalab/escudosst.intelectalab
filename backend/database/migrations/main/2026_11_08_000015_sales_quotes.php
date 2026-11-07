<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cotizaciones formales (venta de la suscripción con pago manual, DA-07 solución interina «pago manual registrado por el dueño»).
 *
 * Secuencia: solicitud del sitio → el equipo arma la cotización (una o varias empresas, cada una con su RUC, plan y
 * complementos) → se envía por correo con el PDF y un enlace personal → el cliente acepta o no → si acepta, ve los datos
 * para pagar y sube la constancia → el equipo revisa y confirma el pago → se crea la cuenta (o se activa la que ya tenía
 * en prueba) con sus suscripciones, se registran los pagos y le llega el correo para crear su contraseña.
 *
 * El enlace del cliente se guarda solo como hash. La constancia y el PDF son archivos privados (site_assets).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS sales_quotes (
              id uuid NOT NULL DEFAULT uuidv7(),
              number text NOT NULL,
              demo_request_id uuid REFERENCES demo_requests (id),
              status text NOT NULL DEFAULT 'draft'
                CHECK (status IN ('draft', 'sent', 'accepted', 'rejected', 'payment_submitted', 'paid', 'cancelled')),
              contact_name text NOT NULL,
              contact_email text NOT NULL,
              contact_phone text,
              billing_period text NOT NULL DEFAULT 'monthly' CHECK (billing_period IN ('monthly', 'annual')),
              companies jsonb NOT NULL DEFAULT '[]'::jsonb,
              annual_discount_pct numeric(5,2) NOT NULL DEFAULT 0,
              subtotal numeric(12,2) NOT NULL DEFAULT 0,
              discount_amount numeric(12,2) NOT NULL DEFAULT 0 CHECK (discount_amount >= 0),
              total numeric(12,2) NOT NULL DEFAULT 0 CHECK (total >= 0),
              currency char(3) NOT NULL DEFAULT 'PEN',
              valid_until date,
              client_notes text,
              internal_notes text,
              token_hash text,
              sent_at timestamptz,
              sent_count integer NOT NULL DEFAULT 0,
              pdf_file_id uuid REFERENCES site_assets (id),
              accepted_at timestamptz,
              accepted_ip_hash bytea,
              rejected_at timestamptz,
              reject_reason text,
              evidence_file_id uuid REFERENCES site_assets (id),
              payment_method text CHECK (payment_method IN ('transfer', 'deposit', 'yape', 'plin', 'other')),
              payment_reference text,
              payment_paid_on date,
              payment_amount numeric(12,2),
              payment_submitted_at timestamptz,
              evidence_rejected_reason text,
              confirmed_at timestamptz,
              confirmed_by uuid REFERENCES users (id),
              account_id uuid REFERENCES accounts (id),
              cancel_reason text,
              created_at timestamptz NOT NULL DEFAULT now(),
              created_by uuid REFERENCES users (id),
              updated_at timestamptz NOT NULL DEFAULT now(),
              updated_by uuid REFERENCES users (id),
              deleted_at timestamptz,
              PRIMARY KEY (id),
              CONSTRAINT uq_sales_quotes_number UNIQUE (number)
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_sales_quotes_token ON sales_quotes (token_hash) WHERE token_hash IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_sales_quotes_request ON sales_quotes (demo_request_id)');
        DB::statement("COMMENT ON TABLE sales_quotes IS '[WEB] Cotización formal enviada al cliente: aceptación, constancia de pago y alta de la cuenta'");
        DB::statement('ALTER TABLE sales_quotes ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE sales_quotes FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS rls_sales_quotes_platform ON sales_quotes');
        DB::statement('CREATE POLICY rls_sales_quotes_platform ON sales_quotes TO escudo_platform USING (true) WITH CHECK (true)');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON sales_quotes TO escudo_platform');

        // Datos para pagar (cuenta bancaria, Yape…) y vigencia por defecto: los escribe el dueño en el panel.
        DB::statement('ALTER TABLE site_settings ADD COLUMN IF NOT EXISTS payment_instructions text');
        DB::statement('ALTER TABLE site_settings ADD COLUMN IF NOT EXISTS quote_validity_days integer NOT NULL DEFAULT 15 CHECK (quote_validity_days BETWEEN 1 AND 90)');

        DB::statement('ALTER TABLE site_assets DROP CONSTRAINT IF EXISTS site_assets_kind_check');
        DB::statement("ALTER TABLE site_assets ADD CONSTRAINT site_assets_kind_check CHECK (kind IN ('logo', 'logo_dark', 'favicon', 'story_logo', 'story_consent', 'story_signature', 'media', 'report', 'quote_pdf', 'payment_evidence'))");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS sales_quotes');
        DB::statement('ALTER TABLE site_settings DROP COLUMN IF EXISTS payment_instructions');
        DB::statement('ALTER TABLE site_settings DROP COLUMN IF EXISTS quote_validity_days');
    }
};
