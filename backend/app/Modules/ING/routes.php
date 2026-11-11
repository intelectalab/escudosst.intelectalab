<?php

use App\Modules\ING\GateController;
use Illuminate\Support\Facades\Route;

// Portería (M-ING): escanear QR, visitantes y quiénes están dentro. Puntos de acceso e historial: /records/ING/...
Route::middleware(['auth:sanctum', 'company', 'module:ING'])->prefix('ing')->group(function () {
    Route::get('inside', [GateController::class, 'inside'])->middleware('permission:ing.access_events.read');
    Route::middleware('permission:ing.access_events.create')->group(function () {
        Route::get('gate', [GateController::class, 'index']);
        Route::post('gate/scan', [GateController::class, 'scan'])->middleware('throttle:120,1');
        Route::post('gate/visitor', [GateController::class, 'visitor']);
        Route::post('gate/exit', [GateController::class, 'exit']);
    });
});
