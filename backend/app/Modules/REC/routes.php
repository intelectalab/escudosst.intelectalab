<?php

use App\Modules\REC\RecordsController;
use Illuminate\Support\Facades\Route;

// Registros de los módulos SST (motor común, con empresa activa).
Route::middleware(['auth:sanctum', 'company'])->prefix('records')->group(function () {
    $m = '[A-Za-z0-9]{3}';
    $t = '[a-z0-9_]+';
    Route::get('{module}', [RecordsController::class, 'schema'])->where('module', $m);
    Route::get('{module}/{table}', [RecordsController::class, 'index'])->where(['module' => $m, 'table' => $t]);
    Route::post('{module}/{table}', [RecordsController::class, 'store'])->where(['module' => $m, 'table' => $t]);
    Route::get('{module}/{table}/options/{field}', [RecordsController::class, 'options'])->where(['module' => $m, 'table' => $t, 'field' => $t]);
    Route::get('{module}/{table}/{id}/trace', [RecordsController::class, 'trace'])->where(['module' => $m, 'table' => $t])->whereUuid('id');
    Route::get('{module}/{table}/{id}/history', [RecordsController::class, 'history'])->where(['module' => $m, 'table' => $t])->whereUuid('id');
    Route::get('{module}/{table}/{id}', [RecordsController::class, 'show'])->where(['module' => $m, 'table' => $t])->whereUuid('id');
    Route::patch('{module}/{table}/{id}', [RecordsController::class, 'update'])->where(['module' => $m, 'table' => $t])->whereUuid('id');
    Route::delete('{module}/{table}/{id}', [RecordsController::class, 'destroy'])->where(['module' => $m, 'table' => $t])->whereUuid('id');
});

// Informes imprimibles de campo (§11 de las fichas): inspección, PETAR y ATS.
Route::middleware(['auth:sanctum', 'company'])->prefix('reports')->group(function () {
    Route::get('inspections/{id}', [\App\Modules\REC\FieldReportsController::class, 'inspection'])->whereUuid('id')->middleware('module:INS');
    Route::get('work_permits/{id}', [\App\Modules\REC\FieldReportsController::class, 'permit'])->whereUuid('id')->middleware('module:PTR');
    Route::get('ats_forms/{id}', [\App\Modules\REC\FieldReportsController::class, 'ats'])->whereUuid('id')->middleware('module:ATS');
});

// Informes imprimibles de planificación (§11 de las fichas): política, Plan Anual, PASST, línea base y matriz legal.
Route::middleware(['auth:sanctum', 'company'])->prefix('reports')->controller(\App\Modules\REC\PlanningReportsController::class)->group(function () {
    Route::get('sst_policies/{id}', 'policy')->whereUuid('id')->middleware('module:POL');
    Route::get('risst_versions/{id}', 'risst')->whereUuid('id')->middleware('module:RIS');
    Route::get('sst_events/{id}/notifications', 'eventNotifications')->whereUuid('id')->middleware('module:ACC');
    Route::get('annual_plans/{id}', 'plan')->whereUuid('id')->middleware('module:PLA');
    Route::get('annual_programs/{id}', 'program')->whereUuid('id')->middleware('module:PAS');
    Route::get('training_programs/{id}', 'trainingProgram')->whereUuid('id')->middleware('module:PCA');
    Route::get('training_programs/{id}/compliance.xlsx', 'trainingProgramXlsx')->whereUuid('id')->middleware('module:PCA');
    Route::get('baseline_assessments/{id}', 'baseline')->whereUuid('id')->middleware('module:LBA');
    Route::get('legal_matrix', 'legalMatrix')->middleware('module:MRL');
});

// Informes imprimibles de registros (§11 de las fichas): revisión por la dirección, constancia de firma, actas de convocatoria e
// instalación del comité, entregas de EPP por trabajador y cargos e historial de un documento.
Route::middleware(['auth:sanctum', 'company'])->prefix('reports')->controller(\App\Modules\REC\RecordReportsController::class)->group(function () {
    Route::get('management_reviews/{id}', 'review')->whereUuid('id')->middleware('module:RVD');
    Route::get('signatures/{id}', 'signature')->whereUuid('id')->middleware('module:FIR');
    Route::get('elections/{id}/call', 'electionCall')->whereUuid('id')->middleware('module:COM');
    Route::get('committees/{id}', 'committee')->whereUuid('id')->middleware('module:COM');
    Route::get('committees/{id}/annual', 'committeeAnnual')->whereUuid('id')->middleware('module:COM');
    Route::get('committees/{id}/annual.xlsx', 'committeeAnnualXlsx')->whereUuid('id')->middleware('module:COM');
    Route::get('ppe_deliveries/worker/{id}', 'ppeWorker')->whereUuid('id')->middleware('module:EPP');
    Route::get('documents/{id}', 'document')->whereUuid('id')->middleware('module:DOC');
});

