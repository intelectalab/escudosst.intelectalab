<?php

use App\Modules\LBA\LoadBaseQuestions;
use Illuminate\Support\Facades\Route;

// Línea base: cargar las preguntas base al diagnóstico (M-LBA). El resto: /records/LBA/...
Route::middleware(['auth:sanctum', 'company', 'module:LBA', 'permission:lba.baseline_items.create'])->prefix('lba')->group(function () {
    Route::post('assessments/{assessment}/load', LoadBaseQuestions::class)->whereUuid('assessment');
});
