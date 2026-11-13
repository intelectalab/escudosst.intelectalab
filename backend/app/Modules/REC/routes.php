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
