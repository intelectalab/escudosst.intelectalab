<?php

use App\Modules\REG\RegistersController;
use Illuminate\Support\Facades\Route;

// Registros obligatorios (R.M. 050-2013-TR) en Excel, armados con los datos de los módulos (M-REG).
Route::middleware(['auth:sanctum', 'company', 'module:REG'])->prefix('reg')->group(function () {
    Route::get('types', [RegistersController::class, 'index']);
    Route::get('{type}.xlsx', [RegistersController::class, 'export'])->where('type', '[a-z_]+');
});
