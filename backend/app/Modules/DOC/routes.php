<?php

use App\Modules\DOC\FilesController;
use Illuminate\Support\Facades\Route;

// Archivos de la empresa (evidencias, fotos, planos, documentos), con empresa activa.
Route::middleware(['auth:sanctum', 'company'])->group(function () {
    Route::post('files', [FilesController::class, 'store'])->middleware('throttle:60,1');
    Route::get('files/{id}', [FilesController::class, 'show'])->whereUuid('id');
});
