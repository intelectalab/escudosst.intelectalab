<?php

use App\Modules\INV\CauseTreeController;
use App\Modules\INV\ScatController;
use App\Modules\INV\TeamSignaturesController;
use Illuminate\Support\Facades\Route;

// Investigación de accidentes e incidentes (M-INV): árbol de causas, asistente SCAT e informe imprimible de la ficha.
// El resto: /records/INV/...
Route::middleware(['auth:sanctum', 'company', 'module:INV', 'permission:inv.investigations.read'])->prefix('inv')->group(function () {
    Route::get('investigations/{id}/tree', CauseTreeController::class)->whereUuid('id');
    Route::get('scat/catalog', [ScatController::class, 'catalog']);
    Route::get('investigations/{id}/scat', [ScatController::class, 'show'])->whereUuid('id');
    Route::put('investigations/{id}/scat', [ScatController::class, 'save'])->whereUuid('id')->middleware('permission:inv.investigation_causes.create');
    Route::get('investigations/{id}/report', [ScatController::class, 'report'])->whereUuid('id');
    // Firmas del equipo investigador por M-FIR («Solicitar firmas»).
    Route::get('investigations/{id}/signatures', [TeamSignaturesController::class, 'index'])->whereUuid('id');
    Route::post('investigations/{id}/signatures', [TeamSignaturesController::class, 'request'])->whereUuid('id')->middleware('throttle:20,1');
});
