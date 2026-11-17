<?php

use App\Modules\SCT\ReconcileController;
use Illuminate\Support\Facades\Route;

// Conciliación de la planilla de asegurados SCTR del periodo con los trabajadores activos (M-SCT SCR-SCT-02, RN-SCT-04).
Route::middleware(['auth:sanctum', 'company', 'module:SCT'])->prefix('sct')->group(function () {
    Route::post('reconcile', ReconcileController::class)->middleware('throttle:30,1');
});
