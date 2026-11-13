<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Parámetros de los avisos §10 de las fichas (valores a confirmar por la empresa o el especialista SST).
 */
return new class extends Migration
{
    private const PARAMS = [
        ['talk_missing_check_hour', '10', 'hora', 'Hora del día desde la que se avisa al jefe de área que su área no tiene charla registrada', 'M-CH5 §10', 'M-CH5'],
        ['bbs_alert_pct', '80', '%', 'Porcentaje de conductas seguras bajo el cual una categoría de observación genera alerta al cierre del mes', 'M-OBS RN-OBS-03', 'M-OBS'],
        ['register_due_notice_days', '10', 'días', 'Días después de cerrar el mes en que se avisa si no se generó el registro del periodo', 'M-REG §10', 'M-REG'],
        ['legal_eval_notice_days', '15', 'días', 'Días antes de la fecha de evaluación de un requisito legal en que se avisa al responsable', 'M-MRL §10', 'M-MRL'],
        ['gap_due_notice_days', '7', 'días', 'Días antes de la fecha meta de una brecha de la línea base en que se avisa al responsable', 'M-LBA §10', 'M-LBA'],
        ['baseline_reassessment_months', '12', 'meses', 'Cada cuántos meses se reevalúa la línea base', 'M-LBA §4', 'M-LBA'],
        ['baseline_reassessment_notice_days', '30', 'días', 'Días antes de cumplirse el periodo de reevaluación de la línea base en que se avisa', 'M-LBA §10', 'M-LBA'],
        ['plan_missing_notice_days', '30', 'días', 'Días antes del inicio del año en que se avisa si no hay Plan Anual aprobado', 'M-PLA §10', 'M-PLA'],
        ['training_plan_notice_days', '15', 'días', 'Días antes del mes en que se avisa si un tema del programa de capacitación no tiene capacitación creada', 'M-PCA §10', 'M-PCA'],
        ['sctr_period_due_day', '5', 'día', 'Día del mes desde el que se avisa si el periodo no tiene constancia de SCTR cargada', 'M-SCT §10', 'M-SCT'],
        ['ats_close_check_hour', '18', 'hora', 'Hora del día desde la que se avisa al supervisor un ATS sin cerrar', 'M-ATS §10', 'M-ATS'],
        ['share_link_notice_hours', '24', 'horas', 'Horas antes del vencimiento de un enlace del expediente en que se avisa al Jefe SST', 'M-SUN §10', 'M-SUN'],
        ['survey_reminder_days', '2', 'días', 'Días antes del cierre de una encuesta psicosocial en que se recuerda responderla', 'M-PSI §10', 'M-PSI'],
    ];

    public function up(): void
    {
        foreach (self::PARAMS as [$key, $value, $unit, $desc, $ref, $source]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, 'integer', ?, ?, ?, ?, date '2012-04-25', true
                  where not exists (select 1 from legal_parameters where company_id is null and key = ?)",
                [$key, $value, $unit, $desc, $ref, $source, $key]
            );
        }
    }

    public function down(): void
    {
        $keys = array_column(self::PARAMS, 0);
        DB::connection('platform')->delete('delete from legal_parameters where company_id is null and key in ('.implode(', ', array_fill(0, count($keys), '?')).')', $keys);
    }
};
