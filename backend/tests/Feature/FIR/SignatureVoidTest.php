<?php

// Anular una firma con motivo (M-FIR RN-FIR-01, §9 signed → voided): fila de anulación vinculada, nunca borrando.

use App\Modules\FIR\SignatureService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

/** Reporte firmado por el Jefe SST de Andinas: [headers, id del reporte, id de la solicitud, id de la firma]. */
function firSignedReport($t): array
{
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $me = collect($t->getJson('/api/v1/records/ACR/corrective_actions/options/responsible_user_id', $h)->json('data'))->first()['id'];
    $doc = $t->postJson('/api/v1/records/ACT/substandard_reports', ['report_type' => 'suggestion', 'description' => 'FIR anulación'], $h)->assertCreated()->json('data.id');
    $req = $t->postJson('/api/v1/fir/requests', ['subject_table' => 'substandard_reports', 'subject_id' => $doc, 'signer_user_id' => $me, 'role_in_document' => 'approver'], $h)
        ->assertCreated()->json('data.id');
    $t->postJson("/api/v1/fir/requests/{$req}/sign", ['password' => DEMO_PASSWORD], $h)->assertOk();
    $sig = $t->getJson("/api/v1/fir/verify/substandard_reports/{$doc}", $h)->assertOk()->json('data.0.signature_id');

    return [$h, $doc, $req, $sig];
}

it('anula una firma con motivo: deja la fila de anulación, no toca la original, la solicitud pasa a anulada y desbloquea el registro', function () {
    [$h, $doc, $req, $sig] = firSignedReport($this);
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $before = DB::selectOne('select to_jsonb(s)::text as j from signatures s where id = ?', [$sig])->j;

    // RN-FIR-03: con todas sus firmas el registro no se edita.
    $this->patchJson("/api/v1/records/ACT/substandard_reports/{$doc}", ['description' => 'Corregido'], $h)->assertStatus(409);

    $this->postJson("/api/v1/fir/signatures/{$sig}/void", [], $h)->assertStatus(422)->assertJsonPath('errors.reason.0', 'Indica el motivo de la anulación.');
    $this->postJson("/api/v1/fir/signatures/{$sig}/void", ['reason' => 'corto'], $h)->assertStatus(422);
    $this->postJson("/api/v1/fir/signatures/{$sig}/void", ['reason' => '          '], $h)->assertStatus(422);

    $res = $this->postJson("/api/v1/fir/signatures/{$sig}/void", ['reason' => 'Firmó la persona equivocada'], $h)->assertOk()->json('data');
    expect($res['status'])->toBe('voided');

    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    expect(DB::selectOne('select to_jsonb(s)::text as j from signatures s where id = ?', [$sig])->j)->toBe($before);
    $void = DB::selectOne('select * from signatures where voids_signature_id = ?', [$sig]);
    expect($void->id)->toBe($res['void_id'])->and($void->method)->toBe('void')->and($void->void_reason)->toBe('Firmó la persona equivocada')
        ->and($void->subject_id)->toBe($doc)->and($void->signature_request_id)->toBeNull();
    expect(DB::selectOne('select status from signature_requests where id = ?', [$req])->status)->toBe('voided');

    // No cuenta como firma válida.
    expect(DB::selectOne("select count(*) as n from signatures s where s.subject_type = 'substandard_reports' and s.subject_id = ? and ".SignatureService::valid('s'), [$doc])->n)->toBe(0);

    // Verificación y listado: se ve «Anulada» con motivo, quién y cuándo; la fila de anulación no se lista como firma.
    $v = $this->getJson("/api/v1/fir/verify/substandard_reports/{$doc}", $h)->assertOk()->json('data');
    expect($v)->toHaveCount(1)->and($v[0]['voided'])->toBeTrue()->and($v[0]['void']['reason'])->toBe('Firmó la persona equivocada')
        ->and($v[0]['void']['voided_by_name'])->not->toBeEmpty()->and($v[0]['void']['voided_at'])->not->toBeEmpty()->and($v[0]['can_void'])->toBeFalse();
    $s = $this->getJson("/api/v1/fir/subject/substandard_reports/{$doc}", $h)->assertOk()->json('data.0');
    expect($s['status'])->toBe('voided')->and($s['void_reason'])->toBe('Firmó la persona equivocada')->and($s['can_void'])->toBeFalse();

    // Sin todas sus firmas, el registro vuelve a poder corregirse.
    $this->patchJson("/api/v1/records/ACT/substandard_reports/{$doc}", ['description' => 'Corregido'], $h)->assertOk();
});

it('no anula dos veces ni anula una fila de anulación', function () {
    [$h, , , $sig] = firSignedReport($this);
    $void = $this->postJson("/api/v1/fir/signatures/{$sig}/void", ['reason' => 'Firma registrada por error'], $h)->assertOk()->json('data.void_id');
    $this->postJson("/api/v1/fir/signatures/{$sig}/void", ['reason' => 'Firma registrada por error'], $h)->assertStatus(409);
    $this->postJson("/api/v1/fir/signatures/{$void}/void", ['reason' => 'Anular la anulación'], $h)->assertStatus(409);
    $this->postJson('/api/v1/fir/signatures/01900000-0000-7000-8000-00000000dead/void', ['reason' => 'Firma que no existe'], $h)->assertNotFound();

    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    expect(DB::selectOne('select count(*) as n from signatures where voids_signature_id = ?', [$sig])->n)->toBe(1);
});

it('sin permiso para gestionar solicitudes de firma no se anula (403)', function () {
    [, , , $sig] = firSignedReport($this);
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $uid = DB::selectOne('select id from app_auth_lookup_user(?)', [ESENCIAL_EMAIL])->id;
    $role = DB::selectOne("select id from roles where code = 'area_lead' and account_id is null")->id;
    DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, ?, 'active', now())", [ANDINAS_ID, $uid, $role]);
    DB::select("select set_config('app.company_id', '', false)");

    $this->app['auth']->forgetGuards();
    $h2 = authHeaders(tokenFor(ESENCIAL_EMAIL), ANDINAS_ID);
    $this->postJson("/api/v1/fir/signatures/{$sig}/void", ['reason' => 'Intento sin permiso'], $h2)->assertForbidden();

    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    expect(DB::selectOne('select count(*) as n from signatures where voids_signature_id = ?', [$sig])->n)->toBe(0);
});
