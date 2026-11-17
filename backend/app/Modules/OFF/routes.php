<?php

use App\Modules\OFF\Http\SyncController;
use Illuminate\Support\Facades\Route;

// App offline (M-OFF): registro de dispositivos, lotes de sincronización idempotentes y conflictos. El resto: /records/OFF/...
Route::middleware(['auth:sanctum', 'company', 'module:OFF'])->prefix('sync')->group(function () {
    Route::post('devices', [SyncController::class, 'device'])->middleware(['permission:off.devices.read', 'throttle:30,1']);
    Route::get('devices', [\App\Modules\OFF\Http\DevicesController::class, 'index'])->middleware('permission:off.devices.read');
    Route::post('devices/{id}/revoke', [\App\Modules\OFF\Http\DevicesController::class, 'revoke'])->whereUuid('id');
    Route::get('package', \App\Modules\OFF\Http\PackageController::class)->middleware(['permission:off.devices.read', 'throttle:20,1']);
    Route::get('report.xlsx', [\App\Modules\OFF\Http\DevicesController::class, 'report'])->middleware('permission:off.devices.read');
    Route::post('batches', [SyncController::class, 'batch'])->middleware(['permission:off.sync_batches.create', 'throttle:60,1']);
    Route::get('conflicts', [SyncController::class, 'conflicts'])->middleware('permission:off.sync_conflicts.read');
    Route::post('conflicts/{conflict}/resolve', [SyncController::class, 'resolve'])->whereUuid('conflict')->middleware('permission:off.sync_conflicts.update');
});
