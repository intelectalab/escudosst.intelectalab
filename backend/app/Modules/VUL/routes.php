<?php

use App\Modules\VUL\VulController;
use Illuminate\Support\Facades\Route;

// Grupos vulnerables (M-VUL SCR-VUL-01/02/03): adecuaciones (base principal) con su cruce con el IPERC del puesto,
// y la condición en la base médica solo para el médico ocupacional con clave médica. El resto: /records/VUL/...
Route::middleware(['auth:sanctum', 'company', 'module:VUL'])->prefix('vul')->group(function () {
    Route::get('adjustments', [VulController::class, 'adjustments']);
    Route::post('adjustments', [VulController::class, 'store']);
    Route::put('adjustments/{id}', [VulController::class, 'update'])->whereUuid('id');
    Route::post('compatibility', [VulController::class, 'compatibility']);
    Route::post('reauth', [VulController::class, 'reauth'])->middleware('throttle:10,1');
    Route::get('records', [VulController::class, 'records']);
    Route::post('records', [VulController::class, 'storeRecord']);
    Route::put('records/{id}', [VulController::class, 'updateRecord'])->whereUuid('id');
});
