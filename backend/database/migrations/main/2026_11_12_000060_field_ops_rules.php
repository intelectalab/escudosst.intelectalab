<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de equipos de emergencia, simulacros y brigadas:
 *  - M-EQE RN-EQE-03: la acción correctiva puede nacer de una revisión de equipo (origen «equipment_check»).
 *  - M-SIM RN-SIM-03: mínimo de miembros de una brigada para activarla (parámetro a confirmar).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table corrective_actions drop constraint if exists corrective_actions_source_type_check');
        DB::statement("alter table corrective_actions add constraint corrective_actions_source_type_check check (source_type = any (array['inspection', 'finding', 'audit', 'event',
            'investigation', 'report', 'baseline', 'legal_requirement', 'committee_agreement', 'management_review', 'monitoring', 'iperc', 'drill', 'observation', 'change', 'other',
            'environmental_aspect', 'equipment_check']))");
        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
             select null, 'min_brigade_members', '2', 'integer', null, 'Miembros activos mínimos de una brigada de emergencia para activarla', 'M-SIM RN-SIM-03', 'M-SIM', date '2012-04-25', true
              where not exists (select 1 from legal_parameters where company_id is null and key = 'min_brigade_members')"
        );
    }

    public function down(): void
    {
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key = 'min_brigade_members'");
    }
};
