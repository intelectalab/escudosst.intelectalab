<?php

use App\Modules\NOT\PrefsController;
use Illuminate\Support\Facades\Route;

// SCR-NOT-03 Mis preferencias de avisos (canales por módulo y resumen diario).
Route::middleware(['auth:sanctum', 'company'])->group(function () {
    Route::get('me/notification-prefs', [PrefsController::class, 'show']);
    Route::put('me/notification-prefs', [PrefsController::class, 'update']);
});

// Reporte de avisos emitidos frente a atendidos (M-NOT §11) y rebotes del proveedor de correo (RN-NOT-07).
Route::middleware(['auth:sanctum', 'company'])->get('not/report.xlsx', [\App\Modules\NOT\NotReportsController::class, 'report']);
Route::middleware('throttle:60,1')->post('public/mail-events', [\App\Modules\NOT\NotReportsController::class, 'mailEvent']);