// Reportes de listas (§11 de las fichas): padrón, usuarios × roles × alcances, lista maestra, acciones, Pareto de causas y firmas pendientes.
Route::middleware(['auth:sanctum', 'company'])->prefix('reports')->controller(\App\Modules\REC\ListReportsController::class)->group(function () {
    Route::get('workers.xlsx', 'workers')->middleware('module:TRB');
    Route::get('users.xlsx', 'users')->middleware('module:USR');
    Route::get('documents.xlsx', 'documents')->middleware('module:DOC');
    Route::get('corrective_actions', 'actions')->middleware('module:ACR');
    Route::get('corrective_actions.xlsx', 'actions')->middleware('module:ACR');
    Route::get('investigation_causes', 'causes')->middleware('module:INV');
    Route::get('signature_requests.xlsx', 'pendingSignatures')->middleware('module:FIR');
});

// Charlas de 5 minutos (M-CH5 SCR-CH5-01/02): trabajadores del área, registro rápido y cobertura del día por área.
Route::middleware(['auth:sanctum', 'company', 'module:CH5'])->prefix('ch5')->controller(\App\Modules\REC\TalksController::class)->group(function () {
    Route::get('area-workers', 'areaWorkers');
    Route::post('quick', 'quick')->middleware('throttle:30,1');
    Route::get('coverage', 'coverage');
    Route::get('report.xlsx', 'report');
    Route::get('topics', 'topics');
    Route::post('topics', 'saveTopic');
    Route::get('talks/{id}/attendances', 'attendances')->whereUuid('id');
    Route::post('attendances/{id}/sign', 'sign')->whereUuid('id')->middleware('throttle:60,1');
});

// Ejecutar simulacro (M-SIM SCR-SIM-02): cronómetro de alarma a evacuación completa y asistencia de la sede.
Route::middleware(['auth:sanctum', 'company', 'module:SIM'])->prefix('sim/drills/{id}')->whereUuid('id')->controller(\App\Modules\REC\DrillRunController::class)->group(function () {
    Route::get('run', 'show');
    Route::post('run', 'save')->middleware('throttle:30,1');
    Route::post('evaluation', 'evaluate')->middleware('throttle:30,1');
    Route::get('report', 'report');
});

// Nuevo ATS (M-ATS SCR-ATS-02): copiar pasos desde un PETS, un ATS anterior o filas del IPERC vigente.
Route::middleware(['auth:sanctum', 'company', 'module:ATS'])->prefix('ats/forms/{id}')->whereUuid('id')->controller(\App\Modules\REC\AtsCopyController::class)->group(function () {
    Route::get('sources', 'sources');
    Route::post('copy', 'copy')->middleware('throttle:30,1');
});

// Monitoreo de agentes (M-MAG SCR-MAG-01/02, RN-MAG-04/07): mapa de puntos con semáforo, calendario y conversión de unidades.
Route::middleware(['auth:sanctum', 'company', 'module:MAG'])->prefix('mag')->controller(\App\Modules\REC\MagOverviewController::class)->group(function () {
    Route::get('points', 'points');
    Route::get('calendar', 'calendar');
    Route::get('unit-conversions', 'conversions');
    Route::post('unit-conversions', 'storeConversion');
    Route::delete('unit-conversions/{id}', 'deleteConversion')->whereUuid('id');
});

// Monitoreo de agentes (M-MAG SCR-MAG-03/05): carga masiva de mediciones desde Excel y tendencia por punto.
Route::middleware(['auth:sanctum', 'company', 'module:MAG'])->prefix('mag')->controller(\App\Modules\REC\MeasurementsController::class)->group(function () {
    Route::get('import/template', 'template');
    Route::post('import', 'import')->middleware('throttle:20,1');
    Route::get('trend', 'trend');
    Route::get('exposed', 'exposed');
    Route::get('gaps', 'gaps');
});

