<?php

use App\Modules\WEB\Http\Controllers\ComplaintsController;
use App\Modules\WEB\Http\Controllers\CookieConsentController;
use App\Modules\WEB\Http\Controllers\DemoRequestsController;
use App\Modules\WEB\Http\Controllers\LeadsController;
use App\Modules\WEB\Http\Controllers\LegalAcceptanceController;
use App\Modules\WEB\Http\Controllers\LegalDocumentsController;
use App\Modules\WEB\Http\Controllers\PublicFormsController;
use App\Modules\WEB\Http\Controllers\PublicSiteController;
use App\Modules\WEB\Http\Controllers\SiteAssetsController;
use App\Modules\WEB\Http\Controllers\SiteContentController;
use App\Modules\WEB\Http\Controllers\SiteMenuController;
use App\Modules\WEB\Http\Controllers\SitePagesController;
use App\Modules\WEB\Http\Controllers\SiteSettingsController;
use App\Modules\WEB\Http\Controllers\SuccessStoriesController;
use App\Modules\WEB\Http\Controllers\SuccessStoryInvitesController;
use Illuminate\Support\Facades\Route;

// Sitio web público (sin sesión). Límite de uso por IP.
Route::middleware('throttle:120,1')->prefix('public')->group(function () {
    Route::get('site', [PublicSiteController::class, 'site']);
    Route::get('pricing', [PublicSiteController::class, 'pricing']);
    Route::get('modules', [PublicSiteController::class, 'modules']);
    Route::get('legal/{slug}', [LegalDocumentsController::class, 'publicPage'])->where('slug', '[a-z0-9-]+');
    Route::get('complaints/info', [ComplaintsController::class, 'info']);
    Route::get('site/assets/{id}', [SiteAssetsController::class, 'show'])->whereUuid('id');
    Route::get('faqs', [SiteContentController::class, 'publicFaqs']);
    Route::get('pages', [SitePagesController::class, 'publicIndex']);
    Route::get('pages/{slug}', [SitePagesController::class, 'publicShow'])->where('slug', '[a-z0-9/-]+');
    Route::get('testimonials', [SiteContentController::class, 'publicTestimonials']);
    Route::get('success-stories', [SuccessStoriesController::class, 'publicIndex']);
    Route::get('success-stories/{slug}', [SuccessStoriesController::class, 'publicShow'])->where('slug', '[a-z0-9-]+');
    Route::post('cookie-consent', [CookieConsentController::class, 'store']);
});

// Formularios públicos: límite más estricto por IP (captcha por definir, DA-13).
Route::middleware('throttle:6,10')->prefix('public')->group(function () {
    Route::post('trial', [PublicFormsController::class, 'trial']);
    Route::post('demo-requests', [PublicFormsController::class, 'demoRequest']);
    Route::post('complaints', [ComplaintsController::class, 'store']);
});

// Seguimiento de una hoja: límite propio para que no se puedan probar claves al azar.
Route::middleware('throttle:10,10')->prefix('public')->group(function () {
    Route::post('complaints/status', [ComplaintsController::class, 'status']);
});

// Casos de éxito por invitación: el cliente abre su enlace personal (token), cuenta su historia y firma;
// y puede retirar su autorización con el enlace de su correo.
Route::middleware('throttle:30,10')->prefix('public/success-stories')->group(function () {
    Route::get('share/{token}', [SuccessStoryInvitesController::class, 'showShare'])->where('token', '[A-Za-z0-9]{48}');
    Route::get('withdraw/{token}', [SuccessStoryInvitesController::class, 'showWithdraw'])->where('token', '[A-Za-z0-9]{48}');
});
Route::middleware('throttle:6,10')->prefix('public/success-stories')->group(function () {
    Route::post('share/{token}', [SuccessStoryInvitesController::class, 'submitShare'])->where('token', '[A-Za-z0-9]{48}');
    Route::post('withdraw/{token}', [SuccessStoryInvitesController::class, 'withdraw'])->where('token', '[A-Za-z0-9]{48}');
});

