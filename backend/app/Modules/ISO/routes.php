<?php

use App\Modules\ISO\IsoPseController;
use Illuminate\Support\Facades\Route;

// Sistema integrado ISO y paquetes sectoriales (complementos).
Route::middleware(['auth:sanctum', 'company', 'module:ISO'])->post('iso/assess', [IsoPseController::class, 'assess']);
Route::middleware(['auth:sanctum', 'company', 'module:ISO'])->get('iso/matrix', [IsoPseController::class, 'matrix']);
Route::middleware(['auth:sanctum', 'company', 'module:ISO'])->get('iso/matrix.xlsx', [IsoPseController::class, 'matrixXlsx']);
// Paquetes sectoriales: backend/app/Modules/PSE/routes.php
