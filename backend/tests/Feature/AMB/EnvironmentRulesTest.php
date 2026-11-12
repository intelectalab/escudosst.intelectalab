<?php

// Medio ambiente (M-AMB): significancia calculada con el umbral de parámetros (RN-AMB-01), controles obligatorios en
// aspectos significativos (RN-AMB-03), manifiesto y EO-RS para residuos peligrosos (RN-AMB-02), registros cerrados que no
// se editan (RN-AMB-05) y acción correctiva desde un aspecto (§12). Asistente IA: cupo mensual y consumo (RN-IAA-06).

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('AMB', 'IAA', 'ACR') and deleted_at is null", [HUAYLAS_ID]);
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->site = DB::selectOne('select id from sites where company_id = ? order by is_main desc limit 1', [HUAYLAS_ID])->id;
});

it('calcula si un aspecto es significativo y exige sus controles', function () {
    $base = ['activity' => 'Mantenimiento de maquinaria', 'aspect' => 'Derrame de aceite', 'impact' => 'Contaminación del suelo', 'site_id' => $this->site];
    $this->postJson('/api/v1/records/AMB/environmental_aspects', $base + ['severity_value' => 3, 'probability_value' => 4], $this->h)
        ->assertStatus(422)->assertJsonValidationErrors('controls');

    $id = $this->postJson('/api/v1/records/AMB/environmental_aspects', $base + ['severity_value' => 3, 'probability_value' => 4,
        'controls' => 'Bandejas antiderrame y kit de contención', 'is_significant' => false], $this->h)->assertCreated()->json('data.id');
    expect($this->getJson("/api/v1/records/AMB/environmental_aspects/{$id}", $this->h)->json('data.is_significant'))->toBeTrue();

    $low = $this->postJson('/api/v1/records/AMB/environmental_aspects', $base + ['aspect' => 'Consumo de papel', 'severity_value' => 1, 'probability_value' => 2], $this->h)
        ->assertCreated()->json('data.id');
    expect($this->getJson("/api/v1/records/AMB/environmental_aspects/{$low}", $this->h)->json('data.is_significant'))->toBeFalse();

    // §12: el aspecto genera su acción correctiva, con trazabilidad.
    $this->postJson("/api/v1/acr/from/environmental_aspects/{$id}", ['title' => 'Instalar bandejas en el taller', 'due_on' => now()->addDays(10)->toDateString(),
        'responsible_user_id' => DB::selectOne('select id from users where email = ?', [CORPORATIVO_EMAIL])->id], $this->h)->assertSuccessful();
    expect(DB::selectOne("select count(*) as n from corrective_actions where source_type = 'environmental_aspect' and source_id = ?", [$id])->n)->toBe(1);
});

it('un residuo peligroso no se transfiere sin manifiesto ni EO-RS y, dispuesto, ya no se edita', function () {
    $id = $this->postJson('/api/v1/records/AMB/waste_records', ['site_id' => $this->site, 'waste_type' => 'hazardous', 'description' => 'Trapos con aceite',
        'quantity' => 12, 'unit' => 'kg', 'generated_on' => now()->subDays(5)->toDateString()], $this->h)->assertCreated()->json('data.id');

    $this->patchJson("/api/v1/records/AMB/waste_records/{$id}", ['status' => 'transferred'], $this->h)
        ->assertStatus(422)->assertJsonValidationErrors(['manifest_number']);
    $this->patchJson("/api/v1/records/AMB/waste_records/{$id}", ['status' => 'disposed', 'manifest_number' => 'MRSP-2026-0081',
        'eo_rs_name' => 'Gestión Ambiental Andina S.A.C.', 'eo_rs_ruc' => '20512345678'], $this->h)->assertOk();
    $this->patchJson("/api/v1/records/AMB/waste_records/{$id}", ['quantity' => 15], $this->h)->assertStatus(409);
});

it('el Asistente IA respeta el cupo mensual del plan y muestra el consumo', function () {
    $plan = DB::connection('platform')->selectOne("select p.id, p.ai_requests_month from subscriptions s join plans p on p.id = s.plan_id where s.company_id = ? and s.deleted_at is null order by s.started_at desc limit 1", [HUAYLAS_ID]);
    $used = (int) DB::selectOne("select count(*) as n from ai_requests where created_at >= date_trunc('month', now() at time zone 'America/Lima') and deleted_at is null")->n;
    DB::connection('platform')->update('update plans set ai_requests_month = ? where id = ?', [$used + 1, $plan->id]);
    try {
        $this->postJson('/api/v1/iaa/suggest', ['feature' => 'controls', 'text' => 'trabajo en altura en andamio'], $this->h)->assertOk();
        $this->postJson('/api/v1/iaa/suggest', ['feature' => 'controls', 'text' => 'trabajo en altura en andamio'], $this->h)
            ->assertStatus(429)->assertJsonPath('type', 'ai_limit_reached');
        $u = $this->getJson('/api/v1/iaa/usage', $this->h)->assertOk()->json('data');
        expect($u['used'])->toBe($used + 1)->and($u['limit'])->toBe($used + 1)->and($u['months'])->not->toBeEmpty();
    } finally {
        DB::connection('platform')->update('update plans set ai_requests_month = ? where id = ?', [$plan->ai_requests_month, $plan->id]);
    }
});
