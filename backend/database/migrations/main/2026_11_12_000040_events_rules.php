<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de accidentes, investigación y acciones correctivas según sus fichas:
 *  - M-ACC RN-ACC-06: agente de la lesión en las personas afectadas. §9: notificación «vencida».
 *  - M-INV RN-INV-06: justificación cuando no hay causa de gestión; RN-INV-05: adendas a un informe aprobado.
 *  - M-ACR RN-ACR-06: ampliación de plazo con fecha original, contador, motivo y aprobación de gerencia pasado el límite
 *    «max_extensions_without_approval» (parámetro a confirmar).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table event_people add column if not exists injury_agent text');
        DB::statement('alter table event_notifications drop constraint if exists event_notifications_status_check');
        DB::statement("alter table event_notifications add constraint event_notifications_status_check check (status = any (array['pending', 'sent', 'acknowledged', 'not_required', 'overdue']))");
        DB::statement('alter table investigations add column if not exists management_cause_justification text');
        DB::statement("alter table investigations add column if not exists addenda jsonb not null default '[]'::jsonb");
        DB::statement('alter table corrective_actions add column if not exists original_due_on date');
        DB::statement('alter table corrective_actions add column if not exists extension_count integer not null default 0');
        DB::statement('alter table corrective_actions add column if not exists extension_reason text');
        DB::statement('alter table corrective_actions add column if not exists extension_approved_by uuid references users(id)');

        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
             select null, 'max_extensions_without_approval', '1', 'integer', null, 'Ampliaciones de plazo de una acción correctiva sin aprobación de la gerencia', 'M-ACR RN-ACR-06', 'M-ACR', date '2012-04-25', true
              where not exists (select 1 from legal_parameters where company_id is null and key = 'max_extensions_without_approval')"
        );
    }

    public function down(): void
    {
        DB::statement('alter table event_notifications drop constraint if exists event_notifications_status_check');
        DB::statement("alter table event_notifications add constraint event_notifications_status_check check (status = any (array['pending', 'sent', 'acknowledged', 'not_required']))");
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key = 'max_extensions_without_approval'");
    }
};
