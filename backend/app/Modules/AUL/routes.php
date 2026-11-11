<?php

use App\Modules\AUL\AulController;
use App\Modules\AUL\VtaCatalogController;
use App\Modules\AUL\VtaController;
use Illuminate\Support\Facades\Route;

// Venta de cursos (VTA): pedido y cobro (simulado en modo de prueba hasta configurar la pasarela).
Route::middleware(['auth:sanctum', 'company', 'module:VTA'])->prefix('vta')->group(function () {
    Route::post('sell', [VtaController::class, 'sell']);
    Route::post('orders/{id}/pay', [VtaController::class, 'pay'])->whereUuid('id');
    Route::get('catalog', [VtaCatalogController::class, 'link']);
    Route::put('catalog', [VtaCatalogController::class, 'setSlug']);
});

// Catálogo público de cursos de la empresa (sin sesión): lectura y solicitud de compra con límite de uso por IP (RN-VTA-24).
Route::prefix('public/courses/{catalog}')->where(['catalog' => '[A-Za-z0-9-]{3,60}', 'offer' => '[a-z0-9-]{1,120}'])->group(function () {
    Route::middleware('throttle:web-read')->get('/', [VtaCatalogController::class, 'index']);
    Route::middleware('throttle:web-read')->get('{offer}', [VtaCatalogController::class, 'show']);
    Route::middleware('throttle:web-forms')->post('{offer}/order', [VtaCatalogController::class, 'order']);
});

// Aula virtual para quien aprende: mis cursos, lecciones, examen; y alternativas de las preguntas.
Route::middleware(['auth:sanctum', 'company', 'module:AUL'])->prefix('aul')->group(function () {
    Route::get('my', [AulController::class, 'my']);
    Route::get('enrollments/{id}', [AulController::class, 'show'])->whereUuid('id');
    Route::post('enrollments/{id}/lessons/{lesson}/done', [AulController::class, 'done'])->whereUuid(['id', 'lesson']);
    Route::post('enrollments/{id}/lessons/{lesson}/exam', [AulController::class, 'exam'])->whereUuid(['id', 'lesson'])->middleware('throttle:20,1');
    Route::put('questions/{id}/options', [AulController::class, 'options'])->whereUuid('id');
});
