<?php

// M-POL RN-POL-01/02/08 y §11: principios del art. 23 obligatorios, aprobación solo por gerencia, resumen con % de cargos y verificación pública.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'POL' and deleted_at is null", [HUAYLAS_ID]);
    DB::select("select set_config('app.company_id', '', false)");
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

it('exige los principios del art. 23 para enviar la política y solo gerencia la aprueba', function () {
    $o = $this->getJson('/api/v1/pol/overview', $this->h)->assertOk()->json('data');
    expect($o['principles'])->toHaveCount(5)->and($o['can_approve'])->toBeFalse();

    $id = $this->postJson('/api/v1/records/POL/sst_policies', ['title' => 'Política RR', 'content_html' => '<p>Cuidamos la vida.</p>'], $this->h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/POL/sst_policies/{$id}", ['status' => 'in_review'], $this->h)->assertStatus(422)->assertJsonValidationErrors('principles_checklist');
    $this->patchJson("/api/v1/records/POL/sst_policies/{$id}", ['principles_checklist' => array_slice($o['principles'], 0, 4)], $this->h)->assertOk();
    $this->patchJson("/api/v1/records/POL/sst_policies/{$id}", ['status' => 'in_review'], $this->h)->assertStatus(422);
    $this->patchJson("/api/v1/records/POL/sst_policies/{$id}", ['principles_checklist' => $o['principles']], $this->h)->assertOk();
    $this->patchJson("/api/v1/records/POL/sst_policies/{$id}", ["status" => "in_review"], $this->h)->assertOk();
    // El Jefe SST (sin el permiso de aprobar la política) no la aprueba.
    $this->patchJson("/api/v1/records/POL/sst_policies/{$id}", ['status' => 'approved'], $this->h)->assertStatus(422)->assertJsonValidationErrors('status');

    $d = collect($this->getJson('/api/v1/pol/overview', $this->h)->json('data.drafts'))->firstWhere('id', $id);
    expect($d['principles_checklist'])->toHaveCount(5)->and($d['status'])->toBe('in_review');

    $v = $this->getJson('/api/v1/public/verify/policy/'.HUAYLAS_ID."/{$id}")->assertOk()->json('data');
    expect($v['title'])->toBe('Política RR')->and($v['result'])->toBe('not_current');
    expect($this->getJson('/api/v1/public/verify/policy/'.HUAYLAS_ID.'/01900000-0000-7000-8000-000000009999')->json('data.result'))->toBe('invalid');
});
