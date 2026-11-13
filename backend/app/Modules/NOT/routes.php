<?php

use App\Modules\NOT\PrefsController;
use Illuminate\Support\Facades\Route;

// SCR-NOT-03 Mis preferencias de avisos (canales por módulo y resumen diario).
Route::middleware(['auth:sanctum', 'company'])->group(function () {
    Route::get('me/notification-prefs', [PrefsController::class, 'show']);
    Route::put('me/notification-prefs', [PrefsController::class, 'update']);
});
