<?php

use App\Modules\USR\Http\Controllers\AccountSecurityController;
use App\Modules\USR\Http\Controllers\AuthController;
use App\Modules\USR\Http\Controllers\CompanyUsersController;
use App\Modules\USR\Http\Controllers\CustomRolesController;
use App\Modules\USR\Http\Controllers\InvitationController;
use App\Modules\USR\Http\Controllers\MeController;
use App\Modules\USR\Http\Controllers\MfaController;
use App\Modules\USR\Http\Controllers\RolesMatrixController;
use App\Modules\USR\Http\Controllers\SearchController;
use App\Modules\USR\Http\Controllers\SupportAccessController;
use Illuminate\Support\Facades\Route;

// Sin sesión (o con desafío MFA)
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::middleware('throttle:login')->group(function () {
    Route::get('auth/invitations/{token}', [InvitationController::class, 'show']);
    Route::post('auth/invitations/{token}/accept', [InvitationController::class, 'accept']);
});
// Olvidé mi contraseña, invitación al equipo y confirmación de correo (enlaces con token, sin sesión)
Route::middleware('throttle:login')->group(function () {
    Route::post('auth/password/forgot', [AccountSecurityController::class, 'forgot']);
    Route::get('auth/links/{token}', [AccountSecurityController::class, 'link'])->where('token', '[A-Za-z0-9]{48}');
    Route::post('auth/links/{token}/password', [AccountSecurityController::class, 'setPassword'])->where('token', '[A-Za-z0-9]{48}');
    Route::post('auth/email/confirm/{token}', [AccountSecurityController::class, 'confirmEmail'])->where('token', '[A-Za-z0-9]{48}');
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
    Route::patch('me/profile', [AccountSecurityController::class, 'profile']);
    Route::post('me/password', [AccountSecurityController::class, 'password'])->middleware('throttle:login');
    Route::post('me/email', [AccountSecurityController::class, 'email'])->middleware('throttle:login');
    Route::get('me/companies', [MeController::class, 'companies']);
    Route::post('me/switch-company', [MeController::class, 'switchCompany']);
});

// Con sesión y empresa activa (X-Company-Id)
Route::middleware(['auth:sanctum', 'company'])->group(function () {
    Route::get('me/permissions', [MeController::class, 'permissions']);
    Route::get('me/modules', [MeController::class, 'modules']);
    Route::get('search', [SearchController::class, 'index'])->middleware('throttle:120,1');
});

// Usuarios de la empresa (invitar, cambiar rol, suspender)
Route::middleware(['auth:sanctum', 'company', 'module:USR'])->prefix('company')->group(function () {
    Route::get('users', [CompanyUsersController::class, 'index']);
    Route::get('roles', [CompanyUsersController::class, 'roles']);
    Route::get('roles/matrix', [RolesMatrixController::class, 'matrix']);
    // Roles personalizados de la cuenta (derivan de un rol base, sin exceder sus permisos)
    Route::get('roles/manage', [CustomRolesController::class, 'index']);
    Route::post('roles', [CustomRolesController::class, 'store']);
    Route::patch('roles/{role}', [CustomRolesController::class, 'update'])->whereUuid('role');
    Route::delete('roles/{role}', [CustomRolesController::class, 'destroy'])->whereUuid('role');
    Route::get('scope-options', [RolesMatrixController::class, 'scopeOptions']);
    Route::post('users', [CompanyUsersController::class, 'store'])->middleware('throttle:login');
    Route::patch('users/{membership}', [CompanyUsersController::class, 'update'])->whereUuid('membership');
    Route::post('users/{membership}/resend', [CompanyUsersController::class, 'resend'])->whereUuid('membership')->middleware('throttle:login');
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

// Equipo de la plataforma (docs/05 §4): solo el Dueño invita, cambia roles y desactiva.
Route::middleware(['auth:sanctum', 'platform', 'platform.owner'])->prefix('internal')->group(function () {
    Route::get('team', [AccountSecurityController::class, 'team']);
    Route::post('team', [AccountSecurityController::class, 'invite']);
    Route::patch('team/{id}', [AccountSecurityController::class, 'update'])->whereUuid('id');
    Route::post('team/{id}/{action}', [AccountSecurityController::class, 'action'])->whereUuid('id')->whereIn('action', ['resend', 'disable', 'enable']);
});
