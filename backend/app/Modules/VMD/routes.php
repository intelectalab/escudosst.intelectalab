<?php

use App\Modules\VMD\VmdController;
use Illuminate\Support\Facades\Route;

// Vigilancia médica (M-VMD): base médica separada, con re-autenticación y registro de accesos.
Route::middleware(['auth:sanctum', 'company', 'module:VMD'])->prefix('vmd')->group(function () {
    Route::post('reauth', [VmdController::class, 'reauth'])->middleware('throttle:10,1');
    Route::get('programs', [VmdController::class, 'programs']);
    Route::post('programs', [VmdController::class, 'createProgram']);
    Route::patch('programs/{id}', [VmdController::class, 'updateProgram'])->whereUuid('id');
    Route::get('cases', [VmdController::class, 'cases']);
    Route::post('cases', [VmdController::class, 'createCase']);
    Route::get('cases/{id}', [VmdController::class, 'showCase'])->whereUuid('id');
    Route::patch('cases/{id}', [VmdController::class, 'updateCase'])->whereUuid('id');
    Route::post('cases/{id}/notes', [VmdController::class, 'addNote'])->whereUuid('id');
});
