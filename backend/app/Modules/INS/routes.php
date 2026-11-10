<?php

use App\Modules\INS\Http\InspectionController;
use Illuminate\Support\Facades\Route;

// Ejecución de inspecciones y hallazgos (M-INS). El resto de registros: /records/INS/...
Route::middleware(['auth:sanctum', 'company', 'module:INS'])->prefix('ins')->group(function () {
    Route::get('inspections/{inspection}/checklist', [InspectionController::class, 'checklist'])->whereUuid('inspection')->middleware('permission:ins.inspections.read');
    Route::post('inspections/{inspection}/answers', [InspectionController::class, 'answers'])->whereUuid('inspection')->middleware('permission:ins.inspection_answers.create');
    Route::post('inspections/{inspection}/finish', [InspectionController::class, 'finish'])->whereUuid('inspection')->middleware('permission:ins.inspections.update');
    Route::post('findings/{finding}/action', [InspectionController::class, 'action'])->whereUuid('finding')
        ->middleware(['permission:ins.findings.update', 'module:ACR', 'permission:acr.corrective_actions.create']);
});
