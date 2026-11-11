<?php

use App\Modules\SUS\Http\Controllers\AccountsController;
use App\Modules\SUS\Http\Controllers\BackupsController;
use App\Modules\SUS\Http\Controllers\BillingController;
use App\Modules\SUS\Http\Controllers\CatalogsController;
use App\Modules\SUS\Http\Controllers\HealthController;
use App\Modules\SUS\Http\Controllers\MetricsController;
use App\Modules\SUS\Http\Controllers\MySubscriptionController;
use App\Modules\SUS\Http\Controllers\PlanCatalogController;
use App\Modules\SUS\Http\Controllers\PlanModulesController;
use App\Modules\SUS\Http\Controllers\PlatformNotificationsController;
use App\Modules\SUS\Http\Controllers\ProvidersController;
use App\Modules\SUS\Http\Controllers\SpecialistCatalogsController;
use App\Modules\SUS\Http\Controllers\SubscriptionsController;
use App\Modules\SUS\Http\Controllers\SupportTicketsController;
use Illuminate\Support\Facades\Route;

// Panel interno del dueño de la plataforma (docs/08-api.md §16). Sin X-Company-Id.
Route::middleware(['auth:sanctum', 'platform'])->prefix('internal')->group(function () {
    // Avisos de la campana del panel
    Route::get('notifications', [PlatformNotificationsController::class, 'index']);
    // Consultas de las empresas al equipo
    Route::get('support-tickets', [SupportTicketsController::class, 'index']);
    Route::post('support-tickets/{id}/reply', [SupportTicketsController::class, 'reply'])->whereUuid('id');
    Route::post('support-tickets/{id}/close', [SupportTicketsController::class, 'close'])->whereUuid('id');
    // Proveedores y claves (IA, pagos, facturación electrónica, WhatsApp, firma digital): solo el dueño cambia claves.
    Route::get('providers', [ProvidersController::class, 'index']);
    Route::put('providers/{code}', [ProvidersController::class, 'update'])->whereIn('code', ['payments', 'einvoice', 'whatsapp', 'esign', 'backups'])->middleware('platform.owner');
    Route::post('providers/{code}/test', [ProvidersController::class, 'test'])->whereIn('code', ['payments', 'einvoice', 'whatsapp', 'esign', 'backups'])->middleware(['platform.owner', 'throttle:10,1']);
    // SCR-SUS-01 · Planes y módulos
    Route::get('plans', [PlanModulesController::class, 'index']);
    Route::patch('plans/{plan}', [PlanCatalogController::class, 'updatePlan'])->where('plan', '[A-Za-z]');
    Route::put('plans/{plan}/modules/{module}', [PlanModulesController::class, 'update'])
        ->where(['plan' => '[A-Za-z]', 'module' => '[A-Za-z]{3}']);
    Route::patch('addons/{addon}', [PlanCatalogController::class, 'updateAddon'])->where('addon', '[A-Za-z]{3}');

    // SCR-SUS-02 / SCR-SUS-03 · Cuentas y suscripciones
    Route::get('accounts', [AccountsController::class, 'index']);
    Route::post('accounts', [AccountsController::class, 'store']);
    Route::get('accounts/{account}', [AccountsController::class, 'show']);
    Route::post('accounts/{account}/companies/{company}/admins/{user}/resend', [AccountsController::class, 'resendInvitation']);
    Route::get('billing', [BillingController::class, 'index']);
    Route::get('metrics', [MetricsController::class, 'index']);

    // SCR-SUS-08 · Catálogos globales
    Route::prefix('catalogs')->group(function () {
        Route::get('/', [CatalogsController::class, 'summary']);
        Route::get('legal-parameters', [CatalogsController::class, 'legalParameters']);
        Route::get('legal-norms', [CatalogsController::class, 'legalNorms']);
        // Contenido que valida un especialista SST/legal: lo lee el equipo, lo cambia solo el Dueño.
        Route::get('legal-parameters/{id}/companies', [SpecialistCatalogsController::class, 'overrides'])->whereUuid('id');
        Route::get('legal-norms/{id}/requirements', [SpecialistCatalogsController::class, 'requirements'])->whereUuid('id');
        Route::get('scat', [SpecialistCatalogsController::class, 'scat']);
        Route::middleware('platform.owner')->group(function () {
            Route::post('legal-parameters/{id}/versions', [CatalogsController::class, 'newVersion'])->whereUuid('id');
            Route::post('legal-parameters/{id}/confirm', [CatalogsController::class, 'confirm'])->whereUuid('id');
            Route::put('legal-parameters/{id}/companies/{company}', [SpecialistCatalogsController::class, 'setOverride'])->whereUuid(['id', 'company']);
            Route::delete('legal-parameters/{id}/companies/{company}', [SpecialistCatalogsController::class, 'removeOverride'])->whereUuid(['id', 'company']);
            Route::post('legal-norms', [CatalogsController::class, 'saveNorm']);
            Route::patch('legal-norms/{id}', [CatalogsController::class, 'saveNorm'])->whereUuid('id');
            Route::post('legal-norms/{id}/requirements', [SpecialistCatalogsController::class, 'createRequirement'])->whereUuid('id');
            Route::patch('norm-requirements/{id}', [SpecialistCatalogsController::class, 'updateRequirement'])->whereUuid('id');
            Route::post('norm-requirements/{id}/confirm', [SpecialistCatalogsController::class, 'confirmRequirement'])->whereUuid('id');
            Route::post('norm-requirements/{id}/archive', [SpecialistCatalogsController::class, 'archiveRequirement'])->whereUuid('id');
            Route::post('norm-requirements/{id}/restore', [SpecialistCatalogsController::class, 'restoreRequirement'])->whereUuid('id');
            Route::post('scat', [SpecialistCatalogsController::class, 'createScat']);
            Route::post('scat/reorder', [SpecialistCatalogsController::class, 'reorderScat']);
            Route::patch('scat/{id}', [SpecialistCatalogsController::class, 'updateScat'])->whereUuid('id');
            Route::post('scat/{id}/confirm', [SpecialistCatalogsController::class, 'confirmScat'])->whereUuid('id');
        });
        Route::get('hazards', [CatalogsController::class, 'hazards']);
        Route::post('hazards', [CatalogsController::class, 'saveHazard']);
        Route::patch('hazards/{id}', [CatalogsController::class, 'saveHazard'])->whereUuid('id');
        Route::get('sector-packs', [CatalogsController::class, 'sectorPacks']);
        Route::patch('sector-packs/{id}', [CatalogsController::class, 'packStatus'])->whereUuid('id');
        Route::get('holidays', [CatalogsController::class, 'holidays']);
        Route::post('holidays', [CatalogsController::class, 'createHoliday']);
        Route::patch('holidays/{id}', [CatalogsController::class, 'updateHoliday'])->whereUuid('id');
        Route::delete('holidays/{id}', [CatalogsController::class, 'deleteHoliday'])->whereUuid('id');
    });
    Route::get('health', [HealthController::class, 'index']);
    Route::post('health/run-daily', [HealthController::class, 'runDaily']);
    Route::get('backups', [BackupsController::class, 'index']);
    Route::post('backups', [BackupsController::class, 'store'])->middleware(['platform.owner', 'throttle:sus-backups']);
    Route::post('subscriptions/{subscription}/change-plan', [SubscriptionsController::class, 'changePlan']);
    Route::post('subscriptions/{subscription}/suspend', [SubscriptionsController::class, 'suspend']);
    Route::post('subscriptions/{subscription}/reactivate', [SubscriptionsController::class, 'reactivate']);
    Route::post('subscriptions/{subscription}/addons', [SubscriptionsController::class, 'addAddon']);
    Route::post('subscriptions/{subscription}/payments', [SubscriptionsController::class, 'registerPayment']);
    Route::delete('subscriptions/{subscription}/addons/{addon}', [SubscriptionsController::class, 'removeAddon'])
        ->where('addon', '[A-Za-z]{3}');
});

// Lado del cliente (con empresa activa): estado de su suscripción y avisos en el sistema.
Route::middleware(['auth:sanctum', 'company'])->group(function () {
    Route::get('me/subscription', [MySubscriptionController::class, 'show']);
    Route::get('me/notifications', [MySubscriptionController::class, 'notifications']);
    Route::post('me/notifications/read-all', [MySubscriptionController::class, 'readAll']);
    Route::post('me/notifications/{notification}/read', [MySubscriptionController::class, 'read'])->whereUuid('notification');
});

// Estado de los proveedores para la app de la empresa (avisos de «modo de prueba»).
Route::middleware(['auth:sanctum'])->get('providers/status', [ProvidersController::class, 'status']);

// Consultas al equipo de EscudoSST desde la aplicación de empresa (Ayuda › Escribir al equipo).
Route::middleware(['auth:sanctum', 'company'])->prefix('me')->group(function () {
    Route::get('support-tickets', [SupportTicketsController::class, 'mine']);
    Route::post('support-tickets', [SupportTicketsController::class, 'store'])->middleware('throttle:10,1');
});
