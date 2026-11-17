<?php

use App\Modules\EST\MonthlyStatsController;
use Illuminate\Support\Facades\Route;

// Estadísticas mensuales (M-EST): calcular y cerrar meses. Horas-hombre y lectura: /records/EST/...
Route::middleware(['auth:sanctum', 'company', 'module:EST'])->prefix('est')->group(function () {
    Route::get('summary', \App\Modules\EST\SummaryController::class)->middleware('permission:est.monthly_statistics.read');
    Route::post('months', [MonthlyStatsController::class, 'compute'])->middleware('permission:est.monthly_statistics.generate');
    Route::post('months/{id}/close', [MonthlyStatsController::class, 'close'])->whereUuid('id')->middleware('permission:est.monthly_statistics.close');
    // Herramientas (SCR-EST-01/03/04, RN-EST-03/08): horas sugeridas, importación, validación, parámetros, conciliación y ajustes.
    Route::get('suggest', [\App\Modules\EST\EstToolsController::class, 'suggest']);
    Route::post('man-hours/import', [\App\Modules\EST\EstToolsController::class, 'import'])->middleware('throttle:20,1');
    Route::post('man-hours/{id}/validate', [\App\Modules\EST\EstToolsController::class, 'validateHours'])->whereUuid('id');
    Route::get('params', [\App\Modules\EST\EstToolsController::class, 'params']);
    Route::post('params', [\App\Modules\EST\EstToolsController::class, 'saveParam']);
    Route::get('months/{year}/{month}/reconcile', [\App\Modules\EST\EstToolsController::class, 'reconcile'])->whereNumber(['year', 'month']);
    Route::post('months/{id}/adjust', [\App\Modules\EST\EstToolsController::class, 'adjust'])->whereUuid('id');
});
