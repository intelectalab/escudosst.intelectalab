<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-CAP RN-CAP-12 y SCR-CAP-13: la verificación pública de certificados está desactivada por defecto; se activa por empresa
 * (parámetro certificate_public_verification) y además por tema (trainings.public_verification_enabled).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table trainings add column if not exists public_verification_enabled boolean not null default false');
        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
             select null, 'certificate_public_verification', 'false', 'boolean', null, 'Permite verificar en la página pública los certificados de capacitación de la empresa',
                    'M-CAP RN-CAP-12', 'M-CAP', date '2012-04-25', false
              where not exists (select 1 from legal_parameters where company_id is null and key = 'certificate_public_verification')");
    }

    public function down(): void
    {
        DB::statement('alter table trainings drop column if exists public_verification_enabled');
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key = 'certificate_public_verification'");
    }
};
