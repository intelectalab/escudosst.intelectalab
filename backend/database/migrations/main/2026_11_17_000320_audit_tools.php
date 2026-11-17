<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-AUI RN-AUI-06: una observación u oportunidad de mejora se cierra sin acción con su justificación (audit_findings.dismiss_reason).
 * RN-AUI-02: el acceso del auditor externo vence al terminar la auditoría + auditor_access_grace_days (valor a confirmar).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table audit_findings add column if not exists dismiss_reason text');
        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
             select null, 'auditor_access_grace_days', '0', 'integer', 'días', 'Días después del fin de la auditoría en que vence el acceso del auditor externo',
                    'M-AUI RN-AUI-02', 'M-AUI', date '2012-04-25', true
              where not exists (select 1 from legal_parameters where company_id is null and key = 'auditor_access_grace_days')");
    }

    public function down(): void
    {
        DB::statement('alter table audit_findings drop column if exists dismiss_reason');
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key = 'auditor_access_grace_days'");
    }
};
