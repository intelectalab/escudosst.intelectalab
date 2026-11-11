<?php

use App\Modules\API\ApiCredentialsController;
use App\Modules\API\ExternalApiController;
use Illuminate\Support\Facades\Route;

// API e integraciones (M-API RN-API-03): los secretos se muestran una sola vez (al crear o al rotar).
Route::middleware(['auth:sanctum', 'company', 'module:API'])->group(function () {
    Route::post('api-clients/{id}/rotate-secret', [ApiCredentialsController::class, 'rotateClient'])->whereUuid('id');
    Route::post('webhooks/{id}/rotate-secret', [ApiCredentialsController::class, 'rotateWebhook'])->whereUuid('id');
    Route::post('webhooks/{id}/test', [ApiCredentialsController::class, 'testWebhook'])->whereUuid('id')->middleware('throttle:10,1');
});

// API de solo lectura para integraciones (con clave, secreto y el ID de la empresa).
Route::get('ext/v1/{resource}', [ExternalApiController::class, 'index'])->where('resource', '[a-z_]{3,30}');
