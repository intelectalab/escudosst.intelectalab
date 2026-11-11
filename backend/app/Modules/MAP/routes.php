<?php

use App\Modules\MAP\LegendController;
use Illuminate\Support\Facades\Route;

// Mapa de riesgos (M-MAP): leyenda con vínculos al IPERC para la vista principal y el afiche. Plano y marcadores: /records/MAP/...
Route::middleware(['auth:sanctum', 'company', 'module:MAP', 'permission:map.risk_maps.read'])->prefix('map')->group(function () {
    Route::get('maps/{id}/legend', LegendController::class)->whereUuid('id');
});
