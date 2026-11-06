<?php

use App\Modules\USR\Http\Controllers\AuthController;
use App\Modules\USR\Http\Controllers\InvitationController;
use App\Modules\USR\Http\Controllers\MeController;
use App\Modules\USR\Http\Controllers\MfaController;
use App\Modules\USR\Http\Controllers\SupportAccessController;
use Illuminate\Support\Facades\Route;

// Sin sesión (o con desafío MFA)
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::middleware('throttle:login')->group(function () {
    Route::get('auth/invitations/{token}', [InvitationController::class, 'show']);
    Route::post('auth/invitations/{token}/accept', [InvitationController::class, 'accept']);
});
Route::middleware('throttle:mfa')->group(function () {
    Route::post('auth/mfa/setup', [MfaController::class, 'setup']);
    Route::post('auth/mfa/confirm', [MfaController::class, 'confirm']);
    Route::post('auth/mfa/verify', [MfaController::class, 'verify']);
    Route::post('auth/mfa/email', [MfaController::class, 'sendEmail']);
});

// Con sesión, sin empresa activa
Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/mfa', [MfaController::class, 'status']);
    Route::post('auth/mfa/recovery-codes', [MfaController::class, 'regenerateRecoveryCodes'])->middleware('throttle:mfa');
    Route::post('auth/mfa/disable', [MfaController::class, 'disable'])->middleware('throttle:mfa');
    Route::get('me', [MeController::class, 'show']);
    Route::get('me/companies', [MeController::class, 'companies']);
    Route::post('me/switch-company', [MeController::class, 'switchCompany']);
});

// Con sesión y empresa activa (X-Company-Id)
Route::middleware(['auth:sanctum', 'company'])->group(function () {
    Route::get('me/permissions', [MeController::class, 'permissions']);
    Route::get('me/modules', [MeController::class, 'modules']);
});

// Accesos de soporte (docs/05 §4.6): lado de la plataforma
Route::middleware(['auth:sanctum', 'platform'])->prefix('internal')->group(function () {
    Route::get('support-access', [SupportAccessController::class, 'index']);
    Route::post('support-access', [SupportAccessController::class, 'store']);
    Route::post('support-access/{grant}/cancel', [SupportAccessController::class, 'cancel'])->whereUuid('grant');
    Route::post('support-access/{grant}/revoke', [SupportAccessController::class, 'revoke'])->whereUuid('grant');
});

// Accesos de soporte: lado de la empresa (administrador de cuenta)
Route::middleware(['auth:sanctum', 'company'])->prefix('account')->group(function () {
    Route::get('support-access', [SupportAccessController::class, 'companyIndex']);
    Route::post('support-access/{grant}/approve', [SupportAccessController::class, 'approve'])->whereUuid('grant');
    Route::post('support-access/{grant}/reject', [SupportAccessController::class, 'reject'])->whereUuid('grant');
    Route::post('support-access/{grant}/revoke', [SupportAccessController::class, 'companyRevoke'])->whereUuid('grant');
});
