<?php

use App\Modules\AMB\AmbToolsController;
use Illuminate\Support\Facades\Route;

// Medio ambiente (M-AMB): criterios de significancia de la empresa y reportes. El resto: /records/AMB/...
Route::middleware(['auth:sanctum', 'company', 'module:AMB'])->prefix('amb')->group(function () {
    Route::match(['get', 'put'], 'criteria', [AmbToolsController::class, 'criteriaEndpoint']);
    Route::get('reports.xlsx', [AmbToolsController::class, 'report']);
});
