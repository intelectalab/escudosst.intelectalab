<?php

use App\Modules\CAP\CertificatesController;
use Illuminate\Support\Facades\Route;

// Emisión de certificados de una capacitación (M-CAP RN-CAP-03, 07). El resto: /records/CAP/...
Route::middleware(['auth:sanctum', 'company', 'module:CAP', 'permission:cap.trainings.read'])->prefix('cap')->group(function () {
    Route::get('matrix', \App\Modules\CAP\MatrixController::class);
    Route::get('compliance', [\App\Modules\CAP\MatrixController::class, 'compliance']);
    Route::post('sessions/{id}/invite', [\App\Modules\CAP\MatrixController::class, 'invite'])->whereUuid('id');
    Route::post('certificates/validate', [CertificatesController::class, 'validateBatch']);
    Route::post('trainings/{training}/certificates', [CertificatesController::class, 'issue'])->whereUuid('training');
    Route::post('certificates/{id}/reissue', [CertificatesController::class, 'reissue'])->whereUuid('id');
});

// Impresión (con sesión) y verificación pública (sin sesión, con límite) de certificados y fotochecks.
Route::middleware(['auth:sanctum', 'company', 'module:CAP'])->get('cap/certificates/{id}/print', [\App\Modules\CAP\PrintController::class, 'certificate'])->whereUuid('id');
Route::middleware(['auth:sanctum', 'company', 'module:FOT'])->get('fot/credentials/{id}/print', [\App\Modules\CAP\PrintController::class, 'credential'])->whereUuid('id');
Route::middleware('throttle:30,1')->prefix('public/verify')->group(function () {
    Route::get('certificate/{code}', [\App\Modules\CAP\PrintController::class, 'verifyCertificate'])->where('code', '[A-Za-z0-9-]{4,40}');
    Route::get('credential/{token}', [\App\Modules\CAP\PrintController::class, 'verifyCredential'])->where('token', '[A-Za-z0-9]{8,64}');
});

// Programa de capacitación (M-PCA SCR-PCA-01/03): vista anual temas × meses y matriz puesto × tema.
Route::middleware(['auth:sanctum', 'company', 'module:PCA'])->get('pca/overview', \App\Modules\CAP\ProgramOverviewController::class);
