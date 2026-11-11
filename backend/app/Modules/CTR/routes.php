<?php

use App\Modules\CTR\InvitationController;
use App\Modules\CTR\PortalController;
use App\Modules\CTR\ReviewController;
use Illuminate\Support\Facades\Route;

// Contratistas (M-CTR): invitar al portal, bandeja de revisión y portal del contratista. El resto: /records/CTR/...
Route::middleware(['auth:sanctum', 'company', 'module:CTR'])->prefix('ctr')->group(function () {
    Route::get('invitations', [InvitationController::class, 'index']);
    Route::post('invitations', [InvitationController::class, 'store'])->middleware('throttle:login');
    Route::post('invitations/{contractor}/resend', [InvitationController::class, 'resend'])->whereUuid('contractor')->middleware('throttle:login');

    Route::get('review', [ReviewController::class, 'index']);
    Route::post('review/{document}', [ReviewController::class, 'review'])->whereUuid('document');

    Route::get('portal', [PortalController::class, 'show']);
    Route::patch('portal/company', [PortalController::class, 'updateCompany']);
    Route::post('portal/documents', [PortalController::class, 'storeDocument']);
    Route::post('portal/workers', [PortalController::class, 'storeWorker']);
    Route::post('portal/accreditations', [PortalController::class, 'storeAccreditation']);
});
