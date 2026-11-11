<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Consultas al equipo de EscudoSST (docs/05: «Soporte de la plataforma atiende tickets»). Un usuario de una empresa
 * escribe desde Ayuda › «Escribir al equipo»; la consulta llega a Panel de la plataforma › Consultas, donde soporte
 * responde y la cierra. La respuesta se ve en Ayuda › «Mis consultas». Nunca incluye datos médicos.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS support_tickets (
              id uuid NOT NULL DEFAULT uuidv7() PRIMARY KEY,
              code text NOT NULL UNIQUE,
              company_id uuid NOT NULL REFERENCES companies (id),
              user_id uuid NOT NULL REFERENCES users (id),
              topic text NOT NULL DEFAULT 'question' CHECK (topic IN ('question', 'problem', 'suggestion', 'billing')),
              subject text NOT NULL CHECK (length(subject) BETWEEN 3 AND 120),
              message text NOT NULL CHECK (length(message) BETWEEN 5 AND 4000),
              page text CHECK (page IS NULL OR length(page) <= 200),
              status text NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'answered', 'closed')),
              reply text CHECK (reply IS NULL OR length(reply) <= 4000),
              replied_by uuid REFERENCES users (id),
              replied_at timestamptz,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now()
            )
            SQL);
        DB::statement('CREATE INDEX IF NOT EXISTS ix_support_tickets_company ON support_tickets (company_id, created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_support_tickets_status ON support_tickets (status, created_at DESC)');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON support_tickets TO escudo_platform');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS support_tickets');
    }
};
