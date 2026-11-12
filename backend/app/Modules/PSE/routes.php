<?php

use App\Modules\PSE\PseController;
use Illuminate\Support\Facades\Route;

// Paquetes sectoriales (M-PSE): instalar con vista previa, actualizar por ítem, desinstalar e inventario.
Route::middleware(['auth:sanctum', 'company', 'module:PSE'])->prefix('pse')->group(function () {
    Route::get('packs', [PseController::class, 'index']);
    Route::get('packs/{id}/preview', [PseController::class, 'preview'])->whereUuid('id');
    Route::post('packs/{id}/install', [PseController::class, 'install'])->whereUuid('id');
    // Nombre anterior, para la pantalla que ya existía.
    Route::post('packs/{id}/apply', [PseController::class, 'install'])->whereUuid('id');
    Route::get('packs/{id}/updates', [PseController::class, 'updates'])->whereUuid('id');
    Route::post('packs/{id}/updates', [PseController::class, 'applyUpdates'])->whereUuid('id');
    Route::post('packs/{id}/uninstall', [PseController::class, 'uninstall'])->whereUuid('id');
    Route::get('inventory', [PseController::class, 'inventory']);
});
