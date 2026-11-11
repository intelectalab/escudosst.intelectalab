<?php

// Accidentes e incidentes (M-ACC §10, RN-ACC-04): al registrar un evento se avisa según su gravedad; mortal o incidente
// peligroso: Jefe SST, gerencia y comité con prioridad crítica; también al jefe del área. No a quien lo registró.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Permissions::flush();
});

function accnSetup(object $t, array $h): array
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $ids = [];
    foreach ([[PROFESIONAL_EMAIL, 'management'], [ESENCIAL_EMAIL, 'committee_member']] as [$email, $code]) {
        $uid = DB::selectOne('select id from app_auth_lookup_user(?)', [$email])->id;
        $role = DB::selectOne('select id from roles where account_id is null and code = ?', [$code])->id;
        DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, ?, 'active', now())", [HUAYLAS_ID, $uid, $role]);
        $ids[$code] = $uid;
    }
    $site = $t->postJson('/api/v1/records/TEN/sites', ['code' => 'ACCN-S', 'name' => 'Planta avisos'], $h)->assertCreated()->json('data.id');
    $area = $t->postJson('/api/v1/records/TEN/areas', ['site_id' => $site, 'code' => 'ACCN-A', 'name' => 'Área avisos'], $h)->assertCreated()->json('data.id');
    // El jefe del área es un trabajador con usuario (el de gerencia en esta prueba).
    $lead = $t->postJson('/api/v1/records/TRB/workers', ['document_number' => '68686868', 'first_name' => 'Jefe', 'paternal_last_name' => 'Área'], $h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update('update workers set user_id = ? where id = ?', [$ids['management'], $lead]);
    DB::update('update areas set manager_worker_id = ? where id = ?', [$lead, $area]);
    DB::select("select set_config('app.company_id', '', false)");

    return [$site, $area, $ids];
}

function accnEvent(object $t, array $h, string $type, string $site, ?string $area = null): string
{
    return $t->postJson('/api/v1/records/ACC/sst_events', array_filter(['code' => 'ACC-N-'.random_int(1000, 9999), 'event_type' => $type, 'site_id' => $site, 'area_id' => $area,
        'occurred_at' => now('America/Lima')->subHour()->toIso8601String(), 'description' => 'Evento de prueba de avisos']), $h)->assertCreated()->json('data.id');
}

function accnTo(string $event): array
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);

    return collect(DB::select("select recipient_user_id, priority from notifications where event_code = 'acc.event_registered' and subject_id = ?", [$event]))
        ->pluck('priority', 'recipient_user_id')->all();
}

it('un accidente mortal avisa de inmediato con prioridad crítica a gerencia, comité y jefe de área, no a quien lo registró', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    [$site, $area, $ids] = accnSetup($this, $h);
    $me = DB::selectOne('select id from app_auth_lookup_user(?)', [CORPORATIVO_EMAIL])->id;

    $to = accnTo(accnEvent($this, $h, 'fatal_accident', $site, $area));
    expect($to)->toHaveKey($ids['management'])->toHaveKey($ids['committee_member'])->not->toHaveKey($me)
        ->and($to[$ids['committee_member']])->toBe('critical');
    $body = DB::selectOne("select body from notifications where event_code = 'acc.event_registered' and recipient_user_id = ? order by created_at desc limit 1", [$ids['committee_member']])->body;
    expect($body)->toContain('Accidente mortal')->toContain('Planta avisos');
});

it('un incidente leve avisa al jefe de área pero no a gerencia ni comité; al reclasificarlo a peligroso se avisa al comité', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    [$site, $area, $ids] = accnSetup($this, $h);

    $ev = accnEvent($this, $h, 'incident', $site, $area);
    $to = accnTo($ev);
    expect($to)->toHaveKey($ids['management'])   // como jefe del área
        ->not->toHaveKey($ids['committee_member'])
        ->and($to[$ids['management']])->toBe('normal');

    $noArea = accnTo(accnEvent($this, $h, 'minor_accident', $site));
    expect($noArea)->not->toHaveKey($ids['management'])->not->toHaveKey($ids['committee_member']);

    $this->patchJson("/api/v1/records/ACC/sst_events/{$ev}", ['event_type' => 'dangerous_incident'], $h)->assertOk();
    expect(accnTo($ev))->toHaveKey($ids['committee_member']);
});
