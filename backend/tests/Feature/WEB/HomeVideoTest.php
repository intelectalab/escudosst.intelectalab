<?php

// Video de la sección «Demostración» de la portada: video de la Biblioteca de medios o enlace de YouTube/Vimeo,
// se quita y la portada vuelve al reproductor de muestra; un video en la portada no se puede borrar de la biblioteca.

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

function homeVideoPublic(): ?array
{
    // La lectura pública usa otra conexión: dentro de la transacción de la prueba se lee lo mismo con la de la plataforma.
    $list = json_decode(DB::connection('platform')->selectOne('select public_site_settings() as s')->s, true)['hero_video_urls'] ?? [];

    return collect($list)->firstWhere('kind', 'presentation');
}

it('guarda un enlace de YouTube, rechaza otras páginas y lo quita', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $this->putJson('/api/v1/internal/site/settings/home-video', ['url' => 'https://ejemplo.com/video.mp4'], $h)
        ->assertStatus(422)->assertJsonPath('errors.url.0', 'Pega un enlace de YouTube o de Vimeo.');
    $this->putJson('/api/v1/internal/site/settings/home-video', [], $h)->assertStatus(422);

    $this->putJson('/api/v1/internal/site/settings/home-video', ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'], $h)
        ->assertOk()->assertJsonPath('data.provider', 'youtube');
    expect(homeVideoPublic())->toMatchArray(['provider' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);
    $this->getJson('/api/v1/internal/site/settings', $h)->assertJsonPath('data.home_video.provider', 'youtube');

    $this->putJson('/api/v1/internal/site/settings/home-video', ['url' => 'https://vimeo.com/76979871'], $h)->assertOk()->assertJsonPath('data.provider', 'vimeo');
    $this->deleteJson('/api/v1/internal/site/settings/home-video', [], $h)->assertNoContent();
    expect(homeVideoPublic())->toBeNull();
});

it('usa un video subido a la biblioteca, que no se puede borrar mientras está en la portada', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $mp4 = UploadedFile::fake()->createWithContent('recorrido.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 200))
        ->mimeType('video/mp4');   // el archivo falso de prueba no lee el contenido; en una subida real se detecta como video/mp4
    $m = $this->post('/api/v1/internal/site/media', ['file' => $mp4, 'title' => 'Recorrido'], $h + ['Accept' => 'application/json'])->assertCreated()->json('data');
    expect($m['kind'])->toBe('video');
    $img = $this->post('/api/v1/internal/site/media', ['file' => UploadedFile::fake()->image('a.png', 50, 50), 'title' => 'Foto', 'alt_text' => 'Una foto'],
        $h + ['Accept' => 'application/json'])->json('data');
    $this->putJson('/api/v1/internal/site/settings/home-video', ['media_id' => $img['id']], $h)->assertStatus(422)->assertJsonValidationErrors('media_id');

    $this->putJson('/api/v1/internal/site/settings/home-video', ['media_id' => $m['id']], $h)->assertOk()->assertJsonPath('data.provider', 'upload');
    expect(homeVideoPublic())->toMatchArray(['provider' => 'upload', 'file_id' => $m['file_id']]);
    $this->getJson('/api/v1/internal/site/settings', $h)->assertJsonPath('data.home_video.title', 'Recorrido');

    $this->getJson("/api/v1/internal/site/media/{$m['id']}/usages", $h)->assertJsonPath('data.count', 1)->assertJsonPath('data.items.0.label', 'Video de la portada');
    $this->deleteJson("/api/v1/internal/site/media/{$m['id']}", [], $h)->assertStatus(422);

    $this->deleteJson('/api/v1/internal/site/settings/home-video', [], $h)->assertNoContent();
    $this->deleteJson("/api/v1/internal/site/media/{$m['id']}", [], $h)->assertNoContent();
});
