<?php

use App\Modules\IAA\AssistantController;
use Illuminate\Support\Facades\Route;

// Asistente IA (complemento): con proveedor configurado usa IA; si no, la biblioteca de EscudoSST (modo de prueba).
Route::middleware(['auth:sanctum', 'company', 'module:IAA', 'throttle:20,1'])->post('iaa/suggest', [AssistantController::class, 'suggest']);
