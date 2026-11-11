<?php

use App\Modules\TEN\OnboardingController;
use Illuminate\Support\Facades\Route;

// Primeros pasos de la empresa y sus datos generales (M-TEN). Sedes, áreas, puestos y turnos: /records/TEN/...
Route::middleware(['auth:sanctum', 'company'])->prefix('ten')->group(function () {
    Route::get('onboarding', [OnboardingController::class, 'show'])->middleware('permission:ten.companies.read');
    Route::middleware('permission:ten.companies.update')->group(function () {
        Route::patch('company', [OnboardingController::class, 'update']);
        Route::post('onboarding/dismiss', [OnboardingController::class, 'dismiss']);
    });
});