// Panel del dueño · Sitio web (SCR-WEB-21 y SCR-WEB-24)
Route::middleware(['auth:sanctum', 'platform'])->prefix('internal/site')->group(function () {
    Route::get('settings', [SiteSettingsController::class, 'show']);
    Route::patch('settings', [SiteSettingsController::class, 'update']);
    Route::get('legal', [LegalDocumentsController::class, 'index']);
    Route::patch('legal/versions/{version}', [LegalDocumentsController::class, 'saveDraft'])->whereUuid('version');
    Route::post('legal/versions/{version}/publish', [LegalDocumentsController::class, 'publish'])->whereUuid('version')->middleware('platform.owner');
    Route::post('legal/{document}/versions', [LegalDocumentsController::class, 'newVersion'])->whereUuid('document');
    Route::get('requests', [DemoRequestsController::class, 'index']);
    Route::patch('requests/{id}', [DemoRequestsController::class, 'update'])->whereUuid('id');
    Route::post('assets/{kind}', [SiteAssetsController::class, 'store'])->whereIn('kind', ['logo', 'logo_dark', 'favicon']);
    Route::delete('assets/{kind}', [SiteAssetsController::class, 'destroy'])->whereIn('kind', ['logo', 'logo_dark', 'favicon']);
    Route::get('menu', [SiteMenuController::class, 'show']);
    Route::put('menu', [SiteMenuController::class, 'update']);
    Route::get('{kind}', [SiteContentController::class, 'index'])->whereIn('kind', ['faqs', 'testimonials', 'products']);
    Route::post('{kind}', [SiteContentController::class, 'store'])->whereIn('kind', ['faqs', 'testimonials', 'products']);
    Route::patch('{kind}/{id}', [SiteContentController::class, 'update'])->whereIn('kind', ['faqs', 'testimonials', 'products'])->whereUuid('id');
    Route::delete('{kind}/{id}', [SiteContentController::class, 'destroy'])->whereIn('kind', ['faqs', 'testimonials', 'products'])->whereUuid('id');
    Route::get('success-stories/authorization-text', [SuccessStoryInvitesController::class, 'template']);
    Route::put('success-stories/authorization-text', [SuccessStoryInvitesController::class, 'saveTemplate'])->middleware('platform.owner');
    Route::get('success-stories/clients', [SuccessStoryInvitesController::class, 'clients']);
    Route::get('success-stories/invites', [SuccessStoryInvitesController::class, 'index']);
    Route::post('success-stories/invites', [SuccessStoryInvitesController::class, 'store']);
    Route::post('success-stories/invites/{id}/resend', [SuccessStoryInvitesController::class, 'resend'])->whereUuid('id');
    Route::delete('success-stories/invites/{id}', [SuccessStoryInvitesController::class, 'destroy'])->whereUuid('id');
    Route::get('success-stories/{id}/consents', [SuccessStoryInvitesController::class, 'consents'])->whereUuid('id');
    Route::get('success-stories/{id}/consents/{consentId}/file', [SuccessStoryInvitesController::class, 'consentFile'])->whereUuid('id')->whereUuid('consentId');
    Route::get('success-stories', [SuccessStoriesController::class, 'index']);
    Route::post('success-stories', [SuccessStoriesController::class, 'store']);
    Route::get('success-stories/{id}', [SuccessStoriesController::class, 'show'])->whereUuid('id');
    Route::patch('success-stories/{id}', [SuccessStoriesController::class, 'update'])->whereUuid('id');
    Route::delete('success-stories/{id}', [SuccessStoriesController::class, 'destroy'])->whereUuid('id');
    Route::post('success-stories/{id}/actions', [SuccessStoriesController::class, 'act'])->whereUuid('id')->middleware('platform.owner');
    Route::post('success-stories/{id}/{kind}', [SuccessStoriesController::class, 'upload'])->whereUuid('id')->whereIn('kind', ['logo', 'consent']);
    Route::delete('success-stories/{id}/logo', [SuccessStoriesController::class, 'removeLogo'])->whereUuid('id');
    Route::get('success-stories/{id}/consent', [SuccessStoriesController::class, 'consent'])->whereUuid('id');
    Route::get('pages', [SitePagesController::class, 'index']);
    Route::post('pages', [SitePagesController::class, 'store']);
    Route::get('pages/{id}', [SitePagesController::class, 'show'])->whereUuid('id');
    Route::patch('pages/{id}', [SitePagesController::class, 'update'])->whereUuid('id');
    Route::post('pages/{id}/publish', [SitePagesController::class, 'publish'])->whereUuid('id')->middleware('platform.owner');
    Route::post('pages/{id}/archive', [SitePagesController::class, 'archive'])->whereUuid('id')->middleware('platform.owner');
    Route::get('leads', [LeadsController::class, 'index']);
    Route::get('leads/export', [LeadsController::class, 'export'])->middleware('platform.owner');
    Route::get('leads/{id}', [LeadsController::class, 'show'])->whereUuid('id');
    Route::patch('leads/{id}', [LeadsController::class, 'update'])->whereUuid('id');
    Route::get('complaints', [ComplaintsController::class, 'index']);
    Route::get('complaints/export', [ComplaintsController::class, 'export'])->middleware('platform.owner');
    Route::get('complaints/{id}', [ComplaintsController::class, 'show'])->whereUuid('id');
    Route::post('complaints/{id}/actions', [ComplaintsController::class, 'act'])->whereUuid('id');
});

// Re-aceptación de políticas: el usuario de la sesión (sin empresa activa).
Route::middleware('auth:sanctum')->group(function () {
    Route::get('me/legal-pending', [LegalAcceptanceController::class, 'pending']);
    Route::post('me/legal-accept', [LegalAcceptanceController::class, 'accept']);
});
