<?php

// M-POL RN-POL-06/07: meta ligada a un indicador (valor del último snapshot, no editable) y umbral ámbar de la empresa.
// M-SUS SCR-SUS-05: «Mi suscripción» con precios «Por definir» y «Solicitar mejora».

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Permissions::flush();
});

afterEach(function () {
    DB::select("select set_config('app.company_id', '', false)");
    DB::connection('platform')->delete("delete from legal_parameters where company_id = ? and key = 'objective_amber_pct'", [HUAYLAS_ID]);
    app('auth')->forgetGuards();
    Permissions::flush();
});

it('la meta ligada a un indicador toma su valor del indicador y el umbral ámbar es de la empresa', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    currentPolicy(HUAYLAS_ID);
    $kpi = DB::selectOne("select id from kpi_definitions where deleted_at is null and is_active order by code limit 1")->id;
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::insert("insert into kpi_snapshots (company_id, kpi_definition_id, period_start, period_end, value, computed_at) values (?, ?, date_trunc('month', now())::date, current_date, 6, now())", [HUAYLAS_ID, $kpi]);
    DB::select("select set_config('app.company_id', '', false)");
    $obj = $this->postJson('/api/v1/records/POL/sst_objectives', ['year' => (int) date('Y'), 'code' => 'OBJ-KPI', 'title' => 'Objetivo con indicador', 'indicator_kpi_id' => $kpi], $h)
        ->assertCreated()->json('data.id');
    $t = $this->postJson('/api/v1/records/POL/objective_targets', ['objective_id' => $obj, 'period_label' => 'Año', 'target_value' => 10], $h)->assertCreated()->json('data');
    expect((float) $t['actual_value'])->toEqual(6.0)->and($t['status'])->toBe('at_risk');
    $this->patchJson("/api/v1/records/POL/objective_targets/{$t['id']}", ['actual_value' => 9], $h)->assertStatus(422)->assertJsonValidationErrors('actual_value');

    $this->putJson('/api/v1/pol/settings', ['objective_amber_pct' => 50], $h)->assertOk()->assertJsonPath('data.objective_amber_pct', 50);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne('select status from objective_targets where id = ?', [$t['id']])->status)->toBe('on_track');
});

it('«Mi suscripción» muestra el plan con precio por definir y envía la solicitud de mejora', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $d = $this->getJson('/api/v1/me/subscription/detail', $h)->assertOk()->json('data');
    expect($d['plan']['price'])->toBe('Por definir')->and($d['modules'])->not->toBeEmpty();
    $this->postJson('/api/v1/me/subscription/upgrade', ['module_code' => 'API'], $h)->assertCreated()->assertJsonPath('data.status', 'open');
    expect(DB::connection('platform')->selectOne("select subject from support_tickets where topic = 'billing' order by created_at desc limit 1")->subject)->toContain('Solicitud de mejora');
});
