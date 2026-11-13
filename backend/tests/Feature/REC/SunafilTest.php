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

it('arma el expediente de un trabajador y lo comparte con un enlace que vence y se puede anular', function () {
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '45454545', 'first_name' => 'Pedro', 'paternal_last_name' => 'Inspeccionado'], $h)->json('data.id');
    $d = $this->postJson('/api/v1/records/SUN/inspection_dossiers', ['title' => 'Inspección a un trabajador', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31'], $h)->json('data.id');

    // Compartir antes de armar: no.
    $this->postJson("/api/v1/sun/dossiers/{$d}/share", ['days' => 5], $h)->assertStatus(409);
    $r = $this->postJson("/api/v1/sun/dossiers/{$d}/build", ['worker_id' => $w], $h)->assertOk();
    expect($r->json('data.worker.name'))->toBe('Pedro Inspeccionado')
        ->and(collect($r->json('data.items'))->pluck('code')->all())->toContain('W1', 'W3', 'W6', '16');

    $zip = $this->get("/api/v1/sun/dossiers/{$d}/zip", $h)->assertOk();
    expect($zip->headers->get('content-type'))->toContain('zip');
    $this->postJson("/api/v1/sun/dossiers/{$d}/share", ['days' => 999], $h)->assertStatus(422)->assertJsonValidationErrors('days');
    $share = $this->postJson("/api/v1/sun/dossiers/{$d}/share", ['days' => 5, 'max_views' => 2, 'recipient_name' => 'Inspector'], $h)->assertCreated()->json('data');
    approveShareLink($share['id'], ANDINAS_ID);
    [$company, $token] = array_slice(explode('/', $share['url']), -2);

    $this->getJson("/api/v1/public/sun/{$company}/{$token}")->assertOk()->assertJsonPath('data.views_left', 1)->assertJsonPath('data.dossier.title', 'Inspección a un trabajador');
    $this->getJson("/api/v1/public/sun/{$company}/{$token}")->assertOk()->assertJsonPath('data.views_left', 0);
    $this->getJson("/api/v1/public/sun/{$company}/{$token}")->assertStatus(410);

    $share2 = $this->postJson("/api/v1/sun/dossiers/{$d}/share", ['days' => 1], authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID))->json('data');
    approveShareLink($share2['id'], ANDINAS_ID);
    $this->postJson("/api/v1/sun/share-links/{$share2['id']}/revoke", [], authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID))->assertOk();
    [, $token2] = array_slice(explode('/', $share2['url']), -2);
    $this->getJson("/api/v1/public/sun/{$company}/{$token2}")->assertStatus(410);
});
