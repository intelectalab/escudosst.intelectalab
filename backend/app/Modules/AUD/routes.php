<?php

use App\Modules\AUD\Http\Controllers\PlatformAuditController;
use Illuminate\Support\Facades\Route;

// Panel interno del dueño: bitácora de eventos de plataforma (SCR-AUD-01).
Route::middleware(['auth:sanctum', 'platform'])->prefix('internal')->group(function () {
    Route::get('audit', [PlatformAuditController::class, 'index']);
    Route::get('audit/verify', [PlatformAuditController::class, 'verify']);
});