// Observación de conducta segura (M-OBS SCR-OBS-01/02/03): lista de comportamientos con versiones, captura rápida y tendencias.
Route::middleware(['auth:sanctum', 'company', 'module:OBS'])->prefix('obs')->controller(\App\Modules\REC\BehaviorController::class)->group(function () {
    Route::get('lists', 'lists');
    Route::post('lists', 'saveList');
    Route::post('lists/publish', 'publish');
    Route::post('observe', 'observe')->middleware('throttle:60,1');
    Route::get('trends', 'trends');
});

// Evaluación ergonómica REBA con cálculo del puntaje (M-PSI SCR-PSI-02).
Route::middleware(['auth:sanctum', 'company', 'module:PSI'])->post('psi/reba', \App\Modules\REC\RebaController::class)->middleware('throttle:60,1');

// Política SST (M-POL SCR-POL-01): resumen con % de cargos firmados y revisión pendiente; verificación pública del afiche (QR).
// M-ACC §11: reporte de eventos agrupado (tipo, gravedad, sede, parte del cuerpo y agente).
Route::middleware(['auth:sanctum', 'company', 'module:ACC'])->get('acc/report.xlsx', \App\Modules\REC\EventReportController::class);
// M-ACT §11: reportes por área, tipo y canal con su tiempo de atención.
Route::middleware(['auth:sanctum', 'company', 'module:ACT'])->get('act/report.xlsx', \App\Modules\REC\SubstandardReportController::class);
// M-PTR §11: estadísticas de permisos por tipo y sede, y suspensiones con su causa.
Route::middleware(['auth:sanctum', 'company', 'module:PTR'])->get('ptr/stats.xlsx', \App\Modules\REC\PermitStatsController::class);
// M-PTR SCR-PTR-03/05: rangos de gases, contexto del permiso en campo y suspensión por un participante (RN-PTR-06).
Route::middleware(['auth:sanctum', 'company', 'module:PTR'])->prefix('ptr')->controller(\App\Modules\REC\PermitFieldController::class)->group(function () {
    Route::get('gas-limits', 'limits');
    Route::get('work-permits/{id}/context', 'context')->whereUuid('id');
    Route::post('work-permits/{id}/suspend', 'suspend')->whereUuid('id')->middleware('throttle:30,1');
});
Route::middleware(['auth:sanctum', 'company', 'module:POL'])->prefix('pol/policies/{id}')->whereUuid('id')->group(function () {
    Route::post('approval-code', [\App\Modules\REC\PolicyApprovalController::class, 'code'])->middleware('throttle:10,1');
    Route::post('approve', [\App\Modules\REC\PolicyApprovalController::class, 'approve'])->middleware('throttle:20,1');
});
Route::middleware(['auth:sanctum', 'company', 'module:POL'])->get('pol/overview', [\App\Modules\REC\PolicyOverviewController::class, 'overview']);
Route::middleware(['auth:sanctum', 'company', 'module:POL'])->match(['get', 'put'], 'pol/settings', [\App\Modules\REC\PolicyOverviewController::class, 'settings']);
Route::middleware('throttle:30,1')->get('public/verify/policy/{company}/{id}', [\App\Modules\REC\PolicyOverviewController::class, 'public'])->whereUuid('company')->whereUuid('id');

// Plan Anual SST (M-PLA SCR-PLA-02/04): asistente que arma el plan, nuevas versiones e indicadores.
Route::middleware(['auth:sanctum', 'company', 'module:PLA'])->prefix('pla')->controller(\App\Modules\REC\PlanWizardController::class)->group(function () {
    Route::get('wizard', 'prefill');
    Route::post('wizard', 'create');
    Route::post('plans/{id}/new-version', 'newVersion')->whereUuid('id');
    Route::get('plans/{id}/indicators', 'indicators')->whereUuid('id');
    Route::get('plans/{id}/reference-options', 'referenceOptions')->whereUuid('id');
    Route::get('plans/{id}/pdf', 'pdf')->whereUuid('id');
});

// Auditorías (M-AUI SCR-AUI-01/02, RN-AUI-02/07): calendario y preparación, checklist desde la plantilla y acceso del auditor externo.
Route::middleware(['auth:sanctum', 'company', 'module:AUI'])->prefix('aui')->controller(\App\Modules\REC\AuditToolsController::class)->group(function () {
    Route::get('findings.xlsx', 'findingsByClause');
    Route::get('calendar', 'calendar');
    Route::post('audits/{id}/checklist', 'loadChecklist')->whereUuid('id');
    Route::post('audits/{id}/auditor-access', 'auditorAccess')->whereUuid('id')->middleware('throttle:login');
});

