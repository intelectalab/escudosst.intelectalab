<?php

use App\Modules\OFF\Http\SyncController;
use Illuminate\Support\Facades\Route;

// App offline (M-OFF): registro de dispositivos, lotes de sincronización idempotentes y conflictos. El resto: /records/OFF/...
Route::middleware(['auth:sanctum', 'company', 'module:OFF'])->prefix('sync')->group(function () {
    Route::post('devices', [SyncController::class, 'device'])->middleware(['permission:off.devices.read', 'throttle:30,1']);
    Route::post('batches', [SyncController::class, 'batch'])->middleware(['permission:off.sync_batches.create', 'throttle:60,1']);
    Route::get('conflicts', [SyncController::class, 'conflicts'])->middleware('permission:off.sync_conflicts.read');
    Route::post('conflicts/{conflict}/resolve', [SyncController::class, 'resolve'])->whereUuid('conflict')->middleware('permission:off.sync_conflicts.update');
});
