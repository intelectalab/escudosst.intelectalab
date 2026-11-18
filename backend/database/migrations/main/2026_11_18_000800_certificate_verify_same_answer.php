<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-CAP RN-CAP-14 y criterio 8: la verificación pública responde igual a un código inexistente y a uno no verificable, y
 * ambas consultas quedan en certificate_verifications. Un código inexistente no tiene empresa ni certificado: esas columnas
 * pasan a admitir vacío y el resultado «invalid» queda registrado (fuera del alcance de las empresas por RLS: ninguna lo ve).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('alter table certificate_verifications alter column company_id drop not null');
        DB::statement('alter table certificate_verifications alter column certificate_id drop not null');
        DB::statement('alter table certificate_verifications drop constraint if exists certificate_verifications_result_check');
        DB::statement("alter table certificate_verifications add constraint certificate_verifications_result_check
                       check (result in ('valid', 'expired', 'voided', 'pending_validation', 'not_public', 'invalid'))");
        // La función public_verify_certificate pertenece a escudo_definer y no se reemplaza aquí: la respuesta igual la da la
        // API (CAP PrintController) y el registro de un código inexistente lo inserta la API con esta política.
        DB::statement('drop policy if exists rls_certificate_verifications_invalid_insert on certificate_verifications');
        DB::statement("create policy rls_certificate_verifications_invalid_insert on certificate_verifications for insert
                       with check (company_id is null and certificate_id is null and result = 'invalid')");
    }

    public function down(): void
    {
    }
};
