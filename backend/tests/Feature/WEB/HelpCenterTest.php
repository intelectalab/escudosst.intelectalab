<?php

// Centro de ayuda (SCR-WEB-32 a 34 y 45, RN-WEB-43 a 45): solo publicados de categorías activas, buscador,
// relacionados sin borradores, «¿Te sirvió?» inalterable y ayuda contextual por código de pantalla.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

/** Crea una categoría y dos artículos (uno publicado, otro en borrador). Devuelve [categoría, publicado, borrador]. */
function helpSeed(): array
{
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $cat = test()->postJson('/api/v1/internal/site/help/categories', ['name' => 'Primeros pasos', 'icon' => 'play', 'status' => 'active'], $h)->assertCreated()->json('data.id');
    $draft = test()->postJson('/api/v1/internal/site/help/articles', [
        'category_id' => $cat, 'title' => 'Borrador sobre capacitaciones', 'body' => 'Texto del borrador que aún no se publica en el sitio.',
    ], $h)->assertCreated()->json('data.id');
    $pub = test()->postJson('/api/v1/internal/site/help/articles', [
        'category_id' => $cat, 'title' => 'Cómo invitar a tu equipo', 'screen_code' => 'SCR-USR-02', 'related' => [$draft],
        'body' => "## Antes de empezar\n\nNecesitas ser administrador.\n\n- Entra a Usuarios\n- Pulsa «Invitar»",
    ], $h)->assertCreated()->json('data.id');
    test()->postJson("/api/v1/internal/site/help/articles/{$pub}/publish", [], $h)->assertNoContent();

    return [$cat, $pub, $draft];
}

it('el sitio solo muestra lo publicado de categorías activas y busca por texto', function () {
    [$cat] = helpSeed();
    $cats = $this->getJson('/api/v1/public/help/categories')->assertOk()->json('data');
    expect(collect($cats)->firstWhere('slug', 'primeros-pasos')['articles_count'])->toBe(1);

    $list = $this->getJson('/api/v1/public/help/articles?q=equipo')->assertOk()->json('data');
    // Se ignoran los artículos «Cómo usar …» de cada módulo que vienen cargados de fábrica.
    $list = array_values(array_filter($list, fn ($a) => $a['category_slug'] !== 'uso-de-los-modulos'));
    expect(collect($list)->pluck('slug')->all())->toBe(['como-invitar-a-tu-equipo'])
        ->and($list[0]['excerpt'])->not->toContain('##');
    expect(collect($this->getJson('/api/v1/public/help/articles?q=borrador')->json('data'))->where('category_slug', '!=', 'uso-de-los-modulos')->values()->all())->toBe([]);
    $this->getJson('/api/v1/public/help/articles/borrador-sobre-capacitaciones')->assertNotFound();

    $a = $this->getJson('/api/v1/public/help/articles/como-invitar-a-tu-equipo')->assertOk()->json('data');
    expect($a['related'])->toBe([])->and($a['category']['slug'])->toBe('primeros-pasos');   // el relacionado es borrador

    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $this->patchJson("/api/v1/internal/site/help/categories/{$cat}", ['name' => 'Primeros pasos', 'status' => 'hidden'], $h)->assertNoContent();
    $this->getJson('/api/v1/public/help/articles/como-invitar-a-tu-equipo')->assertNotFound();
    expect(collect($this->getJson('/api/v1/public/site/sitemap')->json('data.entries'))->pluck('path'))->not->toContain('/ayuda/como-invitar-a-tu-equipo');
});

it('«¿Te sirvió?» suma el voto, guarda el comentario sin etiquetas y no repite con la misma clave', function () {
    [, $pub] = helpSeed();
    $key = (string) \Illuminate\Support\Str::uuid7();
    $this->postJson('/api/v1/public/help/articles/como-invitar-a-tu-equipo/feedback', ['was_helpful' => false, 'comment' => '<b>Falta</b> un ejemplo', 'key' => $key])->assertCreated();
    $this->postJson('/api/v1/public/help/articles/como-invitar-a-tu-equipo/feedback', ['was_helpful' => false, 'key' => $key])->assertCreated();
    $this->postJson('/api/v1/public/help/articles/como-invitar-a-tu-equipo/feedback', ['was_helpful' => true])->assertCreated();
    $this->postJson('/api/v1/public/help/articles/como-invitar-a-tu-equipo/feedback', [])->assertStatus(422);
    $this->postJson('/api/v1/public/help/articles/no-existe/feedback', ['was_helpful' => true])->assertNotFound();

    $f = $this->getJson("/api/v1/internal/site/help/articles/{$pub}/feedback", authHeaders(tokenFor(ADMIN_EMAIL)))->assertOk()->json('data');
    expect($f['helpful'])->toBe(1)->and($f['not_helpful'])->toBe(1)->and($f['comments'][0]['comment'])->toBe('Falta un ejemplo');
});

it('la ayuda contextual exige sesión y filtra por el código de la pantalla', function () {
    $this->getJson('/api/v1/me/help?screen_code=SCR-USR-02')->assertUnauthorized();
    helpSeed();
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    expect(collect($this->getJson('/api/v1/me/help?screen_code=SCR-USR-02', $h)->assertOk()->json('data'))->pluck('slug')->all())->toBe(['como-invitar-a-tu-equipo']);
    expect($this->getJson('/api/v1/me/help?screen_code=SCR-WEB-01', $h)->json('data'))->toBe([]);
    $this->getJson('/api/v1/me/help?screen_code=hola', $h)->assertStatus(422);
});

it('valida el video, no borra lo publicado, redirige al cambiar la dirección y Soporte no publica', function () {
    [$cat, $pub, $draft] = helpSeed();
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $base = ['category_id' => $cat, 'title' => 'Cómo invitar a tu equipo', 'body' => 'Texto suficientemente largo para el artículo.'];
    $this->patchJson("/api/v1/internal/site/help/articles/{$pub}", $base + ['video_url' => 'https://ejemplo.com/v.mp4'], $h)->assertStatus(422)->assertJsonValidationErrors('video_url');
    $this->deleteJson("/api/v1/internal/site/help/articles/{$pub}", [], $h)->assertStatus(422);
    $this->deleteJson("/api/v1/internal/site/help/categories/{$cat}", [], $h)->assertStatus(422);

    $this->patchJson("/api/v1/internal/site/help/articles/{$pub}", $base + ['slug' => 'invitar-equipo'], $h)->assertNoContent();
    expect(DB::connection('platform')->selectOne("select target_url from site_redirects where source_path = '/ayuda/como-invitar-a-tu-equipo' and is_active")->target_url)->toBe('/ayuda/invitar-equipo');

    $admin = DB::connection('platform')->selectOne('select id from users where email = ?', [ADMIN_EMAIL])->id;
    DB::connection('pgsql')->select("select set_config('app.user_id', ?, false)", [$admin]);
    DB::connection('pgsql')->update("update users set platform_role = 'support' where id = ?", [$admin]);
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/internal/site/help', $h)->assertOk();
    $this->postJson("/api/v1/internal/site/help/articles/{$draft}/publish", [], $h)->assertForbidden();
});
