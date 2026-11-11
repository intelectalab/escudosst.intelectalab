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
