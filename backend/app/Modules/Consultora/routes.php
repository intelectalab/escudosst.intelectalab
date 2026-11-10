<?php

use App\Modules\Consultora\WhiteLabelController;
use Illuminate\Support\Facades\Route;

// Marca blanca de la consultora (M-CON): la configura la consultora; sus empresas cliente la ven.
Route::middleware(['auth:sanctum', 'company', 'module:CON'])->prefix('con')->group(function () {
    Route::get('white-label', [WhiteLabelController::class, 'show']);
    Route::put('white-label', [WhiteLabelController::class, 'save'])->middleware('throttle:20,1');
});
Route::middleware(['auth:sanctum', 'company'])->get('brand', [WhiteLabelController::class, 'brand']);
