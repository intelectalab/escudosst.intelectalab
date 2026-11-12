<?php

use App\Modules\ACT\ReportPointController;
use Illuminate\Support\Facades\Route;

// Reportes por QR sin app (M-ACT): enlace del punto (con sesión) y formulario público (sin sesión, con límite de uso).
Route::middleware(['auth:sanctum', 'company', 'module:ACT'])->get('act/report-points/{id}/link', [ReportPointController::class, 'link'])->whereUuid('id');
Route::middleware('throttle:10,1')->prefix('public/report/{company}/{token}')->group(function () {
    Route::get('/', [ReportPointController::class, 'show']);
    Route::post('/', [ReportPointController::class, 'store']);
});

// Conversación de reportes por WhatsApp (SCR-ACT-03): Meta llama a esta dirección.
Route::middleware('throttle:120,1')->get('webhooks/whatsapp', [\App\Modules\ACT\WhatsAppWebhookController::class, 'verify']);
Route::middleware('throttle:600,1')->post('webhooks/whatsapp', [\App\Modules\ACT\WhatsAppWebhookController::class, 'receive']);
