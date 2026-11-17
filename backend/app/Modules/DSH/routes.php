<?php

use App\Modules\DSH\Http\DashboardController;
use Illuminate\Support\Facades\Route;

// Tablero e indicadores (M-DSH), con empresa activa.
Route::middleware(['auth:sanctum', 'company', 'module:DSH', 'permission:dsh.dashboards.read'])->prefix('dsh')->group(function () {
    Route::get('overview', [DashboardController::class, 'overview']);
    Route::get('history', [DashboardController::class, 'history']);
    Route::get('boards', [DashboardController::class, 'boards']);
    Route::post('boards', [DashboardController::class, 'saveBoard']);
    Route::delete('boards/{id}', [DashboardController::class, 'deleteBoard'])->whereUuid('id');
    Route::get('history.xlsx', [DashboardController::class, 'history']);
    Route::get('group', [DashboardController::class, 'group']);
    Route::get('home', \App\Modules\DSH\Http\HomeController::class);
});
