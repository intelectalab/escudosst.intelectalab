<?php

// Modo SUNAFIL: armar el expediente revisa los puntos clave con los datos de la empresa y lista las brechas.

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

it('arma el expediente con lo incluido y lo que falta', function () {
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $d = $this->postJson('/api/v1/records/SUN/inspection_dossiers', ['title' => 'Inspección SUNAFIL 2026', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31'], $h)
        ->assertCreated()->json('data.id');
    $res = $this->postJson("/api/v1/sun/dossiers/{$d}/build", [], $h)->assertOk();
    expect($res->json('data.total'))->toBeGreaterThanOrEqual(14)->and($res->json('data.gaps'))->toBeGreaterThan(0);
    $titles = collect($res->json('data.items'))->pluck('title')->implode(' | ');
    expect($titles)->toContain('Política de SST vigente')->toContain('Matrices IPERC');
    $this->getJson("/api/v1/records/SUN/inspection_dossiers/{$d}", $h)->assertJsonPath('data.status', 'ready_with_gaps');
    expect($this->getJson("/api/v1/records/SUN/dossier_items?f[dossier_id]={$d}&per_page=100", $h)->json('meta.total'))->toBe($res->json('data.total'));
    // Volver a armarlo no duplica.
    $this->postJson("/api/v1/sun/dossiers/{$d}/build", [], $h)->assertOk();
    expect($this->getJson("/api/v1/records/SUN/dossier_items?f[dossier_id]={$d}&per_page=100", $h)->json('meta.total'))->toBe($res->json('data.total'));
});
