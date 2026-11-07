<?php

// Biblioteca de recursos (SCR-WEB-30/43, RN-WEB-40): libre sin datos; «con registro» exige el formulario; enlace
// firmado de corta duración; la descarga queda registrada y quien se registra entra a «Interesados».

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

/** Sube un PDF a la biblioteca, crea el recurso y lo publica. Devuelve [id, media]. */
function publishedResource(bool $gated): array
{
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $m = test()->post('/api/v1/internal/site/media', ['file' => UploadedFile::fake()->create('matriz.pdf', 40, 'application/pdf'), 'title' => 'Matriz IPERC'], $h + ['Accept' => 'application/json'])->json('data');
    $id = test()->postJson('/api/v1/internal/site/resources', [
        'title' => 'Plantilla de matriz IPERC', 'resource_type' => 'template', 'sector' => 'construction', 'media_id' => $m['id'], 'is_gated' => $gated,
    ], $h)->assertCreated()->json('data.id');
    test()->postJson("/api/v1/internal/site/resources/{$id}/publish", [], $h)->assertNoContent();

    return [$id, $m];
}

it('un recurso libre se descarga sin datos con un enlace firmado que vence', function () {
    [$id] = publishedResource(false);
    $list = $this->getJson('/api/v1/public/resources?tipo=template')->assertOk()->json('data');
    expect(collect($list)->pluck('slug'))->toContain('plantilla-de-matriz-iperc')->and($list[0])->not->toHaveKey('file_id');

    $url = $this->postJson('/api/v1/public/resources/plantilla-de-matriz-iperc/download')->assertOk()->json('data.url');
    $this->get($url)->assertOk();
    $this->get(preg_replace('/signature=[^&]+/', 'signature=falsa', $url))->assertForbidden();
    $this->get('/api/v1/public/resources/file/plantilla-de-matriz-iperc')->assertForbidden();   // sin firma

    expect(DB::connection('platform')->selectOne('select download_count from resource_items where id = ?', [$id])->download_count)->toBe(1);
});

it('con registro pide los datos, crea el interesado y el archivo no sale por la ruta pública', function () {
    [$id, $m] = publishedResource(true);
    $this->postJson('/api/v1/public/resources/plantilla-de-matriz-iperc/download')->assertStatus(422)->assertJsonValidationErrors('full_name');
    $this->postJson('/api/v1/public/resources/plantilla-de-matriz-iperc/download', [
        'full_name' => 'Ana Prueba', 'email' => 'ana@ejemplo.test', 'company_name' => 'Obras Prueba', 'privacy_consent' => false,
    ])->assertStatus(422)->assertJsonValidationErrors('privacy_consent');

    $this->postJson('/api/v1/public/resources/plantilla-de-matriz-iperc/download', [
        'full_name' => 'Ana Prueba', 'email' => 'ana@ejemplo.test', 'company_name' => 'Obras Prueba', 'privacy_consent' => true,
    ])->assertOk()->assertJsonStructure(['data' => ['url', 'expires_minutes']]);

    $lead = DB::connection('platform')->selectOne("select source, company_name from marketing_leads where email = 'ana@ejemplo.test'");
    expect($lead->source)->toBe('resource')->and($lead->company_name)->toBe('Obras Prueba');
    expect(DB::connection('platform')->selectOne('select lead_id, ip_hash from resource_downloads where resource_id = ?', [$id]))->lead_id->not->toBeNull();

    $this->get("/api/v1/public/site/assets/{$m['file_id']}")->assertNotFound();
});

it('no se publica sin archivo y no se elimina si ya tiene descargas', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $id = $this->postJson('/api/v1/internal/site/resources', ['title' => 'Guía sin archivo', 'resource_type' => 'guide'], $h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/internal/site/resources/{$id}/publish", [], $h)->assertStatus(422);

    [$rid] = publishedResource(false);
    $this->postJson('/api/v1/public/resources/plantilla-de-matriz-iperc/download')->assertOk();
    $this->deleteJson("/api/v1/internal/site/resources/{$rid}", [], $h)->assertStatus(422);
    $this->postJson("/api/v1/internal/site/resources/{$rid}/archive", [], $h)->assertNoContent();
    $this->getJson('/api/v1/public/resources')->assertJsonMissing(['slug' => 'plantilla-de-matriz-iperc']);
});
