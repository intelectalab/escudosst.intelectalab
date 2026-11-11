<?php

use App\Modules\AUD\Http\Controllers\CompanyAuditController;
use App\Modules\AUD\Http\Controllers\PlatformAuditController;
use Illuminate\Support\Facades\Route;

// Panel interno del dueño: bitácora de eventos de plataforma (SCR-AUD-01).
Route::middleware(['auth:sanctum', 'platform'])->prefix('internal')->group(function () {
    Route::get('audit', [PlatformAuditController::class, 'index']);
    Route::get('audit/verify', [PlatformAuditController::class, 'verify']);
});

// Bitácora de la empresa activa (M-AUD).
Route::middleware(['auth:sanctum', 'company', 'module:AUD'])->group(function () {
    Route::get('company/audit', CompanyAuditController::class);
    Route::get('company/audit/filters', [CompanyAuditController::class, 'filters']);
    Route::get('company/audit/sensitive', [CompanyAuditController::class, 'sensitive']);
    Route::get('company/audit/sensitive/export', [CompanyAuditController::class, 'sensitiveExport'])->middleware('throttle:10,1');
});
