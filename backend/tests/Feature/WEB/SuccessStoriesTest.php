<?php

// Casos de éxito (SCR-WEB-09 y 44, RN-WEB-41): borrador → aprobado → publicado ⇄ oculto; aprobar exige la
// autorización escrita del cliente o la marca «Ejemplo»; la autorización nunca sale por la web pública.

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

function stories(string $method, string $uri = '', array $data = [])
{
    return test()->json($method, '/api/v1/internal/site/success-stories'.$uri, $data, authHeaders(tokenFor(ADMIN_EMAIL)));
}

function storyBody(array $o = []): array
{
    return $o + [
        'client_name' => 'Constructora Prueba SAC',
        'title' => 'Ordenó su SST en tres obras a la vez',
        'sector' => 'construction',
        'project_year' => 2026,
        'challenge' => 'Llevaba los registros en Excel y papel en cada obra.',
        'solution' => 'Centralizó IPERC, ATS y charlas en EscudoSST.',
        'results' => [['value' => '3', 'label' => 'obras en un solo panel']],
        'quote' => 'Ahora sabemos qué falta en cada obra.',
        'modules' => ['IPC', 'ATS'],
    ];
}

it('valida los datos y arma la dirección desde el nombre del cliente', function () {
    stories('POST', '', storyBody(['sector' => 'luna']))->assertStatus(422)->assertJsonValidationErrors('sector');
    stories('POST', '', storyBody(['modules' => ['ZZZ']]))->assertStatus(422)->assertJsonValidationErrors('modules');
    stories('POST', '', storyBody(['results' => array_fill(0, 5, ['value' => '1', 'label' => 'x'])]))->assertStatus(422)->assertJsonValidationErrors('results');

    $id = stories('POST', '', storyBody())->assertCreated()->json('data.id');
    expect(stories('GET', "/{$id}")->json('data'))->toMatchArray(['slug' => 'constructora-prueba-sac', 'status' => 'draft', 'has_consent' => false]);
    stories('POST', '', storyBody())->assertStatus(422)->assertJsonValidationErrors('slug');   // misma dirección
});

it('no se aprueba sin autorización ni marca «Ejemplo»; con autorización se publica y sale en /casos', function () {
    $id = stories('POST', '', storyBody())->json('data.id');
    stories('POST', "/{$id}/actions", ['action' => 'publish'])->assertStatus(422);
    stories('POST', "/{$id}/actions", ['action' => 'approve'])->assertStatus(422);

    $h = authHeaders(tokenFor(ADMIN_EMAIL)) + ['Accept' => 'application/json'];
    $this->post("/api/v1/internal/site/success-stories/{$id}/consent", ['file' => UploadedFile::fake()->create('autorizacion.exe', 10, 'application/x-msdownload')], $h)->assertStatus(422);
    $asset = $this->post("/api/v1/internal/site/success-stories/{$id}/consent", ['file' => UploadedFile::fake()->create('autorizacion.pdf', 20, 'application/pdf')], $h)->assertCreated()->json('data.id');

    stories('POST', "/{$id}/actions", ['action' => 'approve'])->assertNoContent();
    $this->getJson('/api/v1/public/success-stories')->assertOk()->assertJsonCount(0, 'data');   // aprobado aún no se ve
    stories('POST', "/{$id}/actions", ['action' => 'publish'])->assertNoContent();

    $public = $this->getJson('/api/v1/public/success-stories/constructora-prueba-sac')->assertOk()->json('data');
    expect($public)->not->toHaveKey('consent_file_id')
        ->and($public['results'][0]['value'])->toBe('3')
        ->and($public['modules'])->toBe(['IPC', 'ATS'])
        ->and($public['is_example'])->toBeFalse();
    expect($this->getJson('/api/v1/public/success-stories?sector=mining')->json('data'))->toBe([]);

    // La autorización no sale por la ruta pública de archivos; sí por el panel.
    $this->get("/api/v1/public/site/assets/{$asset}")->assertNotFound();
    $this->get("/api/v1/internal/site/success-stories/{$id}/consent", authHeaders(tokenFor(ADMIN_EMAIL)))->assertOk();
});

it('retirar la autorización lo oculta al momento y vuelve a pedir aprobación', function () {
    $id = stories('POST', '', storyBody())->json('data.id');
    $h = authHeaders(tokenFor(ADMIN_EMAIL)) + ['Accept' => 'application/json'];
    $this->post("/api/v1/internal/site/success-stories/{$id}/consent", ['file' => UploadedFile::fake()->create('a.pdf', 20, 'application/pdf')], $h)->assertCreated();
    stories('POST', "/{$id}/actions", ['action' => 'approve'])->assertNoContent();
    stories('POST', "/{$id}/actions", ['action' => 'publish'])->assertNoContent();

    stories('POST', "/{$id}/actions", ['action' => 'withdraw_consent'])->assertNoContent();
    expect(stories('GET', "/{$id}")->json('data'))->toMatchArray(['status' => 'hidden', 'has_consent' => false, 'approved_by' => null]);
    $this->getJson('/api/v1/public/success-stories/constructora-prueba-sac')->assertNotFound();
    stories('POST', "/{$id}/actions", ['action' => 'publish'])->assertStatus(422);
});

it('un caso «Ejemplo» se publica sin autorización y no se le quita la marca sin adjuntarla', function () {
    $id = stories('POST', '', storyBody(['is_example' => true]))->json('data.id');
    stories('POST', "/{$id}/actions", ['action' => 'approve'])->assertNoContent();
    stories('POST', "/{$id}/actions", ['action' => 'publish'])->assertNoContent();
    expect($this->getJson('/api/v1/public/success-stories')->json('data.0.is_example'))->toBeTrue();

    stories('PATCH', "/{$id}", storyBody(['is_example' => false]))->assertStatus(422)->assertJsonValidationErrors('is_example');
    stories('POST', "/{$id}/actions", ['action' => 'hide'])->assertNoContent();
    $this->getJson('/api/v1/public/success-stories')->assertJsonCount(0, 'data');

    stories('DELETE', "/{$id}")->assertNoContent();
    stories('GET', "/{$id}")->assertNotFound();
});
