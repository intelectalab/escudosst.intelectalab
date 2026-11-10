<?php

use App\Modules\SUN\DossierBuilder;
use App\Modules\SUN\ShareLinkController;
use Illuminate\Support\Facades\Route;

// Modo inspección SUNAFIL: armar el expediente con los datos de la empresa (M-SUN) y compartirlo con un enlace temporal.
Route::middleware(['auth:sanctum', 'company', 'module:SUN', 'permission:sun.inspection_dossiers.read'])->prefix('sun')->group(function () {
    Route::post('dossiers/{dossier}/build', DossierBuilder::class)->whereUuid('dossier');
    Route::get('dossiers/{dossier}/zip', \App\Modules\SUN\DossierZip::class)->whereUuid('dossier');
    Route::post('dossiers/{dossier}/share', [ShareLinkController::class, 'store'])->whereUuid('dossier');
    Route::post('share-links/{id}/revoke', [ShareLinkController::class, 'revoke'])->whereUuid('id');
});
Route::middleware('throttle:30,1')->get('public/sun/{company}/{token}', [ShareLinkController::class, 'show']);
