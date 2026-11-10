<?php

// SCTR por fecha, ingreso evaluado con el fotocheck, mapa de riesgos con versiones y firma solo del firmante.

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function ad(): array
{
    return authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
}

it('niega el ingreso sin fotocheck y permite autorizar por excepción con motivo', function () {
    $h = ad();
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'ING-S', 'name' => 'Sede garita'], $h)->json('data.id');
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '94949494', 'first_name' => 'Noé', 'paternal_last_name' => 'Cruz'], $h)->json('data.id');
    $pt = $this->postJson('/api/v1/records/ING/access_points', ['site_id' => $site, 'code' => 'G1', 'name' => 'Garita principal', 'point_type' => 'main_gate'], $h)
        ->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/ING/access_events', ['access_point_id' => $pt, 'direction' => 'in', 'subject_type' => 'worker', 'worker_id' => $w], $h)
        ->assertCreated()->assertJsonPath('data.result', 'denied')->assertJsonPath('data.deny_reasons.0', 'Sin fotocheck');
    $this->postJson('/api/v1/records/ING/access_events', ['access_point_id' => $pt, 'direction' => 'in', 'subject_type' => 'worker', 'worker_id' => $w, 'result' => 'overridden'], $h)
        ->assertStatus(422)->assertJsonPath('errors.override_reason.0', 'Para autorizar por excepción indica el motivo.');
});

it('marca la póliza SCTR vencida o por vencer según su fecha', function () {
    $h = ad();
    $p = $this->postJson('/api/v1/records/SCT/sctr_policies', ['coverage_kind' => 'health', 'insurer_type' => 'essalud', 'insurer_name' => 'EsSalud', 'policy_number' => 'P-1',
        'starts_on' => now()->subYear()->toDateString(), 'ends_on' => now()->addDays(10)->toDateString()], $h);
    expect($p->status())->toBe(201, (string) json_encode($p->json()));
    expect($p->json('data.status'))->toBe('expiring');
});
