<?php

use App\Modules\FIR\SignatureController;
use Illuminate\Support\Facades\Route;

// Firma electrónica de registros (M-FIR): pedir, firmar con evidencia y rechazar.
Route::middleware(['auth:sanctum', 'company', 'module:FIR'])->prefix('fir')->group(function () {
    Route::get('subject/{table}/{id}', [SignatureController::class, 'subject'])->where('table', '[a-z_]{2,60}')->whereUuid('id');
    Route::post('requests', [SignatureController::class, 'request']);
    Route::post('requests/{id}/sign', [SignatureController::class, 'sign'])->whereUuid('id')->middleware('throttle:10,1');
    Route::post('requests/{id}/decline', [SignatureController::class, 'decline'])->whereUuid('id');
});
