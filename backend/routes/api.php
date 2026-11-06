<?php

use Illuminate\Support\Facades\Route;

/*
| API REST /api/v1 (docs/08-api.md). Cada módulo define sus rutas en
| app/Modules/<CÓDIGO>/routes.php (docs/01-arquitectura.md §6).
*/

Route::prefix('v1')->group(function () {
    foreach (glob(app_path('Modules/*/routes.php')) as $moduleRoutes) {
        require $moduleRoutes;
    }
});
