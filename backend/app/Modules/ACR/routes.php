<?php

use App\Modules\ACR\ActionFromRecord;
use Illuminate\Support\Facades\Route;

// Acción correctiva desde un registro de origen (hallazgo, causa, acuerdo, brecha, reporte, requisito legal).
Route::middleware(['auth:sanctum', 'company', 'module:ACR'])->prefix('acr')->group(function () {
    Route::post('from/{table}/{id}', ActionFromRecord::class)->where('table', '[a-z_]+')->whereUuid('id');
});
