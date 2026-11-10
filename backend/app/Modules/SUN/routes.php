<?php

use App\Modules\SUN\DossierBuilder;
use Illuminate\Support\Facades\Route;

// Modo inspección SUNAFIL: armar el expediente con los datos de la empresa (M-SUN).
Route::middleware(['auth:sanctum', 'company', 'module:SUN', 'permission:sun.inspection_dossiers.read'])->prefix('sun')->group(function () {
    Route::post('dossiers/{dossier}/build', DossierBuilder::class)->whereUuid('dossier');
});
