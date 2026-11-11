<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Motivos neutros del ingreso negado (M-ING RN-ING-02): access_events.deny_codes guarda un código por motivo
 * (sin términos médicos: la aptitud médica queda como «requirement_pending») junto al texto neutro de deny_reasons.
 * Los eventos siguen siendo inalterables (solo se agrega la columna).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE access_events ADD COLUMN IF NOT EXISTS deny_codes jsonb');
        DB::statement("COMMENT ON COLUMN access_events.deny_codes IS '[ING] Códigos neutros de los motivos de negación (RN-ING-02)'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE access_events DROP COLUMN IF EXISTS deny_codes');
    }
};
