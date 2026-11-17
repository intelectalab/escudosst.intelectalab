<?php

use App\Modules\PET\PetStepsController;
use Illuminate\Support\Facades\Route;

// Editor de PETS (M-PET SCR-PET-02): tabla paso–riesgo–control, capacitaciones requeridas y filas del IPERC.
Route::middleware(['auth:sanctum', 'company', 'module:PET'])->prefix('pet')->group(function () {
    Route::get('versions/{id}/steps', [PetStepsController::class, 'show'])->whereUuid('id');
    Route::put('versions/{id}/steps', [PetStepsController::class, 'update'])->whereUuid('id');
    Route::get('versions/{id}/report', [PetStepsController::class, 'report'])->whereUuid('id');
    Route::get('procedures/{id}/current', [PetStepsController::class, 'current'])->whereUuid('id');
});
