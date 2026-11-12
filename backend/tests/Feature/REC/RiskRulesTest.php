<?php

// Reglas de agentes ocupacionales, gestión del cambio y ergonomía según sus fichas.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->me = DB::connection('platform')->selectOne('select id from users where email = ?', [CORPORATIVO_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('MAG', 'MOC', 'PSI', 'ACR') and deleted_at is null", [HUAYLAS_ID]);
    \App\Core\Auth\Permissions::flush();
});

it('la medición toma el límite vigente, rechaza otra unidad, marca el nivel de acción y sobre el límite crea su acción', function () {
    $agent = collect($this->getJson('/api/v1/records/MAG/measurements/options/agent_type_id?q=ruido', $this->h)->json('data'))->first()['id'] ?? null;
    $limit = $this->postJson('/api/v1/records/MAG/exposure_limits', ['agent_type_id' => $agent, 'substance_name' => 'Ruido', 'limit_type' => 'TWA', 'limit_value' => 85, 'unit' => 'dB(A)',
        'valid_from' => '2020-01-01'], $this->h);
    if ($limit->status() !== 201) {
        $this->markTestSkipped('Límites de exposición de solo catálogo en esta empresa.');
    }
    $site = DB::selectOne('select id from sites where company_id = ? and deleted_at is null limit 1', [HUAYLAS_ID])->id;
    $camp = $this->postJson('/api/v1/records/MAG/monitoring_campaigns', ['title' => 'Monitoreo de ruido', 'responsible_user_id' => $this->me], $this->h)->assertCreated()->json('data.id');
    $point = $this->postJson('/api/v1/records/MAG/monitoring_points', ['site_id' => $site, 'agent_type_id' => $agent, 'code' => 'P-'.substr(uniqid(), -4), 'name' => 'Prensa'], $this->h)
        ->assertCreated()->json('data.id');
    $base = ['campaign_id' => $camp, 'monitoring_point_id' => $point, 'agent_type_id' => $agent, 'measured_at' => now()->toIso8601String()];

    $this->postJson('/api/v1/records/MAG/measurements', $base + ['value' => 80, 'unit' => 'dB', 'exposure_limit_id' => $limit->json('data.id')], $this->h)
        ->assertStatus(422)->assertJsonValidationErrors('unit');
    $this->postJson('/api/v1/records/MAG/measurements', $base + ['value' => 60, 'unit' => 'dB(A)', 'exposure_limit_id' => $limit->json('data.id')], $this->h)
        ->assertCreated()->assertJsonPath('data.result', 'action_level');
    $over = $this->postJson('/api/v1/records/MAG/measurements', $base + ['value' => 92, 'unit' => 'dB(A)', 'exposure_limit_id' => $limit->json('data.id')], $this->h)
        ->assertCreated()->assertJsonPath('data.result', 'exceeds_limit')->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $a = DB::selectOne('select c.source_type, c.priority, c.responsible_user_id from measurements m join corrective_actions c on c.id = m.corrective_action_id where m.id = ?', [$over]);
    expect($a->source_type)->toBe('monitoring')->and($a->priority)->toBe('high')->and($a->responsible_user_id)->toBe($this->me);

    // RN-MAG-05: la campaña se cierra con su informe.
    $this->patchJson("/api/v1/records/MAG/monitoring_campaigns/{$camp}", ['status' => 'closed'], $this->h)->assertStatus(422)->assertJsonValidationErrors('report_file_id');
});

it('el cambio temporal pide fecha de fin y sus condiciones se vuelven acciones que impiden cerrarlo', function () {
    if ($this->getJson('/api/v1/records/MOC', $this->h)->status() !== 200) {
        $this->markTestSkipped('MOC no está contratado por la empresa de prueba.');
    }
    $this->postJson('/api/v1/records/MOC/change_requests', ['title' => 'Andamio provisional', 'change_type' => 'facility', 'description' => 'Acceso temporal', 'is_temporary' => true], $this->h)
        ->assertStatus(422)->assertJsonValidationErrors('temporary_until');
    $id = $this->postJson('/api/v1/records/MOC/change_requests', ['title' => 'Nueva prensa', 'change_type' => 'equipment', 'description' => 'Se cambia la prensa',
        'introduces_new_hazards' => true], $this->h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/MOC/change_requests/{$id}", ['status' => 'submitted'], $this->h)->assertOk();
    $this->patchJson("/api/v1/records/MOC/change_requests/{$id}", ['status' => 'under_review'], $this->h)->assertOk();
    $this->postJson('/api/v1/records/MOC/change_reviews', ['change_request_id' => $id, 'reviewer_user_id' => $this->me, 'decision' => 'approve_with_conditions',
        'conditions' => 'Instalar guarda en la prensa'], $this->h)->assertCreated();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne("select count(*) as n from corrective_actions where source_type = 'change' and source_id = ?", [$id])->n)->toBe(1);
    $this->patchJson("/api/v1/records/MOC/change_requests/{$id}", ['status' => 'approved'], $this->h)->assertOk();
    // Introduce peligros nuevos: se implementa con el IPERC actualizado.
    $this->patchJson("/api/v1/records/MOC/change_requests/{$id}", ['status' => 'implemented', 'risk_assessment' => ['nota' => 'ok']], $this->h)
        ->assertStatus(422)->assertJsonValidationErrors('iperc_version_id');
});

it('una evaluación ergonómica de riesgo alto genera su acción correctiva', function () {
    if ($this->getJson('/api/v1/records/PSI', $this->h)->status() !== 200) {
        $this->markTestSkipped('PSI no está contratado por la empresa de prueba.');
    }
    $id = $this->postJson('/api/v1/records/PSI/ergonomic_assessments', ['method' => 'REBA', 'assessed_on' => now()->toDateString(), 'recommendations' => 'Mesa regulable'], $this->h)
        ->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/PSI/ergonomic_assessments/{$id}", ['status' => 'completed'], $this->h)->assertStatus(422)->assertJsonValidationErrors('risk_level');
    $this->patchJson("/api/v1/records/PSI/ergonomic_assessments/{$id}", ['status' => 'completed', 'risk_level' => 'very_high', 'score' => 12], $this->h)->assertOk();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne('select c.priority from ergonomic_assessments e join corrective_actions c on c.id = e.corrective_action_id where e.id = ?', [$id])->priority)->toBe('critical');
});
