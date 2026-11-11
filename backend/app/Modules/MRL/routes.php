<?php

use App\Modules\MRL\NewsController;
use App\Modules\MRL\NormCatalogController;
use Illuminate\Support\Facades\Route;

// Matriz de requisitos legales (M-MRL): catálogo de normas (globales + propias de la empresa) y «Agregar desde el catálogo».
// La matriz y las evaluaciones: /records/MRL/...
Route::middleware(['auth:sanctum', 'company', 'module:MRL'])->prefix('mrl')->group(function () {
    Route::get('catalog', [NormCatalogController::class, 'index'])->middleware('permission:mrl.legal_norms.read');
    Route::get('catalog/requirements', [NormCatalogController::class, 'requirements'])->middleware('permission:mrl.legal_norms.read');
    Route::post('catalog/norms', [NormCatalogController::class, 'storeNorm'])->middleware('permission:mrl.legal_norms.create');
    Route::patch('catalog/norms/{id}', [NormCatalogController::class, 'updateNorm'])->whereUuid('id')->middleware('permission:mrl.legal_norms.update');
    Route::post('catalog/add', [NormCatalogController::class, 'add'])->middleware('permission:mrl.legal_requirements.create');
    // Novedades normativas (SCR-MRL-05): requisitos que requieren revisión por cambio de su norma.
    Route::get('news', [NewsController::class, 'index'])->middleware('permission:mrl.legal_requirements.read');
    Route::post('requirements/{id}/reviewed', [NewsController::class, 'reviewed'])->whereUuid('id');
});
