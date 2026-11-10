<?php

use App\Modules\AUL\AulController;
use App\Modules\AUL\VtaController;
use Illuminate\Support\Facades\Route;

// Venta de cursos (VTA): pedido y cobro (simulado en modo de prueba hasta configurar la pasarela).
Route::middleware(['auth:sanctum', 'company', 'module:VTA'])->prefix('vta')->group(function () {
    Route::post('sell', [VtaController::class, 'sell']);
    Route::post('orders/{id}/pay', [VtaController::class, 'pay'])->whereUuid('id');
});

// Aula virtual para quien aprende: mis cursos, lecciones, examen; y alternativas de las preguntas.
Route::middleware(['auth:sanctum', 'company', 'module:AUL'])->prefix('aul')->group(function () {
    Route::get('my', [AulController::class, 'my']);
    Route::get('enrollments/{id}', [AulController::class, 'show'])->whereUuid('id');
    Route::post('enrollments/{id}/lessons/{lesson}/done', [AulController::class, 'done'])->whereUuid(['id', 'lesson']);
    Route::post('enrollments/{id}/lessons/{lesson}/exam', [AulController::class, 'exam'])->whereUuid(['id', 'lesson'])->middleware('throttle:20,1');
    Route::put('questions/{id}/options', [AulController::class, 'options'])->whereUuid('id');
});
