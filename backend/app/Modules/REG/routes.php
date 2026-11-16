<?php

use App\Modules\REG\RegistersController;
use Illuminate\Support\Facades\Route;

// Registros obligatorios (R.M. 050-2013-TR) en Excel, armados con los datos de los módulos (M-REG).
Route::middleware(['auth:sanctum', 'company', 'module:REG'])->prefix('reg')->group(function () {
    Route::get('types', [RegistersController::class, 'index']);
    Route::get('panel', [RegistersController::class, 'panel']);
    Route::get('{type}/preview', [RegistersController::class, 'preview'])->where('type', '[a-z_]+');
    Route::get('{type}.xlsx', [RegistersController::class, 'export'])->where('type', '[a-z_]+');
    // Versiones generadas y firmadas (RN-REG-02/03/05): validación previa, generación, consulta y Excel desde los datos congelados.
    Route::get('{type}/check', [RegistersController::class, 'check'])->where('type', '[a-z_]+');
    Route::post('{type}/generate', [RegistersController::class, 'generate'])->where('type', '[a-z_]+');
    Route::get('exports/{id}.xlsx', [RegistersController::class, 'versionXlsx'])->whereUuid('id');
    Route::get('exports/{id}', [RegistersController::class, 'show'])->whereUuid('id');
});
