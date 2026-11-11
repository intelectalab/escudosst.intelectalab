<?php

// Bitácora de la empresa (SCR-AUD-01/03): filtros por usuario, módulo y fechas, desde dónde, antes/después de cada cambio
// y la pestaña de accesos a datos sensibles (sin contenido médico).

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function audH(): array
{
    return authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
}

it('filtra por usuario, módulo y fechas y muestra el antes/después y desde dónde', function () {
    $h = audH();
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'AUD-D', 'name' => 'Sede antes'], $h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/TEN/sites/{$site}", ['name' => 'Sede después'], $h)->assertOk();

    $f = $this->getJson('/api/v1/company/audit/filters', $h)->assertOk()->json('data');
    expect($f['can'])->toBe(['general' => true, 'sensitive' => false])
        ->and(collect($f['modules'])->pluck('code'))->toContain('TEN');
    $me = collect($f['users'])->first()['id'];

    $today = now('America/Lima')->toDateString();
    $rows = $this->getJson("/api/v1/company/audit?entity_id={$site}&module=TEN&user_id={$me}&from={$today}&to={$today}", $h)->assertOk()->json('data');
    expect($rows)->toHaveCount(2);
    $upd = collect($rows)->firstWhere('action', 'update');
    expect($upd['diff'])->toContain(['field' => collect($upd['diff'])->first()['field'], 'from' => 'Sede antes', 'to' => 'Sede después', 'hidden' => false])
        ->and($upd['ip'])->toEndWith('.xx.xx')
        ->and($upd)->toHaveKey('channel');

    $this->getJson("/api/v1/company/audit?entity_id={$site}&action=delete", $h)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/company/audit?entity_id={$site}&to=2001-01-01", $h)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/company/audit/sensitive', $h)->assertForbidden();
});

it('muestra los accesos a datos sensibles a quien tiene permiso, sin contenido médico', function () {
    $h = audH();
    $worker = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '70881122', 'first_name' => 'Rosa', 'paternal_last_name' => 'Sensible'], $h)->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $uid = DB::selectOne('select id from users where email = ?', [CORPORATIVO_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $role = DB::selectOne("select id from roles where account_id is null and code = 'external_auditor'")->id;
    DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, ?, 'active', now())", [ANDINAS_ID, $uid, $role]);
    DB::insert(
        "insert into sensitive_access_logs (company_id, user_id, role_code, worker_id, resource, action, purpose, result, ip, occurred_at)
         values (?, ?, 'occupational_doctor', ?, 'medical_exam', 'read', 'Control periódico', 'granted', '190.236.10.20', now()),
                (?, ?, 'sst_manager', ?, 'medical_exam', 'read', null, 'denied', null, now() - interval '1 minute')",
        [ANDINAS_ID, $uid, $worker, ANDINAS_ID, $uid, $worker]
    );
    DB::select("select set_config('app.company_id', '', false)");

    $h2 = authHeaders(tokenFor(CORPORATIVO_EMAIL), ANDINAS_ID);
    expect($this->getJson('/api/v1/company/audit/filters', $h2)->assertOk()->json('data.can.sensitive'))->toBeTrue();
    $rows = $this->getJson('/api/v1/company/audit/sensitive', $h2)->assertOk()->json('data');
    expect($rows[0])->toMatchArray(['data_label' => 'EMO de Rosa Sensible', 'purpose' => 'Control periódico', 'result' => 'granted', 'ip' => '190.236.xx.xx', 'reauth' => false])
        ->and($rows[0])->not->toHaveKey('resource_id');
    $this->getJson('/api/v1/company/audit/sensitive?result=denied', $h2)->assertOk()->assertJsonCount(1, 'data');
});
