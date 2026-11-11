<?php

use App\Modules\TRB\WorkerImport;
use App\Modules\TRB\WorkerRequirements;
use Illuminate\Support\Facades\Route;

// Trabajadores (M-TRB): semáforo de requisitos y carga desde Excel. La ficha y el legajo: /records/TRB/...
Route::middleware(['auth:sanctum', 'company', 'module:TRB'])->prefix('trb')->group(function () {
    Route::get('workers/requirements', WorkerRequirements::class)->middleware('permission:trb.workers.read');
    Route::middleware('permission:trb.workers.create')->group(function () {
        Route::get('imports/template', [WorkerImport::class, 'template']);
        Route::post('imports/preview', [WorkerImport::class, 'preview'])->middleware('throttle:30,1');
        Route::post('imports', [WorkerImport::class, 'import'])->middleware('throttle:10,1');
    });
    // Cargas anteriores (bulk_imports) y su detalle por fila (SCR-TRB-05).
    Route::middleware('permission:trb.bulk_imports.read')->group(function () {
        Route::get('imports', [WorkerImport::class, 'history']);
        Route::get('imports/{id}', [WorkerImport::class, 'show'])->whereUuid('id');
    });
});

// App del trabajador (docs/07 §5.2): activación con DNI + código o enlace, luego ingreso con DNI + PIN en su equipo.
Route::middleware('throttle:login')->prefix('auth/worker')->group(function () {
    Route::get('activation', [\App\Modules\TRB\WorkerAppAuthController::class, 'preview']);
    Route::post('activate', [\App\Modules\TRB\WorkerAppAuthController::class, 'activate']);
});
Route::middleware('throttle:20,1')->post('auth/worker/pin', [\App\Modules\TRB\WorkerAppAuthController::class, 'pin']);
// Nombre de docs/08-api.md §1.
Route::middleware('throttle:20,1')->post('auth/worker/pin-login', [\App\Modules\TRB\WorkerAppAuthController::class, 'pin']);
Route::middleware(['auth:sanctum', 'company', 'module:TRB'])->prefix('trb/workers/{id}/app-access')->whereUuid('id')->group(function () {
    Route::get('/', [\App\Modules\TRB\WorkerAppAuthController::class, 'status']);
    Route::post('/', [\App\Modules\TRB\WorkerAppAuthController::class, 'issue'])->middleware('throttle:20,1');
    Route::delete('devices/{device}', [\App\Modules\TRB\WorkerAppAuthController::class, 'revokeDevice']);
});

// Lo mío en la app del trabajador: solo los datos del trabajador vinculado a la membresía activa.
Route::middleware(['auth:sanctum', 'company'])->prefix('me/worker')->group(function () {
    Route::get('/', [\App\Modules\TRB\WorkerSelfController::class, 'home']);
    Route::patch('profile', [\App\Modules\TRB\WorkerSelfController::class, 'profile']);
    Route::get('credential', [\App\Modules\TRB\WorkerSelfController::class, 'credential']);
    Route::get('pending', [\App\Modules\TRB\WorkerSelfController::class, 'pending']);
    Route::get('items/{kind}/{id}', [\App\Modules\TRB\WorkerSelfController::class, 'item'])->where('kind', '[a-z]{3,12}');
    Route::post('sign', [\App\Modules\TRB\WorkerSelfController::class, 'sign'])->middleware('throttle:20,1');
    Route::get('trainings', [\App\Modules\TRB\WorkerSelfController::class, 'trainings']);
    Route::get('ppe', [\App\Modules\TRB\WorkerSelfController::class, 'ppe']);
    Route::get('reports', [\App\Modules\TRB\WorkerSelfController::class, 'reports']);
    Route::get('health', [\App\Modules\TRB\WorkerHealthController::class, 'summary']);
    Route::post('health/reauth', [\App\Modules\TRB\WorkerHealthController::class, 'reauth'])->middleware('throttle:10,1');
    Route::get('health/results', [\App\Modules\TRB\WorkerHealthController::class, 'results']);
    Route::get('health/files/{file}', [\App\Modules\TRB\WorkerHealthController::class, 'file'])->whereUuid('file');
});
