<?php

use App\Modules\ISO\IsoPseController;
use Illuminate\Support\Facades\Route;

// Sistema integrado ISO y paquetes sectoriales (complementos).
Route::middleware(['auth:sanctum', 'company', 'module:ISO'])->post('iso/assess', [IsoPseController::class, 'assess']);
// Paquetes sectoriales: backend/app/Modules/PSE/routes.php
