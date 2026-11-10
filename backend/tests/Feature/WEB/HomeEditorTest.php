<?php

// Editor de la portada: mostrar u ocultar cada sección, cambiar sus textos (vacío = texto de fábrica), publicar al
// guardar, respetar los lineamientos de contenido y no exponer la portada como página suelta en /{slug}.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

/** Lo publicado de la portada (con la conexión de la plataforma, que ve la transacción de la prueba). */
function publishedHome(): array
{
    $r = json_decode(DB::connection('platform')->selectOne("select public_site_page('') as p")->p, true);

    return collect($r['blocks'] ?? [])->keyBy('key')->all();
}

it('oculta secciones, cambia textos y lo publica al guardar', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $this->getJson('/api/v1/internal/site/home', $h)->assertOk();

    $this->putJson('/api/v1/internal/site/home', ['sections' => [
        ['key' => 'hero', 'visible' => false, 'title' => 'SST *sin papeles* para tu empresa', 'eyebrow' => '', 'text' => null],
        ['key' => 'problem', 'visible' => false],
        ['key' => 'faq', 'visible' => true, 'title' => '  Dudas frecuentes  '],
    ]], $h)->assertOk();

    $home = publishedHome();
    expect($home['hero'])->toMatchArray(['visible' => true, 'title' => 'SST *sin papeles* para tu empresa', 'eyebrow' => null])   // el inicio siempre se muestra
        ->and($home['problem']['visible'])->toBeFalse()
        ->and($home['faq']['title'])->toBe('Dudas frecuentes');
    $this->getJson('/api/v1/internal/site/home', $h)->assertJsonPath('data.sections.1.key', 'problem')->assertJsonPath('data.sections.1.visible', false);

    // «Volver al texto de fábrica»: se guarda vacío.
    $this->putJson('/api/v1/internal/site/home', ['sections' => [['key' => 'faq', 'visible' => true, 'title' => null]]], $h)->assertOk();
    expect(publishedHome()['faq']['title'])->toBeNull();

    // La portada no es una página suelta.
    $this->getJson('/api/v1/public/pages/portada')->assertNotFound();
});

it('rechaza secciones desconocidas y frases bloqueadas por los lineamientos', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $this->putJson('/api/v1/internal/site/home', ['sections' => [['key' => 'otra', 'visible' => true]]], $h)->assertStatus(422);
    $this->putJson('/api/v1/internal/site/home', ['sections' => [['key' => 'faq'], ['key' => 'faq']]], $h)->assertStatus(422);

    DB::connection('platform')->update("update site_settings set content_blocked_terms = '[\"cero accidentes garantizado\"]'::jsonb where singleton");
    $this->putJson('/api/v1/internal/site/home', ['sections' => [['key' => 'demo', 'visible' => true, 'title' => 'Cero accidentes garantizado con EscudoSST']]], $h)
        ->assertStatus(422)->assertJsonPath('errors.sections.0', fn ($m) => str_contains($m, 'cero accidentes garantizado'));
});
