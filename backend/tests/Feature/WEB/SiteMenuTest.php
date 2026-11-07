<?php

// Menú de la cabecera: solo enlaza a páginas que existen; si una página deja de estar publicada, su enlace se oculta.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

it('rechaza enlaces a páginas que no existen y oculta los de páginas quitadas del sitio', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $menu = $this->getJson('/api/v1/internal/site/menu', $h)->assertOk()->json();
    expect(collect($menu['pages'])->pluck('url'))->toContain('/planes')->not->toContain('/sectores/mineria');   // mineria es borrador

    $this->putJson('/api/v1/internal/site/menu', ['items' => [['label' => 'Minería', 'url' => '/sectores/mineria', 'visible' => true]]], $h)
        ->assertStatus(422)->assertJsonValidationErrors('items.0.url');

    $page = collect($this->getJson('/api/v1/internal/site/pages', $h)->json('data'))->firstWhere('slug', 'sectores/mineria');
    $this->postJson("/api/v1/internal/site/pages/{$page['id']}/publish", [], $h)->assertNoContent();
    $items = [['label' => 'Minería', 'url' => '/sectores/mineria', 'visible' => true], ['label' => 'Planes', 'url' => '/planes?x=1', 'visible' => true], ['label' => 'Blog', 'url' => 'https://blog.ejemplo.test', 'visible' => true]];
    $this->putJson('/api/v1/internal/site/menu', ['items' => $items], $h)->assertNoContent();
    expect(collect($this->getJson('/api/v1/public/site')->json('data.menu'))->pluck('label')->all())->toBe(['Minería', 'Planes', 'Blog']);

    $this->postJson("/api/v1/internal/site/pages/{$page['id']}/archive", [], $h)->assertNoContent();
    expect(collect($this->getJson('/api/v1/public/site')->json('data.menu'))->pluck('label')->all())->toBe(['Planes', 'Blog']);
    expect(collect($this->getJson('/api/v1/internal/site/menu', $h)->json('data'))->firstWhere('label', 'Minería')['available'])->toBeFalse();
});
