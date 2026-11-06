<?php

// Avisos del panel de la plataforma: se calculan desde lo pendiente; solo el equipo de la plataforma los ve.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

it('cuenta los testimonios por aprobar y lleva a su pantalla', function () {
    $before = collect($this->getJson('/api/v1/internal/notifications', authHeaders(tokenFor(ADMIN_EMAIL)))->assertOk()->json('data'))->firstWhere('key', 'testimonials_pending')['count'] ?? 0;
    DB::connection('platform')->table('site_testimonials')->insert(['author_name' => 'Prueba', 'quote' => 'Un testimonio de prueba para avisos.', 'status' => 'pending']);

    $item = collect($this->getJson('/api/v1/internal/notifications', authHeaders(tokenFor(ADMIN_EMAIL)))->json('data'))->firstWhere('key', 'testimonials_pending');
    expect($item['count'])->toBe($before + 1)->and($item['href'])->toBe('/plataforma/sitio/contenido');
});

it('no la ve un usuario de empresa', function () {
    $this->getJson('/api/v1/internal/notifications', authHeaders(tokenFor(ESENCIAL_EMAIL)))->assertForbidden();
});

it('sin sesión responde 401 aunque no se pida JSON', function () {
    $this->get('/api/v1/internal/notifications')->assertUnauthorized()->assertJsonPath('type', 'unauthenticated');
});
