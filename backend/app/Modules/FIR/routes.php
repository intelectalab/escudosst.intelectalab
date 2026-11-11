<?php

use App\Modules\FIR\SignatureController;
use App\Modules\FIR\SignatureImages;
use App\Modules\FIR\SignatureToolsController;
use Illuminate\Support\Facades\Route;

// Firma electrónica de registros (M-FIR): pedir, firmar con evidencia y rechazar.
Route::middleware(['auth:sanctum', 'company', 'module:FIR'])->prefix('fir')->group(function () {
    Route::get('subject/{table}/{id}', [SignatureController::class, 'subject'])->where('table', '[a-z_]{2,60}')->whereUuid('id');
    Route::post('requests', [SignatureController::class, 'request']);
    Route::post('requests/{id}/sign', [SignatureController::class, 'sign'])->whereUuid('id')->middleware('throttle:10,1');
    Route::post('requests/{id}/decline', [SignatureController::class, 'decline'])->whereUuid('id');
    Route::get('signatures/{id}/image', [SignatureImages::class, 'image'])->whereUuid('id');
    // Seguimiento (reenviar recordatorio) y verificación de integridad (SCR-FIR-03/04).
    Route::get('pending', [SignatureToolsController::class, 'pending']);
    Route::post('requests/{id}/remind', [SignatureToolsController::class, 'remind'])->whereUuid('id')->middleware('throttle:20,1');
    Route::get('verify', [SignatureToolsController::class, 'verifyCode'])->middleware('throttle:30,1');
    Route::get('verify/{table}/{id}', [SignatureToolsController::class, 'verifySubject'])->where('table', '[a-z_]{2,60}')->whereUuid('id');
});

// Mi firma (dibujada o foto) para pegarla sola en los documentos que firmo o valido.
Route::middleware('auth:sanctum')->prefix('me/signature')->group(function () {
    Route::get('/', [SignatureImages::class, 'show']);
    Route::put('/', [SignatureImages::class, 'save'])->middleware('throttle:20,1');
    Route::delete('/', [SignatureImages::class, 'destroy']);
});
