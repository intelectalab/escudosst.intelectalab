<?php

use App\Modules\PAS\RescheduleController;
use App\Modules\PAS\ScheduleController;
use Illuminate\Support\Facades\Route;

// Cronograma del Programa Anual SST (M-PAS SCR-PAS-01). Las ejecuciones se registran con /records/PAS/activity_executions.
Route::middleware(['auth:sanctum', 'company', 'module:PAS'])->prefix('pas')->group(function () {
    Route::get('schedule', ScheduleController::class)->middleware('permission:pas.annual_programs.read');
    Route::post('activities/{id}/reschedule', RescheduleController::class)->middleware('permission:pas.program_activities.update');
});

// Mis actividades, validación de ejecuciones, avance por mes y responsable, y versiones del programa (M-PAS SCR-PAS-04/05, RN-PAS-01/03).
Route::middleware(['auth:sanctum', 'company', 'module:PAS'])->prefix('pas')->group(function () {
    Route::get('mine', [\App\Modules\PAS\ProgramToolsController::class, 'mine']);
    Route::get('pending-validation', [\App\Modules\PAS\ProgramToolsController::class, 'pending']);
    Route::post('executions/{id}/validate', [\App\Modules\PAS\ProgramToolsController::class, 'validateExecution'])->whereUuid('id');
    Route::get('progress', [\App\Modules\PAS\ProgramToolsController::class, 'progress']);
    Route::post('programs/{id}/new-version', [\App\Modules\PAS\ProgramToolsController::class, 'newVersion'])->whereUuid('id');
});
