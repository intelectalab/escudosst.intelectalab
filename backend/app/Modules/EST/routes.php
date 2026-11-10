<?php

use App\Modules\EST\MonthlyStatsController;
use Illuminate\Support\Facades\Route;

// Estadísticas mensuales (M-EST): calcular y cerrar meses. Horas-hombre y lectura: /records/EST/...
Route::middleware(['auth:sanctum', 'company', 'module:EST'])->prefix('est')->group(function () {
    Route::post('months', [MonthlyStatsController::class, 'compute'])->middleware('permission:est.monthly_statistics.generate');
    Route::post('months/{id}/close', [MonthlyStatsController::class, 'close'])->whereUuid('id')->middleware('permission:est.monthly_statistics.close');
});
