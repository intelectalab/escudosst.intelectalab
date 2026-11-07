<?php

// Logo, favicon y menú del sitio (SCR-WEB-21); re-aceptación de políticas (SCR-WEB-24); reporte del Libro (SCR-WEB-25).

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Lo de la plataforma se revierte al final (las versiones publicadas no se pueden borrar).
beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Storage::fake('local');
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

it('el dueño sube el logo y el favicon; el sitio los entrega y se pueden quitar', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $this->post('/api/v1/internal/site/assets/logo', ['file' => UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml')], $h + ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors('file');   // SVG no (puede llevar código)
    $this->post('/api/v1/internal/site/assets/favicon', ['file' => UploadedFile::fake()->image('fav.png', 64, 32)], $h + ['Accept' => 'application/json'])
        ->assertStatus(422);                                         // el favicon debe ser cuadrado

    $id = $this->post('/api/v1/internal/site/assets/logo', ['file' => UploadedFile::fake()->image('logo.png', 400, 120)], $h + ['Accept' => 'application/json'])
        ->assertCreated()->json('data.id');
    expect($this->getJson('/api/v1/internal/site/settings', $h)->json('data.logo_file_id'))->toBe($id);
    $this->get("/api/v1/public/site/assets/{$id}")->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');

    $this->deleteJson('/api/v1/internal/site/assets/logo', [], $h)->assertNoContent();
    expect($this->getJson('/api/v1/internal/site/settings', $h)->json('data.logo_file_id'))->toBeNull();
});

it('el dueño edita el menú de la cabecera y el sitio lo usa', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    expect(collect($this->getJson('/api/v1/internal/site/menu', $h)->json('data'))->pluck('label')->all())->toContain('Planes');

    $this->putJson('/api/v1/internal/site/menu', ['items' => [['label' => 'Mal', 'url' => 'javascript:alert(1)']]], $h)->assertStatus(422)->assertJsonValidationErrors('items.0.url');
    $this->putJson('/api/v1/internal/site/menu', ['items' => [
        ['label' => 'Precios', 'url' => '/planes', 'visible' => true],
        ['label' => 'Oculto', 'url' => '/producto', 'visible' => false],
        ['label' => 'Blog', 'url' => 'https://blog.escudosst.com', 'visible' => true],
    ]], $h)->assertNoContent();

    expect(collect($this->getJson('/api/v1/public/site')->json('data.menu'))->pluck('label')->all())->toBe(['Precios', 'Blog']);
});

it('al publicar una versión con re-aceptación, el usuario debe aceptarla al entrar', function () {
    $admin = authHeaders(tokenFor(ADMIN_EMAIL));
    $terms = collect($this->getJson('/api/v1/internal/site/legal', $admin)->json('data'))->firstWhere('doc_type', 'terms');
    $draft = $terms['versions'][0]['id'];
    $this->patchJson("/api/v1/internal/site/legal/versions/{$draft}", ['content' => "## 1. Objeto\n\nTexto final."], $admin)->assertNoContent();
    $this->postJson("/api/v1/internal/site/legal/versions/{$draft}/publish", ['requires_reacceptance' => true], $admin)->assertNoContent();

    app('auth')->forgetGuards();
    $user = authHeaders(tokenFor(ESENCIAL_EMAIL));
    $pending = $this->getJson('/api/v1/me/legal-pending', $user)->assertOk()->json('data');
    expect(collect($pending)->pluck('version_id')->all())->toContain($draft);

    $this->postJson('/api/v1/me/legal-accept', ['version_ids' => [$draft]], $user)->assertNoContent();
    expect(collect($this->getJson('/api/v1/me/legal-pending', $user)->json('data'))->pluck('version_id')->all())->not->toContain($draft);
    $this->postJson('/api/v1/me/legal-accept', ['version_ids' => [$draft]], $user)->assertStatus(422);   // ya no está pendiente
});

it('exporta el reporte del Libro de Reclamaciones sin datos personales', function () {
    \Illuminate\Support\Facades\Mail::fake();
    $code = $this->postJson('/api/v1/public/complaints', [
        'consumer_full_name' => 'Pedro Reporte', 'consumer_document_type' => 'DNI', 'consumer_document_number' => '11223344',
        'consumer_email' => 'pedro@prueba.test', 'consumer_address' => 'Jr. Lima 100', 'item_type' => 'service',
        'item_description' => 'Plan Esencial', 'complaint_type' => 'complaint', 'detail' => 'La atención por teléfono fue mala.',
        'consumer_request' => 'Mejor atención', 'privacy_consent' => true,
    ])->json('data.complaint_code');

    $csv = $this->get('/api/v1/internal/site/complaints/export', authHeaders(tokenFor(ADMIN_EMAIL)))->assertOk()->streamedContent();
    expect($csv)->toContain('Código;')->toContain($code)->toContain('Queja')->toContain('Pendiente')->not->toContain('Pedro');
});
