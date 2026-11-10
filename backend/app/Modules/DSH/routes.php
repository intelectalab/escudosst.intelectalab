<?php

use App\Modules\DSH\Http\DashboardController;
use Illuminate\Support\Facades\Route;

// Tablero e indicadores (M-DSH), con empresa activa.
Route::middleware(['auth:sanctum', 'company', 'module:DSH', 'permission:dsh.dashboards.read'])->prefix('dsh')->group(function () {
    Route::get('overview', [DashboardController::class, 'overview']);
    Route::get('group', [DashboardController::class, 'group']);
});
