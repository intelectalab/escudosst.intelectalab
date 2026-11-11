<?php

// Portería (M-ING §4): solo para quien opera la garita (permiso ing.access_events.create). Quien solo consulta
// eventos (RR.HH., gerencia…) no entra a la portería; el operador con alcance por sede solo usa los puntos de su sede.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Permissions::flush();
});

afterEach(function () {
    Permissions::flush();
});

/** Membresía del usuario del plan Profesional en la empresa de prueba con el rol indicado. */
function gaMember(string $role): string
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $uid = DB::selectOne('select id from app_auth_lookup_user(?)', [PROFESIONAL_EMAIL])->id;
    $rid = DB::selectOne('select id from roles where account_id is null and code = ?', [$role])->id;

    return DB::selectOne("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, ?, 'active', now()) returning id", [HUAYLAS_ID, $uid, $rid])->id;
}

function gaPoints($t, array $h): array
{
    $s1 = $t->postJson('/api/v1/records/TEN/sites', ['code' => 'GA-S1', 'name' => 'Sede Norte garita'], $h)->assertCreated()->json('data.id');
    $s2 = $t->postJson('/api/v1/records/TEN/sites', ['code' => 'GA-S2', 'name' => 'Sede Sur garita'], $h)->assertCreated()->json('data.id');
    $p1 = $t->postJson('/api/v1/records/ING/access_points', ['site_id' => $s1, 'code' => 'GA-P1', 'name' => 'Garita Norte', 'point_type' => 'main_gate'], $h)->assertCreated()->json('data.id');
    $p2 = $t->postJson('/api/v1/records/ING/access_points', ['site_id' => $s2, 'code' => 'GA-P2', 'name' => 'Garita Sur', 'point_type' => 'main_gate'], $h)->assertCreated()->json('data.id');

    return [$s1, $s2, $p1, $p2];
}

it('no deja entrar a la portería a quien solo consulta los ingresos', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    [, , $p1] = gaPoints($this, $h);
    gaMember('hr');
    $hr = authHeaders(tokenFor(PROFESIONAL_EMAIL), HUAYLAS_ID);

    $perms = $this->getJson('/api/v1/me/permissions', $hr)->assertOk()->json('data');
    expect($perms)->toContain('ing.access_events.read')->not->toContain('ing.access_events.create');
    $this->getJson('/api/v1/ing/gate', $hr)->assertForbidden();
    $this->postJson('/api/v1/ing/gate/scan', ['access_point_id' => $p1, 'code' => '12345678'], $hr)->assertForbidden();
    $this->postJson('/api/v1/ing/gate/visitor', ['access_point_id' => $p1, 'name' => 'Visita', 'document_number' => '12345678'], $hr)->assertForbidden();
    $this->postJson('/api/v1/ing/gate/exit', ['access_point_id' => $p1, 'event_id' => '01900000-0000-7000-8000-000000000999'], $hr)->assertForbidden();
    // Sí consulta quiénes están dentro.
    $this->getJson('/api/v1/ing/inside', $hr)->assertOk();
});

it('el supervisor SST opera la portería solo en los puntos de su sede', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    [$s1, , $p1, $p2] = gaPoints($this, $h);
    $m = gaMember('sst_supervisor');
    $sup = authHeaders(tokenFor(PROFESIONAL_EMAIL), HUAYLAS_ID);

    // Sin alcance por sede: todos los puntos de la empresa.
    $all = collect($this->getJson('/api/v1/ing/gate', $sup)->assertOk()->json('data.points'))->pluck('id');
    expect($all)->toContain($p1)->toContain($p2);

    // Con alcance a la Sede Norte: solo su garita.
    DB::insert("insert into membership_scopes (company_id, membership_id, scope_type, site_id) values (?, ?, 'site', ?)", [HUAYLAS_ID, $m, $s1]);
    $mine = collect($this->getJson('/api/v1/ing/gate', $sup)->assertOk()->json('data.points'))->pluck('id');
    expect($mine)->toContain($p1)->not->toContain($p2);
    $this->postJson('/api/v1/ing/gate/scan', ['access_point_id' => $p2, 'code' => '12345678'], $sup)->assertStatus(422)->assertJsonValidationErrors('access_point_id');
    $this->postJson('/api/v1/ing/gate/scan', ['access_point_id' => $p1, 'code' => 'NO-EXISTE-0001'], $sup)->assertOk()->assertJsonPath('data.result', 'unknown');
});
