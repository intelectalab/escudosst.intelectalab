<?php

use App\Modules\API\ApiCredentialsController;
use App\Modules\API\ExternalApiController;
use Illuminate\Support\Facades\Route;

// API e integraciones (M-API RN-API-03): los secretos se muestran una sola vez (al crear o al rotar).
Route::middleware(['auth:sanctum', 'company', 'module:API'])->group(function () {
    Route::get('api-clients/usage.xlsx', [ApiCredentialsController::class, 'usage']);
    Route::post('api-clients/{id}/rotate-secret', [ApiCredentialsController::class, 'rotateClient'])->whereUuid('id');
    // RN-API-01: aprobación de la lectura de la aptitud (alcance fitness.read) por el administrador de la cuenta o el médico ocupacional.
    Route::post('api-clients/{id}/fitness-approval', [ApiCredentialsController::class, 'approveFitness'])->whereUuid('id');
    Route::delete('api-clients/{id}/fitness-approval', [ApiCredentialsController::class, 'withdrawFitness'])->whereUuid('id');
    Route::post('webhooks/{id}/rotate-secret', [ApiCredentialsController::class, 'rotateWebhook'])->whereUuid('id');
    Route::post('webhooks/{id}/test', [ApiCredentialsController::class, 'testWebhook'])->whereUuid('id')->middleware('throttle:10,1');
});

// API para integraciones (con clave, secreto y el ID de la empresa). Contrato público: api/openapi.yaml (lo verifica la prueba
// OpenApiContractTest: toda ruta ext/ debe estar descrita).
Route::get('ext/v1/{resource}', [ExternalApiController::class, 'index'])->where('resource', '[a-z_]{3,30}');
// Escrituras (alcance workers.write, con Idempotency-Key) y token OAuth2 «client credentials».
Route::post('ext/v1/workers', [ExternalApiController::class, 'storeWorker']);
Route::patch('ext/v1/workers/{id}', [ExternalApiController::class, 'updateWorker']);
Route::middleware('throttle:30,1')->post('ext/oauth/token', [ExternalApiController::class, 'token']);
