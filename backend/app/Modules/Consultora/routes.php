<?php

use App\Modules\Consultora\InvitationsController;
use App\Modules\Consultora\PanelController;
use App\Modules\Consultora\WhiteLabelController;
use Illuminate\Support\Facades\Route;

// Marca blanca de la consultora (M-CON): la configura la consultora; sus empresas cliente la ven.
Route::middleware(['auth:sanctum', 'company', 'module:CON'])->prefix('con')->group(function () {
    Route::get('white-label', [WhiteLabelController::class, 'show']);
    Route::put('white-label', [WhiteLabelController::class, 'save'])->middleware('throttle:20,1');
});
Route::middleware(['auth:sanctum', 'company'])->get('brand', [WhiteLabelController::class, 'brand']);

// Panel multi-cliente e invitaciones (M-CON SCR-CON-01..03). Funciona desde la empresa de la consultora o desde
// cualquiera de sus empresas cliente: el controlador valida que el usuario sea administrador o consultor de la consultora.
Route::middleware(['auth:sanctum', 'company'])->prefix('con')->group(function () {
    Route::get('panel', PanelController::class)->middleware('throttle:30,1');
    Route::get('invitations', [InvitationsController::class, 'index']);
    Route::post('invitations', [InvitationsController::class, 'store'])->middleware('throttle:20,1');
    Route::post('invitations/{id}/revoke', [InvitationsController::class, 'revoke'])->whereUuid('id');
    Route::post('invitations/{token}/accept', [InvitationsController::class, 'accept'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:login');
    Route::post('link/revoke', [InvitationsController::class, 'unlink']);
});
Route::middleware('throttle:login')->get('public/con/invitations/{token}', [InvitationsController::class, 'preview'])->where('token', '[A-Za-z0-9]{48}');
