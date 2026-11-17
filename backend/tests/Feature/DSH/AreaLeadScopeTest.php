<?php

// M-DSH: el jefe de área ve solo los indicadores de su área (y subáreas); los indicadores de toda la empresa no aplican.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Permissions::flush();
});

afterEach(function () {
    DB::select("select set_config('app.company_id', '', false)");
    app('auth')->forgetGuards();
    Permissions::flush();
});

it('acota los indicadores al área del jefe de área', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'DSA-S', 'name' => 'Sede área'], $h)->json('data.id');
    $area = $this->postJson('/api/v1/records/TEN/areas', ['site_id' => $site, 'code' => 'DSA-A', 'name' => 'Área del jefe'], $h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $uid = DB::selectOne('select id from app_auth_lookup_user(?)', [PROFESIONAL_EMAIL])->id;
    $role = DB::selectOne("select id from roles where account_id is null and code = 'area_lead'")->id;
    $m = DB::selectOne("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, ?, 'active', now()) returning id", [HUAYLAS_ID, $uid, $role])->id;
    DB::insert("insert into membership_scopes (company_id, membership_id, scope_type, area_id) values (?, ?, 'area', ?)", [HUAYLAS_ID, $m, $area]);
    DB::select("select set_config('app.company_id', '', false)");

    $lead = authHeaders(tokenFor(PROFESIONAL_EMAIL), HUAYLAS_ID);
    $d = $this->getJson('/api/v1/dsh/overview', $lead)->assertOk()->json('data');
    expect(collect($d['areas'])->pluck('id')->all())->toBe([$area]);
    $legal = collect($d['kpis'])->firstWhere('code', 'legal_compliance_pct');
    if ($legal) {
        expect($legal['label'])->toBe('No aplica');
    }
    app('auth')->forgetGuards();
    Permissions::flush();
    app()->forgetInstance(\App\Core\Tenancy\CompanyContext::class);
    expect($this->getJson('/api/v1/dsh/overview', $h)->assertOk()->json('data.areas'))->toBeNull();
});
