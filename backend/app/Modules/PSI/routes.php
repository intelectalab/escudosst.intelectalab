<?php

use App\Modules\PSI\PsiController;
use Illuminate\Support\Facades\Route;

// Encuesta psicosocial anónima (M-PSI): enlace con sesión y respuesta pública con enlace firmado.
Route::middleware(['auth:sanctum', 'company', 'module:PSI'])->get('psi/surveys/{id}/link', [PsiController::class, 'link'])->whereUuid('id');
Route::middleware('throttle:20,1')->prefix('public/psi/{company}/{survey}/{sig}')->group(function () {
    Route::get('/', [PsiController::class, 'show']);
    Route::post('/', [PsiController::class, 'answer']);
});
