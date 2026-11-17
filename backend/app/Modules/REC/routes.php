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
    Route::get('annual_plans/{id}', 'plan')->whereUuid('id')->middleware('module:PLA');
    Route::get('annual_programs/{id}', 'program')->whereUuid('id')->middleware('module:PAS');
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
});

// Ejecutar simulacro (M-SIM SCR-SIM-02): cronómetro de alarma a evacuación completa y asistencia de la sede.
Route::middleware(['auth:sanctum', 'company', 'module:SIM'])->prefix('sim/drills/{id}')->whereUuid('id')->controller(\App\Modules\REC\DrillRunController::class)->group(function () {
    Route::get('run', 'show');
    Route::post('run', 'save')->middleware('throttle:30,1');
});

// Nuevo ATS (M-ATS SCR-ATS-02): copiar pasos desde un PETS, un ATS anterior o filas del IPERC vigente.
Route::middleware(['auth:sanctum', 'company', 'module:ATS'])->prefix('ats/forms/{id}')->whereUuid('id')->controller(\App\Modules\REC\AtsCopyController::class)->group(function () {
    Route::get('sources', 'sources');
    Route::post('copy', 'copy')->middleware('throttle:30,1');
});

// Monitoreo de agentes (M-MAG SCR-MAG-03/05): carga masiva de mediciones desde Excel y tendencia por punto.
Route::middleware(['auth:sanctum', 'company', 'module:MAG'])->prefix('mag')->controller(\App\Modules\REC\MeasurementsController::class)->group(function () {
    Route::get('import/template', 'template');
    Route::post('import', 'import')->middleware('throttle:20,1');
    Route::get('trend', 'trend');
});
