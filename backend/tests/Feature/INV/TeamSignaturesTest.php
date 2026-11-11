<?php

// Investigación (M-INV): «Solicitar firmas» del equipo investigador por M-FIR y firmas en el informe imprimible.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Permissions::flush();
});

afterEach(function () {
    Permissions::flush();
});

function invSigInvestigation($t, array $h): string
{
    $site = collect($t->getJson('/api/v1/records/ACC/sst_events/options/site_id', $h)->json('data'))->first()['id'];
    $event = $t->postJson('/api/v1/records/ACC/sst_events', ['code' => 'ACC-FIRM-'.random_int(100, 999), 'event_type' => 'incident', 'site_id' => $site,
        'occurred_at' => now('America/Lima')->subDay()->toIso8601String(), 'description' => 'Resbalón en pasillo mojado'], $h)->assertCreated()->json('data.id');

    return $t->postJson('/api/v1/records/INV/investigations', ['event_id' => $event, 'method' => 'cause_tree', 'summary' => 'Resumen del informe'], $h)->assertCreated()->json('data.id');
}

it('pide la firma a cada integrante, se firma por M-FIR y el informe la muestra; los externos no se piden', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $inv = invSigInvestigation($this, $h);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $me = DB::selectOne('select id from users where email = ?', [CORPORATIVO_EMAIL])->id;
    $worker = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '70771234', 'first_name' => 'Pedro', 'paternal_last_name' => 'Equipo'], $h)->assertCreated()->json('data.id');

    $this->postJson("/api/v1/inv/investigations/{$inv}/signatures", [], $h)->assertStatus(409);   // sin equipo
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::insert("insert into investigation_team (company_id, investigation_id, user_id, role) values (?, ?, ?, 'leader')", [HUAYLAS_ID, $inv, $me]);
    DB::insert("insert into investigation_team (company_id, investigation_id, worker_id, role) values (?, ?, ?, 'worker_representative')", [HUAYLAS_ID, $inv, $worker]);
    DB::insert("insert into investigation_team (company_id, investigation_id, external_name, role) values (?, ?, 'Perito Externo', 'expert')", [HUAYLAS_ID, $inv]);

    $res = $this->postJson("/api/v1/inv/investigations/{$inv}/signatures", [], $h)->assertCreated();
    expect($res->json('data.requested'))->toBe(2)->and($res->json('data.skipped.external'))->toBe(1);
    $members = collect($res->json('data.members'))->keyBy('role');
    expect($members['leader']['state'])->toBe('pending')->and($members['leader']['is_me'])->toBeTrue()
        ->and($members['worker_representative']['state'])->toBe('pending')->and($members['expert']['state'])->toBe('external');
    // Repetir no duplica las solicitudes.
    $this->postJson("/api/v1/inv/investigations/{$inv}/signatures", [], $h)->assertOk()->assertJsonPath('data.requested', 0);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne("select count(*) as n from notifications where event_code = 'fir.signature_requested' and recipient_user_id = ? and subject_id = ?",
        [$me, $members['leader']['request_id']])->n)->toBe(1);

    // El líder firma con su contraseña; el informe muestra su firma y la del trabajador como pendiente.
    $this->postJson("/api/v1/fir/requests/{$members['leader']['request_id']}/sign", ['password' => DEMO_PASSWORD], $h)->assertOk();
    $team = collect($this->getJson("/api/v1/inv/investigations/{$inv}/report", $h)->assertOk()->json('data.team'))->keyBy('role');
    expect($team['leader']['signed_at'])->not->toBeNull()->and($team['leader']['pending'])->toBeFalse()
        ->and($team['worker_representative']['pending'])->toBeTrue()->and($team['expert']['pending'])->toBeFalse();
    $this->getJson("/api/v1/inv/investigations/{$inv}/signatures", $h)->assertOk()->assertJsonPath('data.members.0.state', 'signed');

    // La firma cubre el contenido del informe: sigue íntegra cuando la investigación avanza de estado.
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update investigations set status = 'analysis' where id = ?", [$inv]);
    $this->getJson("/api/v1/fir/verify/investigations/{$inv}", $h)->assertOk()->assertJsonPath('data.0.result', 'intact');
});

it('un rol sin permiso de pedir firmas no las pide', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $inv = invSigInvestigation($this, $h);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $uid = DB::selectOne('select id from users where email = ?', [CORPORATIVO_EMAIL])->id;
    $role = DB::selectOne("select id from roles where account_id is null and code = 'committee_member'")->id;
    DB::update('update memberships set role_id = ? where user_id = ? and company_id = ?', [$role, $uid, HUAYLAS_ID]);
    Permissions::flush();
    $this->postJson("/api/v1/inv/investigations/{$inv}/signatures", [], $h)->assertForbidden();
    $this->getJson("/api/v1/inv/investigations/{$inv}/signatures", $h)->assertOk()->assertJsonPath('data.can_request', false);
});
