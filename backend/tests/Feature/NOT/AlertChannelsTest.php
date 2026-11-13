<?php

// Canales de los avisos (M-NOT): preferencias de cada persona (SCR-NOT-03), bandeja del trabajador, firma solicitada
// y aviso de bloqueo de la cuenta (M-USR §10).

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    app('auth')->forgetGuards();
    Permissions::flush();
});

it('guarda mis preferencias: correo apagado por módulo y resumen diario', function () {
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $p = $this->getJson('/api/v1/me/notification-prefs', $h)->assertOk()->json('data');
    expect($p['modules'])->not->toBeEmpty()->and($p['digest_daily'])->toBeFalse();
    $code = $p['modules'][0]['code'];
    $channels = $p['channels'];
    $channels['email'][$code] = false;
    $this->putJson('/api/v1/me/notification-prefs', ['digest_daily' => true, 'channels' => $channels], $h)->assertOk()
        ->assertJsonPath('data.digest_daily', true)->assertJsonPath("data.channels.email.{$code}", false);
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $uid = DB::selectOne('select id from app_auth_lookup_user(?)', [PROFESIONAL_EMAIL])->id;
    expect((int) DB::selectOne("select count(*) as n from user_notification_prefs where user_id = ? and channel = 'email' and event_category = ? and not enabled", [$uid, strtolower($code)])->n)->toBe(1);
    $this->putJson('/api/v1/me/notification-prefs', ['digest_daily' => false, 'channels' => $p['channels']], $h)->assertOk();
});

it('avisa al firmante cuando le piden una firma', function () {
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $doc = $this->postJson('/api/v1/records/ACT/substandard_reports', ['report_type' => 'unsafe_condition', 'description' => 'Piso mojado sin señal'], $h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $w = DB::selectOne("insert into workers (company_id, document_number, document_number_hash, first_name, paternal_last_name, status) values (?, ?, decode(md5(random()::text), 'hex'), 'Firma', 'Pedida', 'active') returning id",
        [ANDINAS_ID, (string) random_int(60000000, 69999999)])->id;
    $res = $this->postJson('/api/v1/fir/requests', ['subject_table' => 'substandard_reports', 'subject_id' => $doc, 'signer_worker_id' => $w, 'role_in_document' => 'responsible'], $h);
    if ($res->status() === 403) {
        $this->markTestSkipped('FIR no está en el plan de la empresa de prueba.');
    }
    $req = $res->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    expect((int) DB::selectOne("select count(*) as n from notifications where event_code = 'fir.signature_requested' and subject_id = ? and recipient_worker_id = ?", [$req, $w])->n)->toBe(1);
});

it('el trabajador ve sus avisos en su app y quedan leídos', function () {
    $dni = (string) random_int(70000000, 70999999);
    $adm = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => $dni, 'first_name' => 'Avisa', 'paternal_last_name' => 'Prueba'], $adm)->assertCreated()->json('data.id');
    $code = $this->postJson("/api/v1/trb/workers/{$w}/app-access", [], $adm)->assertCreated()->json('data.code');
    app('auth')->forgetGuards();
    $s = $this->postJson('/api/v1/auth/worker/activate', ['dni' => $dni, 'code' => $code, 'pin' => '397154', 'pin_confirmation' => '397154', 'device_uid' => 'wdev-'.uniqid()])->assertCreated()->json();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    (fn () => DB::insert("insert into notifications (company_id, event_code, subject_type, subject_id, recipient_worker_id, title, body, link, priority, dedupe_key)
        values (?, 'epp.expiring', 'EPP', ?, ?, 'EPP por reponer', 'Tu casco debe reponerse.', '/mi', 'low', ?)", [HUAYLAS_ID, $w, $w, 'test-wapp:'.$w]))();
    DB::select("select set_config('app.company_id', '', false)");
    $h = authHeaders($s['token'], HUAYLAS_ID);
    expect($this->getJson('/api/v1/me/worker', $h)->assertOk()->json('data.summary.notices'))->toBe(1);
    expect(collect($this->getJson('/api/v1/me/worker/notifications', $h)->assertOk()->json('data'))->pluck('title')->all())->toContain('EPP por reponer');
    $this->postJson('/api/v1/me/worker/notifications/read-all', [], $h)->assertOk();
    expect($this->getJson('/api/v1/me/worker', $h)->json('data.summary.notices'))->toBe(0);
});

it('al bloquearse la cuenta por intentos fallidos avisa al usuario por correo', function () {
    Mail::fake();
    foreach (range(1, (int) config('escudo.auth.max_failed_logins')) as $_) {
        $this->postJson('/api/v1/auth/login', ['email' => ESENCIAL_EMAIL, 'password' => 'mala'])->assertStatus(401);
    }
    Mail::assertSent(\App\Modules\USR\AccountNoticeMail::class, fn ($m) => $m->hasTo(ESENCIAL_EMAIL) && str_contains($m->title, 'bloqueó'));
});
