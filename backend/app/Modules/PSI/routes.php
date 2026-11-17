<?php

use App\Modules\PSI\PsiController;
use Illuminate\Support\Facades\Route;

// Encuesta psicosocial anónima (M-PSI): enlace con sesión y respuesta pública con enlace firmado.
Route::middleware(['auth:sanctum', 'company', 'module:PSI'])->get('psi/surveys/{id}/link', [PsiController::class, 'link'])->whereUuid('id');
Route::middleware('throttle:20,1')->prefix('public/psi/{company}/{survey}/{sig}')->group(function () {
    Route::get('/', [PsiController::class, 'show']);
    Route::post('/', [PsiController::class, 'answer']);
});

// Ergonomía: RULA, lista de la R.M. 375 e informe por puesto; cuestionario psicosocial propio con versiones (M-PSI SCR-PSI-01/02, RN-PSI-04, §11).
Route::middleware(['auth:sanctum', 'company', 'module:PSI'])->prefix('psi')->group(function () {
    Route::post('rula', [\App\Modules\PSI\ErgoToolsController::class, 'rula']);
    Route::get('rm375', [\App\Modules\PSI\ErgoToolsController::class, 'rm375Items']);
    Route::post('rm375', [\App\Modules\PSI\ErgoToolsController::class, 'rm375']);
    Route::get('ergonomics/report', [\App\Modules\PSI\ErgoToolsController::class, 'report']);
    Route::get('instruments', [\App\Modules\PSI\InstrumentsController::class, 'index']);
    Route::post('instruments', [\App\Modules\PSI\InstrumentsController::class, 'store']);
    Route::put('instruments/{id}', [\App\Modules\PSI\InstrumentsController::class, 'update'])->whereUuid('id');
    Route::post('instruments/{id}/publish', [\App\Modules\PSI\InstrumentsController::class, 'publish'])->whereUuid('id');
});
