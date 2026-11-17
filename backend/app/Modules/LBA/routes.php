<?php

use App\Modules\LBA\LoadBaseQuestions;
use Illuminate\Support\Facades\Route;

// Línea base: cargar las preguntas base al diagnóstico (M-LBA). El resto: /records/LBA/...
Route::middleware(['auth:sanctum', 'company', 'module:LBA', 'permission:lba.baseline_items.create'])->prefix('lba')->group(function () {
    Route::post('assessments/{assessment}/load', LoadBaseQuestions::class)->whereUuid('assessment');
});

// Comparativo entre dos diagnósticos y lista de verificación del Anexo 3 en Excel (M-LBA SCR-LBA-05).
Route::middleware(['auth:sanctum', 'company', 'module:LBA'])->prefix('lba')->group(function () {
    Route::get('compare', [\App\Modules\LBA\BaselineToolsController::class, 'compare']);
    Route::get('assessments/{id}.xlsx', [\App\Modules\LBA\BaselineToolsController::class, 'xlsx'])->whereUuid('id');
});
