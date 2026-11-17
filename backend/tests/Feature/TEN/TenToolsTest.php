<?php

// M-TEN: cambio de modelo SST confirmado por una persona (RN-TEN-03), empresas de la cuenta, estructura en Excel y organigrama SST.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Permissions::flush();
});

afterEach(function () {
    DB::select("select set_config('app.company_id', '', false)");
});

it('propone el modelo SST, lo cambia solo al confirmarlo y muestra estructura y organigrama', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $m = $this->getJson('/api/v1/ten/sst-model', $h)->assertOk()->json('data');
    expect($m)->toHaveKeys(['model', 'workers', 'threshold', 'suggested']);
    $other = $m['model'] === 'committee' ? 'supervisor' : 'committee';
    $this->postJson('/api/v1/ten/sst-model', ['model' => $other, 'reason' => 'Prueba'], $h)->assertOk()->assertJsonPath('data.model', $other);
    $this->postJson('/api/v1/ten/sst-model', ['model' => $m['model']], $h)->assertOk();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne("select count(*) as n from audit_logs where entity_type = 'companies' and changes->'sst_model' is not null")->n)->toBeGreaterThanOrEqual(2);
    DB::select("select set_config('app.company_id', '', false)");
    $this->get('/api/v1/ten/structure.xlsx', $h)->assertOk();
    $this->getJson('/api/v1/ten/sst-org', $h)->assertOk()->assertJsonStructure(['data' => ['company', 'sst_managers', 'members', 'brigades']]);
    $this->getJson('/api/v1/ten/account-companies', $h)->assertOk()->assertJsonStructure(['data', 'active']);
});
