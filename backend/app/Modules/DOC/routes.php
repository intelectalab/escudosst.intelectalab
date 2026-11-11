<?php

use App\Modules\DOC\ApprovalController;
use App\Modules\DOC\CampaignController;
use App\Modules\DOC\FilesController;
use Illuminate\Support\Facades\Route;

// Archivos de la empresa (evidencias, fotos, planos, documentos), con empresa activa.
Route::middleware(['auth:sanctum', 'company'])->group(function () {
    Route::post('files', [FilesController::class, 'store'])->middleware('throttle:60,1');
    Route::get('files/{id}', [FilesController::class, 'show'])->whereUuid('id');
});

// Bandeja de aprobación (SCR-DOC-04), ficha con cargos y campaña de cargos (SCR-DOC-02/05).
Route::middleware(['auth:sanctum', 'company', 'module:DOC'])->prefix('doc')->group(function () {
    Route::get('inbox', [ApprovalController::class, 'inbox'])->middleware('permission:doc.document_versions.read');
    Route::post('versions/{id}/approve', [ApprovalController::class, 'approve'])->whereUuid('id')->middleware('throttle:10,1');
    Route::post('versions/{id}/return', [ApprovalController::class, 'giveBack'])->whereUuid('id');
    Route::get('documents/{id}/sheet', [CampaignController::class, 'sheet'])->whereUuid('id');
    Route::post('documents/{id}/campaign', [CampaignController::class, 'campaign'])->whereUuid('id')->middleware('throttle:10,1');
    Route::post('documents/{id}/remind', [CampaignController::class, 'remind'])->whereUuid('id');
});
