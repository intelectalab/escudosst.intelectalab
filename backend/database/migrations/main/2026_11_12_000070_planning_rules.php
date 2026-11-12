<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de planificación según sus fichas:
 *  - M-RIS RN-RIS-07: la entrega del RISST se anula con motivo (estado «anulada»).
 *  - M-PRE RN-PRE-04: el gasto que excede la línea queda marcado como sobreejecución, con su motivo.
 *  - M-LBA RN-LBA-04: «require_evidence_on_compliance» (0 = no exige; la empresa lo activa con 1).
 *  - M-MRL RN-MRL-04: plazo de la acción que nace de un requisito legal incumplido (parámetro a confirmar).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table risst_deliveries drop constraint if exists risst_deliveries_status_check');
        DB::statement("alter table risst_deliveries add constraint risst_deliveries_status_check check (status = any (array['pending', 'delivered', 'signed', 'voided']))");
        DB::statement('alter table risst_deliveries add column if not exists void_reason text');
        DB::statement('alter table budget_executions add column if not exists is_overrun boolean not null default false');
        DB::statement('alter table budget_executions add column if not exists overrun_reason text');

        foreach ([
            ['require_evidence_on_compliance', '0', 'boolean', 'Un ítem «cumple» de la línea base exige evidencia (1 = sí, 0 = no)', 'M-LBA RN-LBA-04'],
            ['legal_gap_action_days', '30', 'days', 'Plazo de la acción correctiva que nace de un requisito legal incumplido o parcial', 'M-MRL RN-MRL-04'],
        ] as [$key, $value, $type, $desc, $ref]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, ?, null, ?, ?, 'M-LBA', date '2012-04-25', true
                  where not exists (select 1 from legal_parameters where company_id is null and key = ?)", [$key, $value, $type, $desc, $ref, $key]);
        }
    }

    public function down(): void
    {
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key in ('require_evidence_on_compliance', 'legal_gap_action_days')");
    }
};
