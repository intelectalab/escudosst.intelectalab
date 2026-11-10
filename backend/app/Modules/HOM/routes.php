<?php

use App\Modules\HOM\HomController;
use Illuminate\Support\Facades\Route;

// Homologación de proveedores (complemento): criterios de la plantilla y puntajes de cada evaluación.
Route::middleware(['auth:sanctum', 'company', 'module:HOM'])->prefix('hom')->group(function () {
    Route::put('templates/{id}/criteria', [HomController::class, 'criteria'])->whereUuid('id');
    Route::put('evaluations/{id}/scores', [HomController::class, 'scores'])->whereUuid('id');
});
