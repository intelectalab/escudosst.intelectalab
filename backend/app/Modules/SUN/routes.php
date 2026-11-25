<?php

use App\Modules\SUN\DossierBuilder;
use App\Modules\SUN\ShareLinkController;
use Illuminate\Support\Facades\Route;

// Modo inspección SUNAFIL: armar el expediente con los datos de la empresa (M-SUN), el PDF único foliado y compartirlo con un enlace temporal.
Route::middleware(['auth:sanctum', 'company', 'module:SUN', 'permission:sun.inspection_dossiers.read'])->prefix('sun')->group(function () {
    Route::get('diagnosis', \App\Modules\SUN\DiagnosisController::class);
    Route::get('diagnosis/evidence-options', [\App\Modules\SUN\DiagnosisController::class, 'evidenceOptions']);
    Route::post('diagnosis/{code}/evidence', [\App\Modules\SUN\DiagnosisController::class, 'link'])->where('code', '[a-z0-9_]+');
    Route::delete('diagnosis/{code}/evidence/{document}', [\App\Modules\SUN\DiagnosisController::class, 'unlink'])->where('code', '[a-z0-9_]+')->whereUuid('document');
    Route::post('dossiers/{dossier}/build', DossierBuilder::class)->whereUuid('dossier');
    Route::get('dossiers/{dossier}/status', [DossierBuilder::class, 'status'])->whereUuid('dossier');
    Route::get('dossiers/{dossier}/zip', \App\Modules\SUN\DossierZip::class)->whereUuid('dossier');
    Route::get('dossiers/{dossier}/print', \App\Modules\SUN\DossierPrint::class)->whereUuid('dossier');
    Route::get('dossiers/{dossier}/share-links', [ShareLinkController::class, 'index'])->whereUuid('dossier');
    Route::get('dossiers/{dossier}/items', [\App\Modules\SUN\DossierItemsController::class, 'index'])->whereUuid('dossier');
    Route::patch('dossiers/{dossier}/items/{id}', [\App\Modules\SUN\DossierItemsController::class, 'update'])->whereUuid(['dossier', 'id']);
    Route::post('dossiers/{dossier}/evidence', [\App\Modules\SUN\DossierItemsController::class, 'evidence'])->whereUuid('dossier');
    Route::put('dossiers/{dossier}/order', [\App\Modules\SUN\DossierItemsController::class, 'order'])->whereUuid('dossier');
    Route::post('dossiers/{dossier}/share', [ShareLinkController::class, 'store'])->whereUuid('dossier');
    Route::post('share-links/{id}/approve', [ShareLinkController::class, 'approve'])->whereUuid('id');
    Route::post('share-links/{id}/reject', [ShareLinkController::class, 'reject'])->whereUuid('id');
    Route::post('share-links/{id}/revoke', [ShareLinkController::class, 'revoke'])->whereUuid('id');
});
Route::middleware('throttle:30,1')->get('public/sun/{company}/{token}', [ShareLinkController::class, 'show']);
Route::middleware('throttle:10,1')->get('public/sun/{company}/{token}/download', [ShareLinkController::class, 'download']);
Route::middleware(['auth:sanctum', 'company', 'module:SUN', 'permission:sun.self_diagnostics.read'])->prefix('sun/self-diagnostics')->group(function () {
    Route::post('', [\App\Modules\SUN\SelfDiagnosisController::class, 'store']);
    Route::get('monthly', [\App\Modules\SUN\SelfDiagnosisController::class, 'monthly']);
    Route::get('{id}', [\App\Modules\SUN\SelfDiagnosisController::class, 'show'])->whereUuid('id');
});
// RN-SUN-07: el médico ocupacional aprueba (o no) incluir la constancia de aptitud de cada trabajador en el expediente.
Route::middleware(['auth:sanctum', 'company', 'module:SUN', 'permission:sun.dossier_items.approve'])->prefix('sun')->group(function () {
    Route::get('medical-approvals', [\App\Modules\SUN\MedicalApprovalController::class, 'index']);
    Route::post('dossier-items/{id}/approve', [\App\Modules\SUN\MedicalApprovalController::class, 'approve'])->whereUuid('id');
    Route::post('dossier-items/{id}/reject', [\App\Modules\SUN\MedicalApprovalController::class, 'reject'])->whereUuid('id');
});
