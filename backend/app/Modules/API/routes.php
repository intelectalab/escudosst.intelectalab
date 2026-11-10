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
