<?php

// Políticas del sitio (SCR-WEB-15 y 24): borrador editable, no publicar texto de prueba, versiones y huella.

use Illuminate\Support\Facades\DB;

// Las versiones publicadas no se pueden modificar (trigger de la base), así que nada de esta prueba debe quedar:
// todo lo que se hace por la conexión 'platform' corre dentro de una transacción que se revierte al final.
beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('platform')->rollBack(0);
});

function legal(string $method, string $uri, array $data = [])
{
    return test()->json($method, '/api/v1/internal/site/legal'.$uri, $data, authHeaders(tokenFor(ADMIN_EMAIL)));
}

it('los textos traen una estructura de prueba editable y el sitio los muestra como «en preparación»', function () {
    $docs = collect(legal('GET', '')->assertOk()->json('data'))->keyBy('doc_type');
    expect($docs->keys()->all())->toContain('terms', 'privacy', 'cookies', 'dpa', 'sla', 'complaints_info')
        ->and($docs['terms']['versions'][0]['status'])->toBe('draft')
        ->and($docs['terms']['versions'][0]['has_test_text'])->toBeTrue();

    $page = $this->getJson('/api/v1/public/legal/terminos-y-condiciones')->assertOk()->json('data');
    expect($page['status'])->toBe('draft')->and($page['content'])->toContain('## 1. Objeto y aceptación');
});

it('no publica texto de prueba; publica con revisión del abogado y guarda la huella', function () {
    $this->getJson('/api/v1/internal/site/legal', authHeaders(tokenFor(ESENCIAL_EMAIL)))->assertStatus(403);
    $terms = collect(legal('GET', '')->json('data'))->firstWhere('doc_type', 'terms');
    $draft = $terms['versions'][0]['id'];

    legal('POST', "/versions/{$draft}/publish", ['legal_reviewed_by' => 'Dra. Ana Torres'])
        ->assertStatus(422)->assertJsonPath('errors.content.0', 'El texto todavía tiene partes «[Texto de prueba]». Reemplázalas con el texto del abogado antes de publicar.');

    legal('PATCH', "/versions/{$draft}", ['content' => "## 1. Objeto\n\nTexto final revisado.", 'summary_of_changes' => 'Texto del abogado'])->assertNoContent();
    legal('POST', "/versions/{$draft}/publish", ['legal_reviewed_by' => 'Dra. Ana Torres'])->assertNoContent();

    $v = DB::connection('platform')->selectOne('select status, content_sha256 is not null as hashed, legal_reviewed_by from legal_document_versions where id = ?', [$draft]);
    expect($v->status)->toBe('published')->and($v->hashed)->toBeTrue()->and($v->legal_reviewed_by)->toBe('Dra. Ana Torres');

    // Ya publicado: no se edita; se crea una versión nueva que copia el texto.
    legal('PATCH', "/versions/{$draft}", ['content' => 'cambio'])->assertStatus(409);
    $new = legal('POST', "/{$terms['id']}/versions")->assertCreated()->json('data.id');
    legal('POST', "/{$terms['id']}/versions")->assertStatus(409);
    $versions = collect(legal('GET', '')->json('data'))->firstWhere('doc_type', 'terms')['versions'];
    expect($versions[0]['id'])->toBe($new)->and($versions[0]['version_label'])->toBe('2.0')->and($versions[0]['content'])->toContain('Texto final revisado.');

    $page = $this->getJson('/api/v1/public/legal/terminos-y-condiciones')->json('data');
    expect($page['status'])->toBe('published')->and($page['version_label'])->toBe('1.0');
});
