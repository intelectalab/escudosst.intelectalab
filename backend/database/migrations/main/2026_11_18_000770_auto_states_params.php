<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Estados automáticos que piden las fichas y parámetros que estaban en código:
 *  - M-INV RN-INV-04 / §9: investigación abierta con el plazo vencido → «vencida» (overdue).
 *  - M-ATS RN-ATS-05 / §9: ATS firmado o en ejecución que no se cerró en el día → «no cerrado» (not_closed).
 *  - M-EQE RN-EQE-02 / criterio 2: equipo sin inspección en equipment_check_days → «inspección vencida» (inspection_overdue).
 *  - M-LBA RN-LBA-01: puntaje por respuesta (1 / 0,5 / 0) como parámetro «por confirmar».
 *  - M-SUS §10: días de aviso de renovación (renewal_notice_days) como parámetro.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const PARAMS = [
        ['equipment_check_days', '30', 'days', 'días', 'Días entre inspecciones de un equipo de emergencia antes de marcarlo «inspección vencida»', 'M-EQE RN-EQE-02', 'M-EQE'],
        ['baseline_scores', '{"compliant":1,"partial":0.5,"non_compliant":0}', 'json', null, 'Puntaje de cada respuesta del diagnóstico de línea base (cumple, parcial, no cumple)', 'M-LBA RN-LBA-01', 'M-LBA'],
        ['renewal_notice_days', '[5,3,0]', 'json', 'días', 'Días antes del fin del periodo en que se avisa la renovación de la suscripción', 'M-SUS §10', 'M-SUS'],
    ];

    public function up(): void
    {
        $swap = function (string $table, string $name, array $values): void {
            DB::statement("alter table {$table} drop constraint if exists {$name}");
            DB::statement("alter table {$table} add constraint {$name} check (status in ('".implode("','", $values)."'))");
        };
        $swap('investigations', 'investigations_status_check', ['open', 'analysis', 'committee_review', 'approved', 'actions_in_progress', 'closed', 'overdue']);
        $swap('ats_forms', 'ats_forms_status_check', ['draft', 'signed', 'in_execution', 'closed', 'cancelled', 'not_closed']);
        $swap('emergency_equipment', 'emergency_equipment_status_check', ['operational', 'needs_maintenance', 'out_of_service', 'retired', 'inspection_overdue']);
        foreach (self::PARAMS as [$key, $value, $type, $unit, $desc, $ref, $src]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, ?, ?, ?, ?, ?, date '2012-04-25', true where not exists (select 1 from legal_parameters where company_id is null and key = ?)",
                [$key, $value, $type, $unit, $desc, $ref, $src, $key]);
        }
    }

    public function down(): void
    {
    }
};
