<?php

// Firma: reenviar recordatorio (SCR-FIR-03) y verificación de integridad «Íntegro / Alterado» (SCR-FIR-04, RN-FIR-02).

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function firH(): array
{
    return authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
}

it('reenvía el recordatorio de una firma pendiente con límite de frecuencia y lo cuenta', function () {
    $h = firH();
    $me = collect($this->getJson('/api/v1/records/ACR/corrective_actions/options/responsible_user_id', $h)->json('data'))->first()['id'];
    $doc = $this->postJson('/api/v1/records/ACT/substandard_reports', ['report_type' => 'suggestion', 'description' => 'FIR recordatorio'], $h)->json('data.id');
    $req = $this->postJson('/api/v1/fir/requests', ['subject_table' => 'substandard_reports', 'subject_id' => $doc, 'signer_user_id' => $me, 'role_in_document' => 'responsible'], $h)
        ->assertCreated()->json('data.id');

    $p = collect($this->getJson('/api/v1/fir/pending', $h)->assertOk()->json('data'))->firstWhere('id', $req);
    expect($p['can_remind'])->toBeTrue()->and($p['subject_tab'])->not->toBeEmpty();

    $this->postJson("/api/v1/fir/requests/{$req}/remind", [], $h)->assertOk()->assertJsonPath('data.reminder_count', 1);
    $this->postJson("/api/v1/fir/requests/{$req}/remind", [], $h)->assertStatus(429);
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    expect(DB::selectOne("select count(*) as n from notifications where subject_id = ? and event_code = 'fir.signature_reminder' and recipient_user_id = ?", [$req, $me])->n)->toBe(1);

    $this->postJson("/api/v1/fir/requests/{$req}/sign", ['password' => DEMO_PASSWORD], $h)->assertOk();
    DB::update("update notifications set created_at = now() - interval '2 days' where subject_id = ?", [$req]);
    $this->postJson("/api/v1/fir/requests/{$req}/remind", [], $h)->assertStatus(409);
});

it('verifica la integridad: íntegro mientras el registro no cambie, alterado si cambia; también por código', function () {
    $h = firH();
    $me = collect($this->getJson('/api/v1/records/ACR/corrective_actions/options/responsible_user_id', $h)->json('data'))->first()['id'];
    $doc = $this->postJson('/api/v1/records/ACT/substandard_reports', ['report_type' => 'suggestion', 'description' => 'FIR verificación'], $h)->json('data.id');
    $req = $this->postJson('/api/v1/fir/requests', ['subject_table' => 'substandard_reports', 'subject_id' => $doc, 'signer_user_id' => $me, 'role_in_document' => 'approver'], $h)->json('data.id');
    $this->postJson("/api/v1/fir/requests/{$req}/sign", ['password' => DEMO_PASSWORD], $h)->assertOk();

    $v = $this->getJson("/api/v1/fir/verify/substandard_reports/{$doc}", $h)->assertOk()->json('data.0');
    expect($v)->toMatchArray(['result' => 'intact', 'method' => 'pin', 'role_in_document' => 'approver'])
        ->and($v['code'])->toMatch('/^[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}$/')
        ->and($v['signed_hash'])->toBe($v['current_hash']);

    $this->getJson('/api/v1/fir/verify?code='.$v['code'], $h)->assertOk()->assertJsonPath('data.signature_id', $v['signature_id'])->assertJsonPath('data.result', 'intact');
    $this->getJson('/api/v1/fir/verify?code=12', $h)->assertStatus(422);
    $this->getJson('/api/v1/fir/verify?code=0000-0000-0000', $h)->assertNotFound();

    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    DB::update("update substandard_reports set description = 'Cambiado después de firmar' where id = ?", [$doc]);
    $this->getJson("/api/v1/fir/verify/substandard_reports/{$doc}", $h)->assertOk()->assertJsonPath('data.0.result', 'altered');
});
