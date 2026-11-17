<?php

use App\Modules\EMO\EmoClinicalController;
use Illuminate\Support\Facades\Route;

// EMO clínico (M-EMO SCR-EMO-03/05): resultados en la base médica, con re-autenticación y registro de accesos.
// Las órdenes y la aptitud (base principal) siguen en /records/EMO/...
Route::middleware(['auth:sanctum', 'company', 'module:EMO'])->prefix('emo')->group(function () {
    Route::post('reauth', [EmoClinicalController::class, 'reauth'])->middleware('throttle:10,1');
    Route::get('orders/{order}/clinical', [EmoClinicalController::class, 'show'])->whereUuid('order');
    Route::put('orders/{order}/clinical', [EmoClinicalController::class, 'save'])->whereUuid('order');
    Route::post('exams/{exam}/files', [EmoClinicalController::class, 'upload'])->whereUuid('exam');
    Route::get('files/{file}', [EmoClinicalController::class, 'download'])->whereUuid('file');
    Route::get('workers/{worker}/history', [EmoClinicalController::class, 'history'])->whereUuid('worker');
    // Protocolos por puesto (SCR-EMO-06), sin datos clínicos.
    Route::get('protocols', [\App\Modules\EMO\ProtocolsController::class, 'index']);
    Route::post('protocols', [\App\Modules\EMO\ProtocolsController::class, 'save']);
    Route::patch('positions/{id}', [\App\Modules\EMO\ProtocolsController::class, 'position'])->whereUuid('id');
});

// Programación de EMO sin datos clínicos: vencidos por sede y puesto y órdenes en lote (M-EMO SCR-EMO-01).
Route::middleware(['auth:sanctum', 'company', 'module:EMO'])->prefix('emo')->group(function () {
    Route::get('due', [\App\Modules\EMO\EmoScheduleController::class, 'due']);
    Route::post('orders/bulk', [\App\Modules\EMO\EmoScheduleController::class, 'bulk']);
});
