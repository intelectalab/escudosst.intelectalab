<?php

// Biblioteca de medios (SCR-WEB-65, RN-WEB-82): tipos por contenido real, texto alternativo obligatorio en imágenes,
// reemplazo conservando el registro, «dónde se usa», no se borra si se usa, y galería pública de los casos.

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Storage::fake('local');
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

function mediaUpload(array $data)
{
    return test()->post('/api/v1/internal/site/media', $data, authHeaders(tokenFor(ADMIN_EMAIL)) + ['Accept' => 'application/json']);
}

it('sube imágenes con texto alternativo y rechaza SVG o archivos sin descripción', function () {
    mediaUpload(['file' => UploadedFile::fake()->create('x.svg', 3, 'image/svg+xml'), 'title' => 'Dibujo'])->assertStatus(422)->assertJsonValidationErrors('file');
    mediaUpload(['file' => UploadedFile::fake()->image('obra.png', 800, 500), 'title' => 'Obra'])->assertStatus(422)->assertJsonValidationErrors('alt_text');

    $m = mediaUpload(['file' => UploadedFile::fake()->image('obra.png', 800, 500), 'title' => 'Obra', 'alt_text' => 'Supervisores en una obra', 'folder' => 'Casos', 'tags' => ['obra']])
        ->assertCreated()->json('data');
    expect($m)->toMatchArray(['kind' => 'image', 'width' => 800, 'height' => 500, 'folder' => 'Casos', 'tags' => ['obra']]);
    $this->get("/api/v1/public/site/assets/{$m['file_id']}")->assertOk();   // es público: es del sitio

    $pdf = mediaUpload(['file' => UploadedFile::fake()->create('guia.pdf', 50, 'application/pdf'), 'title' => 'Guía'])->assertCreated()->json('data');
    expect($pdf['kind'])->toBe('document');

    $list = $this->getJson('/api/v1/internal/site/media?kind=image', authHeaders(tokenFor(ADMIN_EMAIL)))->assertOk()->json();
    expect(collect($list['data'])->pluck('id'))->toContain($m['id'])->not->toContain($pdf['id'])->and($list['folders'])->toContain('Casos');
});

it('la galería de un caso usa la biblioteca; un medio en uso no se borra y al reemplazarlo conserva sus usos', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $m = mediaUpload(['file' => UploadedFile::fake()->image('a.jpg', 600, 400), 'title' => 'Foto', 'alt_text' => 'Charla de cinco minutos'])->json('data');
    $pdf = mediaUpload(['file' => UploadedFile::fake()->create('g.pdf', 10, 'application/pdf'), 'title' => 'Guía'])->json('data');
    $sid = $this->postJson('/api/v1/internal/site/success-stories', [
        'client_name' => 'Galería Prueba SAC', 'title' => 'Caso con galería de prueba', 'sector' => 'construction',
        'challenge' => 'Un reto de prueba para la galería.', 'solution' => 'Una solución de prueba para la galería.', 'is_example' => true,
    ], $h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/internal/site/success-stories/{$sid}/actions", ['action' => 'approve'], $h)->assertNoContent();
    $this->postJson("/api/v1/internal/site/success-stories/{$sid}/actions", ['action' => 'publish'], $h)->assertNoContent();
    $story = (object) ['id' => $sid, 'slug' => 'galeria-prueba-sac'];

    $this->putJson("/api/v1/internal/site/success-stories/{$story->id}/gallery", ['items' => [['media_id' => $pdf['id']]]], $h)->assertStatus(422);
    $this->putJson("/api/v1/internal/site/success-stories/{$story->id}/gallery", ['items' => [['media_id' => $m['id'], 'caption' => 'Charla en obra']]], $h)->assertNoContent();

    $public = $this->getJson("/api/v1/public/success-stories/{$story->slug}")->assertOk()->json('data.gallery');
    expect($public[0])->toMatchArray(['file_id' => $m['file_id'], 'alt_text' => 'Charla de cinco minutos', 'caption' => 'Charla en obra']);

    expect($this->getJson("/api/v1/internal/site/media/{$m['id']}/usages", $h)->json('data.count'))->toBe(1);
    $this->deleteJson("/api/v1/internal/site/media/{$m['id']}", [], $h)->assertStatus(422);

    $new = $this->post("/api/v1/internal/site/media/{$m['id']}/replace", ['file' => UploadedFile::fake()->image('b.png', 300, 300)], $h + ['Accept' => 'application/json'])->assertOk()->json('data');
    expect($new['id'])->toBe($m['id'])->and($new['file_id'])->not->toBe($m['file_id'])->and($new['width'])->toBe(300);
    expect($this->getJson("/api/v1/public/success-stories/{$story->slug}")->json('data.gallery.0.file_id'))->toBe($new['file_id']);
    $this->post("/api/v1/internal/site/media/{$m['id']}/replace", ['file' => UploadedFile::fake()->create('c.pdf', 5, 'application/pdf')], $h + ['Accept' => 'application/json'])->assertStatus(422);

    $this->putJson("/api/v1/internal/site/success-stories/{$story->id}/gallery", ['items' => []], $h)->assertNoContent();
    $this->deleteJson("/api/v1/internal/site/media/{$m['id']}", [], $h)->assertNoContent();
});
