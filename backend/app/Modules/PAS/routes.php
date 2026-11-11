<?php

use App\Modules\PAS\RescheduleController;
use App\Modules\PAS\ScheduleController;
use Illuminate\Support\Facades\Route;

// Cronograma del Programa Anual SST (M-PAS SCR-PAS-01). Las ejecuciones se registran con /records/PAS/activity_executions.
Route::middleware(['auth:sanctum', 'company', 'module:PAS'])->prefix('pas')->group(function () {
    Route::get('schedule', ScheduleController::class)->middleware('permission:pas.annual_programs.read');
    Route::post('activities/{id}/reschedule', RescheduleController::class)->middleware('permission:pas.program_activities.update');
});
