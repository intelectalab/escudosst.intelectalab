<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Anticipaciones y umbrales de los avisos §10 de las fichas (RN-NOT-03: ninguna se fija en código).
 * Valores por defecto a confirmar por la empresa o el especialista SST.
 */
return new class extends Migration
{
    private const PARAMS = [
        ['notify_warning_hours', '24', 'horas', 'Horas antes del plazo de una notificación obligatoria en que se avisa', 'M-ACC §10', 'M-ACC'],
        ['action_due_notice_days', '3', 'días', 'Días antes de la fecha meta de una acción correctiva en que se avisa al responsable', 'M-ACR §10', 'M-ACR'],
        ['overdue_repeat_days', '7', 'días', 'Cada cuántos días se repite el aviso de una acción vencida', 'M-ACR §10', 'M-ACR'],
        ['training_expiry_notice_days', '30', 'días', 'Días antes del vencimiento de una capacitación en que se avisa', 'M-CAP §10', 'M-CAP'],
        ['session_notice_days', '2', 'días', 'Días antes de una sesión de capacitación en que se convoca a los participantes', 'M-CAP §10', 'M-CAP'],
        ['induction_grace_days', '7', 'días', 'Días después del ingreso en que se avisa si el trabajador no tiene inducción', 'M-CAP §10', 'M-CAP'],
        ['vote_reminder_hours', '24', 'horas', 'Horas antes del cierre de la votación en que se recuerda votar a quien no votó', 'M-COM §10', 'M-COM'],
        ['meeting_notice_days', '3', 'días', 'Días antes de una sesión del comité en que se avisa a sus miembros', 'M-COM §10', 'M-COM'],
        ['agreement_due_notice_days', '3', 'días', 'Días antes del plazo de un acuerdo del comité en que se avisa al responsable', 'M-COM §10', 'M-COM'],
        ['ack_reminder_days', '3', 'días', 'Cada cuántos días se recuerda firmar el cargo de un documento, política o RISST', 'M-DOC §10', 'M-DOC'],
        ['doc_review_notice_days', '30', 'días', 'Días antes de la revisión de un documento en que se avisa al responsable', 'M-DOC §10', 'M-DOC'],
        ['ack_campaign_check_days', '7', 'días', 'Días después de publicar un documento en que se revisa el avance de los cargos', 'M-DOC §10', 'M-DOC'],
        ['ack_min_pct', '80', '%', 'Avance mínimo de cargos esperado al revisar la campaña', 'M-DOC §10', 'M-DOC'],
        ['emo_due_notice_days', '30', 'días', 'Días antes del vencimiento de la aptitud médica en que se avisa', 'M-EMO §10', 'M-EMO'],
        ['emo_appointment_reminder_days', '1', 'días', 'Días antes de la cita del examen médico en que se recuerda al trabajador', 'M-EMO §10', 'M-EMO'],
        ['ppe_replace_notice_days', '15', 'días', 'Días antes de la fecha de reposición de un EPP en que se avisa', 'M-EPP §10', 'M-EPP'],
        ['equipment_due_notice_days', '15', 'días', 'Días antes de la recarga o prueba de un equipo de emergencia en que se avisa', 'M-EQE §10', 'M-EQE'],
        ['stats_close_notice_days', '10', 'días', 'Días después del fin de mes en que se avisa si el mes de estadísticas sigue sin cerrar', 'M-EST §10', 'M-EST'],
        ['signature_reminder_days', '2', 'días', 'Cada cuántos días se recuerda una firma pendiente', 'M-FIR §10', 'M-FIR'],
        ['signature_expiry_notice_days', '2', 'días', 'Días antes del vencimiento de una solicitud de firma en que se avisa al solicitante', 'M-FIR §10', 'M-FIR'],
        ['credential_expiry_notice_days', '15', 'días', 'Días antes del vencimiento de un fotocheck en que se avisa', 'M-FOT §10', 'M-FOT'],
        ['inspection_notice_days', '2', 'días', 'Días antes de una inspección programada en que se avisa al inspector', 'M-INS §10', 'M-INS'],
        ['monitoring_notice_days', '15', 'días', 'Días antes de una campaña de monitoreo en que se avisa', 'M-MAG §10', 'M-MAG'],
        ['provider_failure_threshold', '5', 'fallos', 'Fallos seguidos de un proveedor de envío antes de avisar al dueño de la plataforma', 'M-NOT §10', 'M-NOT'],
        ['digest_hour', '7', 'hora', 'Hora del día en que sale el resumen diario de pendientes por correo', 'M-NOT §10', 'M-NOT'],
        ['offline_warning_days', '3', 'días', 'Días sin sincronizar tras los que se avisa al usuario del dispositivo', 'M-OFF §10', 'M-OFF'],
        ['activity_notice_days', '5', 'días', 'Días antes del inicio del mes en que se avisa de las actividades del programa', 'M-PAS §10', 'M-PAS'],
        ['program_report_day', '5', 'día', 'Día del mes siguiente en que se envía el avance mensual del programa', 'M-PAS §10', 'M-PAS'],
        ['policy_review_notice_days', '30', 'días', 'Días antes de la revisión de la política en que se avisa', 'M-POL §10', 'M-POL'],
        ['permit_expiry_notice_minutes', '60', 'minutos', 'Minutos antes del fin de un permiso de trabajo en que se avisa al solicitante', 'M-PTR §10', 'M-PTR'],
        ['self_diagnostic_days', '90', 'días', 'Cada cuántos días se recuerda hacer el autodiagnóstico SUNAFIL', 'M-SUN §10', 'M-SUN'],
        ['renewal_notice_days', '7', 'días', 'Días antes de la renovación de la suscripción en que se avisa', 'M-SUS §10', 'M-SUS'],
        ['site_close_notice_days', '15', 'días', 'Días antes del cierre de una sede temporal en que se avisa', 'M-TEN §10', 'M-TEN'],
        ['companies_limit_alert_pct', '90', '%', 'Porcentaje del límite de empresas del plan desde el que se avisa', 'M-TEN §10', 'M-TEN'],
        ['worker_doc_notice_days', '30', 'días', 'Días antes del vencimiento de un documento del legajo en que se avisa', 'M-TRB §10', 'M-TRB'],
        ['invite_reminder_days', '3', 'días', 'Días después de una invitación sin aceptar en que se recuerda al invitado', 'M-USR §10', 'M-USR'],
        ['auditor_expiry_notice_days', '7', 'días', 'Días antes del fin del acceso de un auditor externo en que se avisa', 'M-USR §10', 'M-USR'],
        ['followup_notice_days', '2', 'días', 'Días antes de la cita de control de un caso médico en que se recuerda al trabajador', 'M-VMD §10', 'M-VMD'],
        ['dsh_report_day', '5', 'día', 'Día del mes en que se envía a gerencia el resumen mensual de indicadores', 'M-DSH §10', 'M-DSH'],
        ['con_digest_weekday', '1', 'día', 'Día de la semana (1 = lunes) en que el consultor recibe los vencimientos de sus clientes', 'M-CON §10', 'M-CON'],
        ['moc_temp_notice_days', '7', 'días', 'Días antes del fin de un cambio temporal en que se avisa al solicitante', 'M-MOC §10', 'M-MOC'],
        ['term_notice_days', '60', 'días', 'Días antes del fin del mandato del comité en que se avisa', 'M-COM §10', 'M-COM'],
        ['investigation_notice_days', '3', 'días', 'Días antes del plazo de una investigación en que se avisa al líder', 'M-INV §10', 'M-INV'],
        ['drill_notice_days', '7', 'días', 'Días antes de un simulacro anunciado en que se avisa a brigadistas y jefes de área', 'M-SIM §10', 'M-SIM'],
        ['event_triage_hours', '24', 'horas', 'Horas tras las que se avisa un evento reportado sin clasificar', 'M-ACC', 'M-ACC'],
        ['management_review_notice_days', '15', 'días', 'Días antes de la revisión por la dirección en que se avisa', 'M-RVD §10', 'M-RVD'],
        ['man_hours_due_day', '5', 'día', 'Día del mes siguiente desde el que se avisa si faltan las horas-hombre', 'M-EST §10', 'M-EST'],
        ['budget_line_alert_pct', '80', '%', 'Porcentaje de ejecución de una línea del presupuesto desde el que se avisa', 'M-PRE §10', 'M-PRE'],
        ['denied_access_threshold', '3', 'intentos', 'Intentos de acceso médico denegado en un día tras los que se avisa', 'M-AUD §10', 'M-AUD'],
        ['external_audit_notice_days', '60', 'días', 'Días antes de cumplirse el periodo de la auditoría externa legal en que se avisa', 'M-AUI §10', 'M-AUI'],
        ['audit_notice_days', '7', 'días', 'Días antes de una auditoría programada en que se avisa', 'M-AUI §10', 'M-AUI'],
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
