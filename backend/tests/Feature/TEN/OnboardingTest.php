<?php

use Illuminate\Support\Facades\DB;

// Primeros pasos de la empresa (docs/07 §3.3, M-TEN): avance, datos generales y «ocultar».

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

it('muestra el avance de los primeros pasos y guarda los datos de la empresa', function () {
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $r = $this->getJson('/api/v1/ten/onboarding', $h)->assertOk();
    expect(collect($r->json('data.steps'))->pluck('code')->all())->toBe(['company', 'sites', 'positions', 'workers', 'users', 'baseline'])
        ->and($r->json('data.company.ruc'))->not->toBeNull()
        ->and($r->json('data.counts'))->toHaveKeys(['sites', 'areas', 'positions', 'workers', 'users', 'assessments']);

    $this->patchJson('/api/v1/ten/company', ['ciiu_code' => '12'], $h)->assertStatus(422)->assertJsonValidationErrors('ciiu_code');
    $this->patchJson('/api/v1/ten/company', ['economic_sector' => 'nuclear'], $h)->assertStatus(422);
    $u = $this->patchJson('/api/v1/ten/company', ['trade_name' => 'Andinas Primeros Pasos', 'economic_sector' => 'manufacturing', 'risk_level' => 'high',
        'fiscal_address' => 'Av. Industrial 123, Ate', 'ciiu_code' => '2511', 'headcount_declared' => 40], $h)->assertOk();
    expect($u->json('data.company.trade_name'))->toBe('Andinas Primeros Pasos')
        ->and(collect($u->json('data.steps'))->firstWhere('code', 'company')['done'])->toBeTrue();
    expect(DB::selectOne("select count(*) as n from audit_logs where entity_type = 'companies' and entity_id = ?", [ANDINAS_ID])->n)->toBeGreaterThan(0);

    $d = $this->postJson('/api/v1/ten/onboarding/dismiss', [], $h)->assertOk();
    expect($d->json('data.dismissed_at'))->not->toBeNull();
});

it('una sede creada marca el paso de sedes', function () {
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $before = $this->getJson('/api/v1/ten/onboarding', $h)->json('data.counts.sites');
    $this->postJson('/api/v1/records/TEN/sites', ['code' => 'ONB-1', 'name' => 'Sede de primeros pasos'], $h)->assertCreated();
    $after = $this->getJson('/api/v1/ten/onboarding', $h)->assertOk();
    expect($after->json('data.counts.sites'))->toBe($before + 1)
        ->and(collect($after->json('data.steps'))->firstWhere('code', 'sites')['done'])->toBeTrue();
});
