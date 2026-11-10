<?php

// Mapa de riesgos: pictogramas sobre el plano; solo se cambian mientras el mapa está en borrador o revisión.

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Storage::fake('local');
});

it('ubica pictogramas en el plano y los congela cuando el mapa se aprueba', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $file = $this->post('/api/v1/files', ['file' => UploadedFile::fake()->image('plano.png', 800, 600), 'module' => 'MAP'], $h)->json('data.id');
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'MAP-P', 'name' => 'Planta pictogramas'], $h)->json('data.id');
    $map = $this->postJson('/api/v1/records/MAP/risk_maps', ['site_id' => $site, 'title' => 'Mapa planta', 'plan_file_id' => $file], $h)->assertCreated()->json('data.id');

    $m = $this->postJson('/api/v1/records/MAP/risk_map_markers', ['risk_map_id' => $map, 'marker_type' => 'fire', 'x_pct' => 25.5, 'y_pct' => 40, 'label' => 'Extintor PQS'], $h)
        ->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/MAP/risk_map_markers', ['risk_map_id' => $map, 'marker_type' => 'hazard', 'x_pct' => 120, 'y_pct' => 10], $h)->assertStatus(422);
    $this->patchJson("/api/v1/records/MAP/risk_map_markers/{$m}", ['x_pct' => 30], $h)->assertOk();
    $this->getJson("/api/v1/records/MAP/risk_map_markers?f[risk_map_id]={$map}", $h)->assertOk()->assertJsonPath('data.0.label', 'Extintor PQS');

    $this->patchJson("/api/v1/records/MAP/risk_maps/{$map}", ['status' => 'approved'], $h)->assertOk();
    $this->postJson('/api/v1/records/MAP/risk_map_markers', ['risk_map_id' => $map, 'marker_type' => 'first_aid', 'x_pct' => 50, 'y_pct' => 50], $h)->assertStatus(409);
    $this->patchJson("/api/v1/records/MAP/risk_map_markers/{$m}", ['x_pct' => 60], $h)->assertStatus(409);
    $this->deleteJson("/api/v1/records/MAP/risk_map_markers/{$m}", [], $h)->assertStatus(409);
});
