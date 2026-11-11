<?php

use App\Modules\INV\CauseTreeController;
use Illuminate\Support\Facades\Route;

// Investigación de accidentes e incidentes (M-INV): árbol de causas para la ficha. El resto: /records/INV/...
Route::middleware(['auth:sanctum', 'company', 'module:INV', 'permission:inv.investigations.read'])->prefix('inv')->group(function () {
    Route::get('investigations/{id}/tree', CauseTreeController::class)->whereUuid('id');
});
