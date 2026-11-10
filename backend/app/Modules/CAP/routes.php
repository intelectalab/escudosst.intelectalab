<?php

use App\Modules\CAP\CertificatesController;
use Illuminate\Support\Facades\Route;

// Emisión de certificados de una capacitación (M-CAP RN-CAP-03, 07). El resto: /records/CAP/...
Route::middleware(['auth:sanctum', 'company', 'module:CAP', 'permission:cap.trainings.read'])->prefix('cap')->group(function () {
    Route::post('trainings/{training}/certificates', [CertificatesController::class, 'issue'])->whereUuid('training');
});
