<?php

// Clave médica con segundo factor (M-EMO §7, docs/10 §5): contraseña + código de la app de autenticación (o un código de
// recuperación, de un solo uso) + motivo. Sin verificación en dos pasos no se emite; cada intento fallido queda denegado.

use App\Core\Tenancy\DbContext;
use App\Models\User;
use App\Modules\USR\Services\MfaService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::connection('medical')->beginTransaction();
    // El usuario Esencial atiende también como médico ocupacional de Huaylas (solo durante la prueba).
    DB::select("select set_config('app.company_id', ?, false)", [AGRO_ID]);
    $uid = DB::selectOne('select id from users where email = ?', [ESENCIAL_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $role = DB::selectOne("select id from roles where account_id is null and code = 'occupational_doctor'")->id;
    DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, ?, 'active', now())", [HUAYLAS_ID, $uid, $role]);
    DB::select("select set_config('app.company_id', '', false)");
});

afterEach(function () {
    DB::connection('medical')->rollBack();
});

function reauthDoctorId(): string
{
    return DB::selectOne('select id from app_auth_lookup_user(?)', [ESENCIAL_EMAIL])->id;
}

/** @return list<string> motivos de los accesos denegados de la persona en Huaylas, del más antiguo al más reciente */
function deniedReauths(string $uid): array
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $rows = DB::select("select purpose from sensitive_access_logs where user_id = ? and result = 'denied' order by created_at, id", [$uid]);
    DB::select("select set_config('app.company_id', '', false)");

    return array_map(fn ($r) => $r->purpose, $rows);
}

it('con la contraseña correcta y un código de la app incorrecto no emite la clave y lo registra como denegado', function () {
    $doc = authHeaders(tokenFor(ESENCIAL_EMAIL), HUAYLAS_ID);
    $uid = reauthDoctorId();
    $before = deniedReauths($uid);

    $this->postJson('/api/v1/emo/reauth', ['password' => DEMO_PASSWORD, 'code' => '000000', 'purpose' => 'Control periódico'], $doc)
        ->assertStatus(422)->assertJsonPath('errors.code.0', 'El código de la app no es correcto o ya se usó. Espera el siguiente.');
    // Sin código ni motivo tampoco.
    $this->postJson('/api/v1/emo/reauth', ['password' => DEMO_PASSWORD], $doc)->assertStatus(422)->assertJsonValidationErrors(['code', 'purpose']);
    // Contraseña incorrecta con código válido.
    medReauth('/api/v1/emo/reauth', $doc, ESENCIAL_EMAIL, ['password' => 'otra'])->assertStatus(422)->assertJsonValidationErrors(['password']);

    expect(array_slice(deniedReauths($uid), count($before)))->toBe(['Código de verificación incorrecto', 'Contraseña incorrecta']);
});

it('sin verificación en dos pasos activa no emite la clave médica y dice dónde activarla', function () {
    $doc = authHeaders(tokenFor(ESENCIAL_EMAIL), HUAYLAS_ID);
    $uid = reauthDoctorId();
    DbContext::setUser($uid);
    DB::update('update users set mfa_secret = null, mfa_recovery_codes = null, mfa_enabled = false where id = ?', [$uid]);

    $res = $this->postJson('/api/v1/emo/reauth', ['password' => DEMO_PASSWORD, 'code' => '123456', 'purpose' => 'Control periódico'], $doc)
        ->assertStatus(403)->assertJsonPath('type', 'mfa_not_enabled');
    expect($res->json('detail'))->toContain('Mi cuenta')->toContain('Verificación en dos pasos');
    expect(deniedReauths($uid))->toContain('Sin verificación en dos pasos');
});

it('con contraseña y código de la app emite la clave, guarda el factor usado y el motivo queda en cada acceso', function () {
    $sst = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '63631177', 'first_name' => 'Elsa', 'paternal_last_name' => 'Motivo'], $sst)->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $order = DB::selectOne("insert into medical_exam_orders (company_id, worker_id, exam_type, clinic_name, scheduled_at, status) values (?, ?, 'periodic', 'Clínica Andina', now(), 'attended') returning id",
        [HUAYLAS_ID, $w])->id;
    DB::select("select set_config('app.company_id', '', false)");

    $doc = authHeaders(tokenFor(ESENCIAL_EMAIL), HUAYLAS_ID);
    $this->getJson("/api/v1/emo/orders/{$order}/clinical", $doc)->assertStatus(403)->assertJsonPath('type', 'reauth_required')
        ->assertJsonPath('title', 'Confirma tu identidad');
    $r = medReauth('/api/v1/emo/reauth', $doc, ESENCIAL_EMAIL, ['purpose' => 'Revisión de aptitud para trabajo en altura'])->assertOk()
        ->assertJsonPath('data.mfa_method', 'totp')->json('data.reauth_id');

    $s = DB::selectOne('select mfa_method, mfa_verified_at, reauth_purpose from sessions_mfa where id = ?', [$r]);
    expect($s->mfa_method)->toBe('totp')->and($s->mfa_verified_at)->not->toBeNull()->and($s->reauth_purpose)->toBe('medical');

    $this->getJson("/api/v1/emo/orders/{$order}/clinical", $doc + ['X-Medical-Reauth' => $r])->assertOk();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne("select purpose from sensitive_access_logs where reauth_session_id = ? and result = 'granted' order by created_at desc limit 1", [$r])->purpose)
        ->toBe('Revisión de aptitud para trabajo en altura');
    DB::select("select set_config('app.company_id', '', false)");
});

it('un código de recuperación sirve una sola vez como segundo factor', function () {
    $doc = authHeaders(tokenFor(ESENCIAL_EMAIL), HUAYLAS_ID);
    $uid = reauthDoctorId();
    DbContext::setUser($uid);
    $codes = app(MfaService::class)->regenerateRecoveryCodes(User::findOrFail($uid));
    $body = ['password' => DEMO_PASSWORD, 'recovery_code' => strtolower($codes[0]), 'purpose' => 'Sin celular a la mano'];

    $this->postJson('/api/v1/emo/reauth', $body, $doc)->assertOk()->assertJsonPath('data.mfa_method', 'recovery_code');
    expect(app(MfaService::class)->remainingRecoveryCodes(User::findOrFail($uid)))->toBe(count($codes) - 1);

    $this->postJson('/api/v1/emo/reauth', $body, $doc)->assertStatus(422)
        ->assertJsonPath('errors.recovery_code.0', 'El código de recuperación no es válido o ya se usó.');
});
