<?php

use App\Modules\REC\RecordsController;
use Illuminate\Support\Facades\Route;

// Registros de los módulos SST (motor común, con empresa activa).
Route::middleware(['auth:sanctum', 'company'])->prefix('records')->group(function () {
    $m = '[A-Za-z0-9]{3}';
    $t = '[a-z0-9_]+';
    Route::get('{module}', [RecordsController::class, 'schema'])->where('module', $m);
    Route::get('{module}/{table}', [RecordsController::class, 'index'])->where(['module' => $m, 'table' => $t]);
    Route::post('{module}/{table}', [RecordsController::class, 'store'])->where(['module' => $m, 'table' => $t]);
    Route::get('{module}/{table}/options/{field}', [RecordsController::class, 'options'])->where(['module' => $m, 'table' => $t, 'field' => $t]);
    Route::get('{module}/{table}/{id}/trace', [RecordsController::class, 'trace'])->where(['module' => $m, 'table' => $t])->whereUuid('id');
    Route::get('{module}/{table}/{id}/history', [RecordsController::class, 'history'])->where(['module' => $m, 'table' => $t])->whereUuid('id');
    Route::get('{module}/{table}/{id}', [RecordsController::class, 'show'])->where(['module' => $m, 'table' => $t])->whereUuid('id');
    Route::patch('{module}/{table}/{id}', [RecordsController::class, 'update'])->where(['module' => $m, 'table' => $t])->whereUuid('id');
    Route::delete('{module}/{table}/{id}', [RecordsController::class, 'destroy'])->where(['module' => $m, 'table' => $t])->whereUuid('id');
});
