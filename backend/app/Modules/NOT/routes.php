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

// SCR-NOT-01 Reglas de alerta por evento: anticipaciones, destinatarios y canales de la empresa (RN-NOT-03).
Route::middleware(['auth:sanctum', 'company', 'permission:not.notification_rules.read'])->group(function () {
    Route::get('not/rules', [\App\Modules\NOT\NotRulesController::class, 'index']);
    Route::put('not/rules/{event}', [\App\Modules\NOT\NotRulesController::class, 'update'])->where('event', '[a-z_]+\.[a-z_]+');
});
