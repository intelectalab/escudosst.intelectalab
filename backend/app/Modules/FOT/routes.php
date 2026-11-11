<?php

use App\Modules\FOT\CredentialCheckController;
use Illuminate\Support\Facades\Route;

// Fotocheck (M-FOT): indicadores y revisión de requisitos de un trabajador antes de emitir. El resto: /records/FOT/...
Route::middleware(['auth:sanctum', 'company', 'module:FOT', 'permission:fot.credentials.read'])->prefix('fot')->group(function () {
    Route::get('summary', [CredentialCheckController::class, 'summary']);
    Route::get('evaluate', [CredentialCheckController::class, 'evaluate']);
});
