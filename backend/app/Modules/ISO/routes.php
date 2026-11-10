<?php

use App\Modules\ISO\IsoPseController;
use Illuminate\Support\Facades\Route;

// Sistema integrado ISO y paquetes sectoriales (complementos).
Route::middleware(['auth:sanctum', 'company', 'module:ISO'])->post('iso/assess', [IsoPseController::class, 'assess']);
Route::middleware(['auth:sanctum', 'company', 'module:PSE'])->prefix('pse')->group(function () {
    Route::get('packs', [IsoPseController::class, 'packs']);
    Route::post('packs/{id}/apply', [IsoPseController::class, 'apply'])->whereUuid('id');
});
