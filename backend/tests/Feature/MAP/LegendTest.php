<?php

// Mapa de riesgos (M-MAP): leyenda de la vista principal y del afiche con ubicación, tipo y fila IPERC vinculada.

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Storage::fake('local');
});

it('devuelve la leyenda con la ubicación y el IPERC vinculado de cada pictograma', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $file = $this->post('/api/v1/files', ['file' => UploadedFile::fake()->image('plano.png', 800, 600), 'module' => 'MAP'], $h)->json('data.id');
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'MAP-L', 'name' => 'Planta Leyenda'], $h)->json('data.id');
    $map = $this->postJson('/api/v1/records/MAP/risk_maps', ['site_id' => $site, 'title' => 'Nave 1', 'floor_label' => 'Piso 1', 'plan_file_id' => $file], $h)
        ->assertCreated()->json('data.id');

    $criteria = collect($this->getJson('/api/v1/records/IPC/iperc_matrices/options/risk_criteria_id?q=R.M.', $h)->json('data'))->first()['id'];
    $matrix = $this->postJson('/api/v1/records/IPC/iperc_matrices', ['code' => 'MAP-IPC', 'title' => 'Prensas', 'risk_criteria_id' => $criteria], $h)->json('data.id');
    $version = $this->getJson("/api/v1/ipc/matrices/{$matrix}/workspace", $h)->json('data.version_id');
    $row = $this->postJson('/api/v1/records/IPC/iperc_rows', ['iperc_version_id' => $version, 'activity' => 'Troquelado', 'task' => 'Alimentar prensa',
        'hazard' => 'Partes móviles', 'risk' => 'Atrapamiento', 'probability_detail' => ['exposed_people' => 2, 'procedures' => 2, 'training' => 2, 'exposure' => 3],
        'severity_value' => 2], $h)->assertCreated()->json('data.id');

    $this->postJson('/api/v1/records/MAP/risk_map_markers', ['risk_map_id' => $map, 'marker_type' => 'hazard', 'x_pct' => 20, 'y_pct' => 30, 'label' => 'Riesgo mecánico',
        'iperc_row_id' => $row], $h)->assertCreated();
    $this->postJson('/api/v1/records/MAP/risk_map_markers', ['risk_map_id' => $map, 'marker_type' => 'fire', 'x_pct' => 70, 'y_pct' => 70, 'label' => 'Extintor'], $h)->assertCreated();

    $d = $this->getJson("/api/v1/map/maps/{$map}/legend", $h)->assertOk()->json('data');
    expect($d['map']['place'])->toBe('Planta Leyenda · Piso 1')->and($d['markers'])->toHaveCount(2);
    $hz = collect($d['markers'])->firstWhere('marker_type', 'hazard');
    expect($hz['location'])->toBe('Planta Leyenda · Piso 1')
        ->and($hz['iperc']['matrix_code'])->toBe('MAP-IPC')
        ->and($hz['iperc']['row_number'])->toBe(1)
        ->and($hz['iperc']['hazard'])->toBe('Partes móviles')
        ->and($hz['iperc']['stale'])->toBeFalse();
    expect(collect($d['markers'])->firstWhere('marker_type', 'fire')['iperc'])->toBeNull();

    $this->getJson('/api/v1/map/maps/00000000-0000-7000-8000-000000000000/legend', $h)->assertNotFound();
});