// Indicadores de la revisión por la dirección (M-RVD).
Route::middleware(['auth:sanctum', 'company', 'module:RVD'])->get('rvd/summary', \App\Modules\REC\ReviewSummaryController::class);

// Calendario de mantenimiento de equipos de emergencia y mínimo de extintores por sede (M-EQE SCR-EQE-04, RN-EQE-08).
Route::middleware(['auth:sanctum', 'company', 'module:EQE'])->get('eqe/calendar', \App\Modules\REC\EquipmentCalendarController::class);

// Presupuesto SST: tablero de ejecución, Excel y reasignaciones entre líneas aprobadas por gerencia (M-PRE SCR-PRE-01, §11, RN-PRE-05).
Route::middleware(['auth:sanctum', 'company', 'module:PRE'])->prefix('pre')->group(function () {
    Route::get('execution', [\App\Modules\REC\BudgetToolsController::class, 'execution']);
    Route::get('execution.xlsx', [\App\Modules\REC\BudgetToolsController::class, 'executionXlsx']);
});
Route::middleware(['auth:sanctum', 'company', 'module:PRE'])->prefix('reports')->group(function () {
    Route::get('budget_execution', [\App\Modules\REC\BudgetToolsController::class, 'report']);
});
Route::middleware(['auth:sanctum', 'company', 'module:PRE'])->prefix('pre')->group(function () {
    Route::post('transfers', [\App\Modules\REC\BudgetToolsController::class, 'requestTransfer']);
    Route::post('transfers/{id}/decide', [\App\Modules\REC\BudgetToolsController::class, 'decide'])->whereUuid('id');
});

// RISST: resumen con secciones del art. 74, tabla de entregas, entrega masiva y relación de cargos (M-RIS SCR-RIS-01/02/03, §11).
Route::middleware(['auth:sanctum', 'company', 'module:RIS'])->prefix('ris')->group(function () {
    Route::get('overview', [\App\Modules\REC\RisstToolsController::class, 'overview']);
    Route::post('deliver-all', [\App\Modules\REC\RisstToolsController::class, 'deliverAll']);
    Route::get('deliveries.xlsx', [\App\Modules\REC\RisstToolsController::class, 'deliveriesXlsx']);
});

// Registros del sistema como evidencia de un requisito legal (M-MRL SCR-MRL-04, RN-MRL-08).
Route::middleware(['auth:sanctum', 'company', 'module:MRL'])->get('mrl/evidence-options', \App\Modules\REC\MrlEvidenceController::class);

// Mapa de riesgos: filas del IPERC de la sede, equipos con su estado, versión más reciente y justificación de riesgos sin pictograma (M-MAP RN-MAP-01/02/06/08).
Route::middleware(['auth:sanctum', 'company', 'module:MAP'])->prefix('map/maps/{id}')->whereUuid('id')->group(function () {
    Route::get('context', [\App\Modules\REC\RiskMapContextController::class, 'context']);
    Route::post('justify', [\App\Modules\REC\RiskMapContextController::class, 'justify']);
});

// Capacitaciones exigidas a cada participante del ATS (M-ATS RN-ATS-03).
Route::middleware(['auth:sanctum', 'company', 'module:ATS'])->get('ats/forms/{id}/training-check', \App\Modules\REC\AtsTrainingController::class)->whereUuid('id');

// Matriz puesto × EPP, cumplimiento por trabajador y solicitudes de reposición (M-EPP RN-EPP-04).
Route::middleware(['auth:sanctum', 'company', 'module:EPP'])->prefix('epp')->group(function () {
    Route::get('matrix', [\App\Modules\REC\PpeMatrixController::class, 'matrix']);
    Route::get('stock', [\App\Modules\REC\PpeMatrixController::class, 'stock']);
    Route::get('kardex.xlsx', [\App\Modules\REC\PpeMatrixController::class, 'kardex']);
    Route::get('replacement-requests', [\App\Modules\REC\PpeMatrixController::class, 'requests']);
    Route::post('replacement-requests/{id}/resolve', [\App\Modules\REC\PpeMatrixController::class, 'resolve'])->whereUuid('id');
    Route::get('quick/lookup', [\App\Modules\REC\PpeQuickController::class, 'lookup'])->middleware('throttle:60,1');
    Route::post('quick/deliver', [\App\Modules\REC\PpeQuickController::class, 'deliver'])->middleware('throttle:30,1');
});
