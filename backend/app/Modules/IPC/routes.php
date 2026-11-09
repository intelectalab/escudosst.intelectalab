<?php

use App\Modules\IPC\Http\IpercController;
use Illuminate\Support\Facades\Route;

// Matriz IPERC: flujo de versiones y exportación (M-IPC). Filas y controles: /records/IPC/...
Route::middleware(['auth:sanctum', 'company', 'module:IPC'])->prefix('ipc')->group(function () {
    Route::get('matrices/{matrix}/workspace', [IpercController::class, 'workspace'])->whereUuid('matrix')->middleware('permission:ipc.iperc_matrices.read');
    Route::post('matrices/{matrix}/versions', [IpercController::class, 'newVersion'])->whereUuid('matrix')->middleware('permission:ipc.iperc_versions.create');
    Route::post('versions/{version}/submit', [IpercController::class, 'submit'])->whereUuid('version')->middleware('permission:ipc.iperc_versions.update');
    Route::post('versions/{version}/return', [IpercController::class, 'sendBack'])->whereUuid('version')->middleware('permission:ipc.iperc_versions.approve');
    Route::post('versions/{version}/approve', [IpercController::class, 'approve'])->whereUuid('version')->middleware('permission:ipc.iperc_versions.approve');
    Route::get('versions/{version}/export.pdf', [IpercController::class, 'pdf'])->whereUuid('version')->middleware('permission:ipc.iperc_matrices.export');
    Route::get('versions/{version}/export.xlsx', [IpercController::class, 'xlsx'])->whereUuid('version')->middleware('permission:ipc.iperc_matrices.export');
});
