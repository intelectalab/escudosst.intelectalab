<?php

// Archivos: subir (tipo y tamaño), usar en un registro, descargar solo en la empresa dueña.

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Storage::fake('local');
});

it('sube el plano del mapa de riesgos, lo vincula y solo lo descarga su empresa', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->post('/api/v1/files', ['file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')], $h)->assertStatus(422);

    $up = $this->post('/api/v1/files', ['file' => UploadedFile::fake()->image('plano.png', 800, 600), 'module' => 'MAP'], $h)->assertCreated();
    $file = $up->json('data.id');

    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'FIL-S', 'name' => 'Sede plano'], $h)->json('data.id');
    $map = $this->postJson('/api/v1/records/MAP/risk_maps', ['site_id' => $site, 'title' => 'Mapa de riesgos planta', 'plan_file_id' => $file], $h)
        ->assertCreated()->assertJsonPath('data.plan_file_id__label', 'plano.png')->json('data.id');
    foreach (['approved', 'current'] as $st) {
        $this->patchJson("/api/v1/records/MAP/risk_maps/{$map}", ['status' => $st], $h)->assertOk();
    }

    $this->get("/api/v1/files/{$file}", $h)->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get("/api/v1/files/{$file}", authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID))->assertNotFound();

    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->postJson('/api/v1/records/MAP/risk_maps', ['site_id' => $site, 'title' => 'X', 'plan_file_id' => '01900000-0000-7000-8000-00000000ffff'], $h)
        ->assertStatus(422);
});
