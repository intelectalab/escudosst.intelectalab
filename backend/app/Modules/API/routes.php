<?php

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Modules\REC\Rules\ApiRules;
use Illuminate\Support\Facades\Route;

// API e integraciones (M-API): leer una sola vez el secreto de un cliente recién creado.
Route::middleware(['auth:sanctum', 'company', 'module:API'])->prefix('api-clients')->group(function () {
    Route::post('{id}/secret', function (string $id) {
        if (! in_array('api.api_clients.create', Permissions::effective(), true)) {
            throw ApiProblem::forbidden();
        }
        $secret = ApiRules::pullSecret($id);
        if (! $secret) {
            throw new ApiProblem(410, 'secret_gone', 'Secreto no disponible', 'El secreto solo se muestra una vez, durante 10 minutos después de crear el cliente. Si lo perdiste, revoca el cliente y crea otro.');
        }

        return response()->json(['data' => ['secret' => $secret]]);
    })->whereUuid('id');
});

// Webhooks: ver el secreto de firma (para validar la firma en el sistema que recibe).
Route::middleware(['auth:sanctum', 'company', 'module:API'])->post('webhooks/{id}/secret', function (string $id) {
    if (! in_array('api.webhooks.update', Permissions::effective(), true)) {
        throw ApiProblem::forbidden();
    }
    $w = \Illuminate\Support\Str::isUuid($id) ? \Illuminate\Support\Facades\DB::selectOne('select secret from webhooks where id = ? and deleted_at is null', [$id]) : null;
    if (! $w) {
        throw ApiProblem::notFound('El webhook no existe o es de otra empresa.');
    }

    return response()->json(['data' => ['secret' => \App\Modules\API\Webhooks::secret($w->secret)]]);
})->whereUuid('id');

// API de solo lectura para integraciones (con clave, secreto y el ID de la empresa).
Route::get('ext/v1/{resource}', [\App\Modules\API\ExternalApiController::class, 'index'])->where('resource', '[a-z_]{3,30}');
