<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fidelia (el CRM propio de Intelectalab) como proveedor de la conexión con el CRM. Recibe leads por su API
 * POST /api/v1/ext/leads con una llave «fcrm_…» de escritura.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE crm_connections DROP CONSTRAINT IF EXISTS crm_connections_provider_check');
        DB::statement("ALTER TABLE crm_connections ADD CONSTRAINT crm_connections_provider_check CHECK (provider IN ('kallpa', 'generic_webhook', 'fidelia'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE crm_connections DROP CONSTRAINT IF EXISTS crm_connections_provider_check');
        DB::statement("ALTER TABLE crm_connections ADD CONSTRAINT crm_connections_provider_check CHECK (provider IN ('kallpa', 'generic_webhook'))");
    }
};
