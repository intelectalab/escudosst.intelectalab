<?php

use App\Modules\IAA\AssistantController;
use Illuminate\Support\Facades\Route;

// Asistente IA (complemento): responde con la biblioteca que entrena el equipo de EscudoSST (sin IA de pago).
Route::middleware(['auth:sanctum', 'company', 'module:IAA', 'throttle:20,1'])->post('iaa/suggest', [AssistantController::class, 'suggest']);

// IPERC sugerido e investigación guiada: propuestas pendientes que una persona acepta (al borrador / al árbol) o descarta.
Route::middleware(['auth:sanctum', 'company', 'module:IAA'])->prefix('iaa')->group(function () {
    Route::post('iperc/suggest', [\App\Modules\IAA\GuidedController::class, 'iperc'])->middleware('throttle:20,1');
    Route::post('investigation/guide', [\App\Modules\IAA\GuidedController::class, 'investigation'])->middleware('throttle:20,1');
    Route::post('suggestions/{id}/accept', [\App\Modules\IAA\GuidedController::class, 'accept'])->whereUuid('id');
    Route::post('suggestions/{id}/reject', [\App\Modules\IAA\GuidedController::class, 'reject'])->whereUuid('id');
    Route::get('history', [\App\Modules\IAA\GuidedController::class, 'history']);
    Route::get('usage', [\App\Modules\IAA\GuidedController::class, 'usage']);
    Route::get('requests/{id}/suggestions', [\App\Modules\IAA\GuidedController::class, 'suggestions'])->whereUuid('id');
});

// Panel › Asistente IA: el equipo entrena al asistente (temas, palabras clave, preguntas sin respuesta).
Route::middleware(['auth:sanctum', 'platform'])->prefix('internal/assistant')->group(function () {
    Route::get('/', [\App\Modules\IAA\KnowledgeController::class, 'index']);
    Route::post('topics', [\App\Modules\IAA\KnowledgeController::class, 'store']);
    Route::put('topics/{id}', [\App\Modules\IAA\KnowledgeController::class, 'update'])->whereUuid('id');
    Route::delete('topics/{id}', [\App\Modules\IAA\KnowledgeController::class, 'destroy'])->whereUuid('id');
    Route::post('misses/{id}', [\App\Modules\IAA\KnowledgeController::class, 'miss'])->whereUuid('id');
    Route::post('try', [\App\Modules\IAA\KnowledgeController::class, 'try']);
});
