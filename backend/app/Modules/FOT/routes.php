<?php

use App\Modules\FOT\CredentialCheckController;
use Illuminate\Support\Facades\Route;

// Fotocheck (M-FOT): indicadores y revisión de requisitos de un trabajador antes de emitir. El resto: /records/FOT/...
Route::middleware(['auth:sanctum', 'company', 'module:FOT', 'permission:fot.credentials.read'])->prefix('fot')->group(function () {
    Route::get('summary', [CredentialCheckController::class, 'summary']);
    Route::get('evaluate', [CredentialCheckController::class, 'evaluate']);
});

// Requisitos por puesto, verificación en campo con historial e impresión por lote (M-FOT SCR-FOT-01/03/04/07).
Route::middleware(['auth:sanctum', 'company', 'module:FOT'])->prefix('fot')->group(function () {
    Route::get('matrix', [\App\Modules\FOT\FotToolsController::class, 'matrix']);
    Route::get('credentials/batch', [\App\Modules\FOT\FotToolsController::class, 'batch']);
    Route::get('credentials/{id}/verifications', [\App\Modules\FOT\FotToolsController::class, 'history'])->whereUuid('id');
    Route::post('credentials/{id}/verifications', [\App\Modules\FOT\FotToolsController::class, 'verify'])->whereUuid('id')->middleware('throttle:60,1');
});
