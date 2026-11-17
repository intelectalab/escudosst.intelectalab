<?php

// M-NOT SCR-NOT-01 / RN-NOT-03: reglas de alerta de la empresa por evento: anticipaciones (solo esos días), roles
// destinatarios y apagar un aviso, sin tocar código.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled', company_enabled = true where company_id = ? and module_code in ('ACR', 'NOT') and deleted_at is null", [HUAYLAS_ID]);
});

afterEach(function () {
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update('update notification_rules set deleted_at = now() where company_id = ? and deleted_at is null', [HUAYLAS_ID]);
});

it('lista los eventos y aplica la anticipación y los roles que fija la empresa', function () {
    $rules = collect($this->getJson('/api/v1/not/rules', $this->h)->assertOk()->json('data'));
    expect($rules->firstWhere('code', 'acr.due_soon'))->toMatchArray(['module' => 'ACR', 'customized' => false, 'is_active' => true]);

    $this->putJson('/api/v1/not/rules/acr.due_soon', ['is_active' => true, 'roles' => ['nope'], 'lead_days' => [2], 'channels' => ['in_app']], $this->h)->assertStatus(422);
    $this->putJson('/api/v1/not/rules/no.existe', ['is_active' => true, 'roles' => [], 'lead_days' => [], 'channels' => ['in_app']], $this->h)->assertNotFound();
    $this->putJson('/api/v1/not/rules/acr.due_soon', ['is_active' => true, 'roles' => ['sst_manager'], 'lead_days' => [2], 'channels' => ['in_app', 'email']], $this->h)
        ->assertOk()->assertJsonPath('data.lead_days', [2]);
    expect(collect($this->getJson('/api/v1/not/rules', $this->h)->json('data'))->firstWhere('code', 'acr.due_soon'))
        ->toMatchArray(['customized' => true, 'roles' => ['sst_manager'], 'lead_days' => [2]]);

    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $tag = substr(md5(uniqid()), 0, 6);
    $me = DB::connection('platform')->selectOne('select id from users where lower(email) = ?', [strtolower(CORPORATIVO_EMAIL)])->id;
    $in2 = DB::selectOne("insert into corrective_actions (company_id, code, source_type, title, due_on, status, responsible_user_id) values (?, ?, 'other', 'Regla 2 días', current_date + 2, 'open', ?) returning id", [HUAYLAS_ID, 'AC-R2-'.$tag, $me])->id;
    $in1 = DB::selectOne("insert into corrective_actions (company_id, code, source_type, title, due_on, status, responsible_user_id) values (?, ?, 'other', 'Regla 1 día', current_date + 1, 'open', ?) returning id", [HUAYLAS_ID, 'AC-R1-'.$tag, $me])->id;
    app(\App\Modules\NOT\SstAlerts::class)->forCompany(DB::connection(), HUAYLAS_ID, false);

    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $count = fn ($id) => (int) DB::selectOne("select count(*) as n from notifications where subject_id = ? and event_code = 'acr.due_soon'", [$id])->n;
    // Con la regla «2 días antes»: avisa la que vence en 2 días (al Jefe SST) y no la que vence mañana.
    expect($count($in2))->toBeGreaterThan(0)->and($count($in1))->toBe(0);

    // Apagar el aviso: no sale más.
    $this->putJson('/api/v1/not/rules/acr.due_soon', ['is_active' => false, 'roles' => [], 'lead_days' => [], 'channels' => ['in_app']], $this->h)->assertOk();
    DB::update('update corrective_actions set due_on = current_date + 2 where id = ?', [$in1]);
    app(\App\Modules\NOT\SstAlerts::class)->forCompany(DB::connection(), HUAYLAS_ID, false);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect($count($in1))->toBe(0);
});
