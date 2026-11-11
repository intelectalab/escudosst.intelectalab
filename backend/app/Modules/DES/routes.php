<?php

use App\Modules\DES\DesController;
use Illuminate\Support\Facades\Route;

// Descansos médicos y ausentismo (M-DES SCR-DES-01/03/04): indicadores de ausentismo (base principal) y el detalle
// médico (CIE-10, certificado) en la base médica solo para el personal de salud con clave médica. El resto: /records/DES/...
Route::middleware(['auth:sanctum', 'company', 'module:DES'])->prefix('des')->group(function () {
    Route::get('indicators', [DesController::class, 'indicators']);
    Route::get('absences', [DesController::class, 'absences']);
    Route::post('reauth', [DesController::class, 'reauth'])->middleware('throttle:10,1');
    Route::get('absences/{id}/medical', [DesController::class, 'showMedical'])->whereUuid('id');
    Route::put('absences/{id}/medical', [DesController::class, 'saveMedical'])->whereUuid('id');
    Route::post('absences/{id}/certificate', [DesController::class, 'uploadCertificate'])->whereUuid('id');
    Route::get('absences/{id}/certificate', [DesController::class, 'downloadCertificate'])->whereUuid('id');
});
