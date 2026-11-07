<?php

// SEO técnico (SCR-WEB-27, RN-WEB-07): redirecciones sin bucles, redirección automática al cambiar la dirección de
// una página publicada, datos del sitemap y códigos de Google.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

function redirects(string $method, string $uri = '', array $data = [])
{
    return test()->json($method, '/api/v1/internal/site/redirects'.$uri, $data, authHeaders(tokenFor(ADMIN_EMAIL)));
}

it('crea redirecciones, cuenta visitas y no permite bucles ni rutas del panel', function () {
    redirects('POST', '', ['source_path' => '/plataforma/x', 'target_url' => '/planes', 'status_code' => 301])->assertStatus(422)->assertJsonValidationErrors('source_path');
    redirects('POST', '', ['source_path' => 'sin-barra', 'target_url' => '/planes', 'status_code' => 301])->assertStatus(422)->assertJsonValidationErrors('source_path');
    redirects('POST', '', ['source_path' => '/a', 'target_url' => 'http://inseguro.test', 'status_code' => 301])->assertStatus(422)->assertJsonValidationErrors('target_url');

    redirects('POST', '', ['source_path' => '/Precios/', 'target_url' => '/planes', 'status_code' => 301])->assertCreated();
    redirects('POST', '', ['source_path' => '/planes', 'target_url' => '/precios', 'status_code' => 302])->assertStatus(422)->assertJsonValidationErrors('target_url');   // bucle
    redirects('POST', '', ['source_path' => '/precios', 'target_url' => '/otra', 'status_code' => 301])->assertStatus(422)->assertJsonValidationErrors('source_path');   // ya existe

    expect(collect($this->getJson('/api/v1/public/site/redirects')->json('data'))->pluck('source_path'))->toContain('/precios');   // normalizada
    $this->getJson('/api/v1/public/site/redirects/resolve?path=/precios')->assertOk()->assertJson(['data' => ['target_url' => '/planes', 'status_code' => 301]]);
    $this->getJson('/api/v1/public/site/redirects/resolve?path=/no-existe')->assertNotFound();
    expect(collect(redirects('GET')->json('data'))->firstWhere('source_path', '/precios')['hit_count'])->toBe(1);
});

it('al cambiar la dirección de una página publicada, la anterior redirige a la nueva y el sitemap lista solo la nueva', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $page = collect($this->getJson('/api/v1/internal/site/pages', $h)->json('data'))->firstWhere('slug', 'sectores/mineria');
    $this->postJson("/api/v1/internal/site/pages/{$page['id']}/publish", [], $h)->assertNoContent();
    $p = $this->getJson("/api/v1/internal/site/pages/{$page['id']}", $h)->json('data');

    $this->patchJson("/api/v1/internal/site/pages/{$page['id']}", ['slug' => 'sectores/mineria-y-energia', 'title' => $p['title'], 'summary' => $p['summary'], 'blocks' => $p['blocks'], 'version' => $p['version']], $h)->assertOk();

    $this->getJson('/api/v1/public/site/redirects/resolve?path=/sectores/mineria')->assertOk()->assertJsonPath('data.target_url', '/sectores/mineria-y-energia');
    $paths = collect($this->getJson('/api/v1/public/site/sitemap')->json('data.entries'))->pluck('path');
    expect($paths)->toContain('/sectores/mineria-y-energia')->not->toContain('/sectores/mineria');
});

it('Soporte no cambia redirecciones; el Dueño guarda los códigos de Google y el sitio los recibe', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $this->patchJson('/api/v1/internal/site/seo', ['ga4' => 'UA-123'], $h)->assertStatus(422)->assertJsonValidationErrors('ga4');
    $this->patchJson('/api/v1/internal/site/seo', ['ga4' => 'G-ABC123XYZ', 'search_console_verification' => 'abc-123_XYZ'], $h)->assertNoContent();
    expect($this->getJson('/api/v1/internal/site/seo', $h)->json('data'))->toMatchArray(['ga4' => 'G-ABC123XYZ', 'search_console_verification' => 'abc-123_XYZ']);

    $admin = DB::connection('platform')->selectOne('select id from users where email = ?', [ADMIN_EMAIL])->id;
    DB::connection('pgsql')->select("select set_config('app.user_id', ?, false)", [$admin]);
    DB::connection('pgsql')->update("update users set platform_role = 'support' where id = ?", [$admin]);
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/internal/site/redirects', $h)->assertOk();
    $this->postJson('/api/v1/internal/site/redirects', ['source_path' => '/x', 'target_url' => '/planes', 'status_code' => 301], $h)->assertForbidden();
});
