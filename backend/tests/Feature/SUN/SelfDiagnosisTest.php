<?php

// M-SUN: autodiagnóstico guardado con gravedad por obligación y puntaje ponderado (RN-SUN-01/02), aviso de brecha muy grave
// (§10), posición por mes (§11) y copia congelada del expediente (RN-SUN-05, criterio 3).

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
});

afterEach(function () {
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    DB::update("update legal_parameters set deleted_at = now() where company_id = ? and key = 'sunafil_obligation_severity' and deleted_at is null", [ANDINAS_ID]);
});

it('guarda el autodiagnóstico con gravedad y puntaje ponderado y avisa la brecha muy grave', function () {
    $d = $this->getJson('/api/v1/sun/diagnosis', $this->h)->assertOk()->json('data');
    expect($d)->toHaveKeys(['risk_weighted_score', 'very_serious_gaps', 'checklist_version'])
        ->and($d['areas'][0])->toHaveKeys(['code', 'severity', 'severity_label', 'result']);

    // Un especialista marca como muy grave una obligación que hoy no se cumple.
    $fails = collect($d['areas'])->firstWhere('result', 'fails');
    if (! $fails) {
        $this->markTestSkipped('La empresa de prueba no tiene obligaciones incumplidas.');
    }
    companyParamForTests(ANDINAS_ID, 'sunafil_obligation_severity', json_encode([$fails['code'] => 'very_serious']), 'json');

    $s = $this->postJson('/api/v1/sun/self-diagnostics', [], $this->h)->assertCreated()->json('data');
    expect($s['very_serious_gaps'])->toBeGreaterThanOrEqual(1);
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    expect(DB::selectOne("select count(*) as n from notifications where subject_id = ? and event_code = 'sun.very_serious_gap'", [$s['id']])->n)->toBeGreaterThan(0);
    expect(DB::selectOne('select critical_gaps, status from self_diagnostics where id = ?', [$s['id']]))->critical_gaps->toBe($s['very_serious_gaps'])->status->toBe('completed');

    $m = collect($this->getJson('/api/v1/sun/self-diagnostics/monthly', $this->h)->assertOk()->json('data'));
    expect($m->firstWhere('month', now('America/Lima')->format('Y-m'))['id'])->toBe($s['id']);

    $this->getJson("/api/v1/sun/self-diagnostics/{$s['id']}", $this->h)->assertOk()->assertJsonPath('data.checklist_version', $s['checklist_version'])
        ->assertJsonStructure(['data' => ['company' => ['name'], 'areas']]);
});

it('el expediente conserva la copia congelada aunque cambie el registro de origen', function () {
    $d = $this->postJson('/api/v1/records/SUN/inspection_dossiers', ['title' => 'Expediente congelado', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31'], $this->h)->json('data.id');
    sunBuild($d, [], $this->h);
    $item = $this->getJson("/api/v1/sun/dossiers/{$d}/items", $this->h)->assertOk()->json('data.0');

    // Un ítem que apunta a un registro real (la empresa de prueba casi no tiene datos): se congela igual que al armar.
    $rep = $this->postJson('/api/v1/records/ACT/substandard_reports', ['report_type' => 'suggestion', 'description' => 'Registro del expediente'], $this->h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    [$snap, $hash] = \App\Modules\SUN\DossierBuilder::snapshot('substandard_reports', $rep);
    expect($hash)->toBe(hash('sha256', $snap));
    DB::update("update dossier_items set source_type = 'substandard_reports', source_id = ?, snapshot = ?::jsonb, snapshot_hash = ?, frozen_at = now() where id = ?", [$rep, $snap, $hash, $item['id']]);

    $frozen = collect($this->getJson("/api/v1/sun/dossiers/{$d}/items", $this->h)->json('data'))->firstWhere('id', $item['id']);
    expect($frozen['snapshot_hash'])->toBe($hash)->and($frozen['source_changed'])->toBeFalse();

    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $before = DB::selectOne('select snapshot::text as s from dossier_items where id = ?', [$item['id']])->s;
    DB::update("update substandard_reports set description = 'Cambiado después de armar' where id = ?", [$rep]);

    $after = collect($this->getJson("/api/v1/sun/dossiers/{$d}/items", $this->h)->json('data'))->firstWhere('id', $item['id']);
    expect($after['source_changed'])->toBeTrue();
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    expect(DB::selectOne('select snapshot::text as s from dossier_items where id = ?', [$item['id']])->s)->toBe($before);
});
