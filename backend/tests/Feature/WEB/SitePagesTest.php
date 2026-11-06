<?php

// Páginas por bloques (SCR-WEB-03, 04, 08 y 20): borrador → publicada; solo lo publicado sale a la web.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

function pages(string $method, string $uri = '', array $data = [])
{
    return test()->json($method, '/api/v1/internal/site/pages'.$uri, $data, authHeaders(tokenFor(ADMIN_EMAIL)));
}

it('trae los borradores de sectores, diferenciadores y consultoras sin publicar', function () {
    $rows = collect(pages('GET')->assertOk()->json('data'));
    expect($rows->where('page_type', 'sector'))->toHaveCount(7)
        ->and($rows->where('page_type', 'product'))->toHaveCount(7)
        ->and($rows->pluck('slug'))->toContain('consultoras')
        ->and($rows->pluck('status')->unique()->all())->toBe(['draft']);
    $this->getJson('/api/v1/public/pages/sectores/construccion')->assertNotFound();
});

it('edita, publica y solo lo publicado se ve; los cambios posteriores esperan a volver a publicar', function () {
    $page = collect(pages('GET')->json('data'))->firstWhere('slug', 'sectores/construccion');
    $p = pages('GET', "/{$page['id']}")->assertOk()->json('data');

    $body = fn (array $o = []) => $o + ['slug' => $p['slug'], 'title' => $p['title'], 'summary' => $p['summary'], 'blocks' => $p['blocks'], 'version' => $p['version']];
    pages('PATCH', "/{$page['id']}", $body(['blocks' => [['type' => 'video', 'title' => 'x', 'url' => 'https://malicioso.test/v']]]))->assertStatus(422);
    pages('PATCH', "/{$page['id']}", $body(['blocks' => [['type' => 'modules', 'items' => ['ZZZ']]]]))->assertStatus(422);
    pages('PATCH', "/{$page['id']}", $body(['slug' => 'Mal Slug']))->assertStatus(422)->assertJsonValidationErrors('slug');

    pages('POST', "/{$page['id']}/publish")->assertNoContent();
    $public = $this->getJson('/api/v1/public/pages/sectores/construccion')->assertOk()->json('data');
    expect($public['title'])->toBe('SST para construcción')->and($public['blocks'][0]['type'])->toBe('hero');
    expect(collect($this->getJson('/api/v1/public/pages?tipo=sector')->json('data'))->pluck('slug'))->toContain('sectores/construccion');

    $current = pages('GET', "/{$page['id']}")->json('data.version');
    $v = pages('PATCH', "/{$page['id']}", $body(['title' => 'SST para obras', 'version' => $current]))->assertOk()->json('data.version');
    pages('PATCH', "/{$page['id']}", $body(['title' => 'Otro', 'version' => $current]))->assertStatus(409);   // versión vieja: alguien más guardó
    expect($this->getJson('/api/v1/public/pages/sectores/construccion')->json('data.title'))->toBe('SST para construcción');
    expect(collect(pages('GET')->json('data'))->firstWhere('id', $page['id'])['has_changes'])->toBeTrue();

    pages('POST', "/{$page['id']}/publish")->assertNoContent();
    expect($this->getJson('/api/v1/public/pages/sectores/construccion')->json('data.title'))->toBe('SST para obras');

    pages('POST', "/{$page['id']}/archive")->assertNoContent();
    $this->getJson('/api/v1/public/pages/sectores/construccion')->assertNotFound();
    expect($v)->toBeInt();
});

it('no publica frases bloqueadas por los lineamientos de contenido', function () {
    $terms = DB::connection('platform')->selectOne('select content_blocked_terms from site_settings where singleton')->content_blocked_terms;
    $term = json_decode($terms, true)[0] ?? null;
    if (! $term) {
        $this->markTestSkipped('No hay términos bloqueados configurados.');
    }
    $id = pages('POST', '', ['page_type' => 'landing', 'slug' => 'prueba-qa', 'title' => 'Prueba QA', 'blocks' => [['type' => 'text', 'title' => 'Hola', 'body' => "Somos {$term}"]]])
        ->assertCreated()->json('data.id');
    pages('POST', "/{$id}/publish")->assertStatus(422)->assertJsonValidationErrors('blocks');
});
