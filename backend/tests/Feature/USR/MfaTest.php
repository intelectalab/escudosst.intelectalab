<?php

// Verificación en dos pasos (docs/08-api.md §2, RN-USR-05, RN-USR-06).

use App\Modules\USR\Mail\MfaCodeMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

function challengeFor(string $email): array
{
    return test()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => DEMO_PASSWORD])->assertOk()->json();
}

function userIdOf(string $email): string
{
    return DB::selectOne('select id from app_auth_lookup_user(?)', [$email])->id;
}

it('configura el segundo factor con QR y entrega 8 códigos de recuperación', function () {
    $c = challengeFor(ESENCIAL_EMAIL);

    $setup = $this->postJson('/api/v1/auth/mfa/setup', ['mfa_challenge_id' => $c['mfa_challenge_id']])
        ->assertOk()
        ->assertJsonPath('issuer', 'EscudoSST');
    expect($setup->json('otpauth_uri'))->toStartWith('otpauth://totp/EscudoSST:');

    $res = $this->postJson('/api/v1/auth/mfa/confirm', [
        'mfa_challenge_id' => $c['mfa_challenge_id'],
        'code' => totpFor(userIdOf(ESENCIAL_EMAIL), $setup->json('secret')),
    ])->assertOk()->assertJsonStructure(['token', 'recovery_codes']);

    expect($res->json('recovery_codes'))->toHaveCount(8)
        ->and($res->json('recovery_codes.0'))->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/');

    // El secreto queda cifrado en la base, nunca en claro.
    $stored = DB::selectOne('select mfa_secret, mfa_enabled from users where id = ?', [userIdOf(ESENCIAL_EMAIL)]);
    expect($stored->mfa_enabled)->toBeTrue()
        ->and($stored->mfa_secret)->not->toContain($setup->json('secret'));
});

it('después del alta pide el código en cada ingreso', function () {
    loginAs(ESENCIAL_EMAIL);

    expect(challengeFor(ESENCIAL_EMAIL))
        ->toMatchArray(['mfa_required' => true, 'mfa_setup_required' => false]);
});

it('rechaza un código incorrecto', function () {
    loginAs(ESENCIAL_EMAIL);
    $c = challengeFor(ESENCIAL_EMAIL);

    $this->postJson('/api/v1/auth/mfa/verify', ['mfa_challenge_id' => $c['mfa_challenge_id'], 'code' => '000000'])
        ->assertStatus(401)
        ->assertJsonPath('type', 'invalid_mfa_code');
});

it('no acepta el mismo código dos veces', function () {
    loginAs(ESENCIAL_EMAIL);
    $userId = userIdOf(ESENCIAL_EMAIL);
    $secret = mfaSecrets()[ESENCIAL_EMAIL];

    $code = totpFor($userId, $secret);
    $c1 = challengeFor(ESENCIAL_EMAIL);
    $this->postJson('/api/v1/auth/mfa/verify', ['mfa_challenge_id' => $c1['mfa_challenge_id'], 'code' => $code])->assertOk();

    $c2 = challengeFor(ESENCIAL_EMAIL);
    $this->postJson('/api/v1/auth/mfa/verify', ['mfa_challenge_id' => $c2['mfa_challenge_id'], 'code' => $code])
        ->assertStatus(401);
});

it('bloquea la cuenta tras 5 códigos incorrectos', function () {
    loginAs(ESENCIAL_EMAIL);

    foreach (range(1, 5) as $_) {
        $c = challengeFor(ESENCIAL_EMAIL);
        $this->postJson('/api/v1/auth/mfa/verify', ['mfa_challenge_id' => $c['mfa_challenge_id'], 'code' => '000000'])
            ->assertStatus(401);
    }

    $this->postJson('/api/v1/auth/login', ['email' => ESENCIAL_EMAIL, 'password' => DEMO_PASSWORD])
        ->assertStatus(401)
        ->assertJsonPath('type', 'account_locked');
});

it('permite ingresar con un código de recuperación, que sirve una sola vez', function () {
    $c = challengeFor(CORPORATIVO_EMAIL);
    $secret = $this->postJson('/api/v1/auth/mfa/setup', ['mfa_challenge_id' => $c['mfa_challenge_id']])->json('secret');
    $codes = $this->postJson('/api/v1/auth/mfa/confirm', [
        'mfa_challenge_id' => $c['mfa_challenge_id'], 'code' => totpFor(userIdOf(CORPORATIVO_EMAIL), $secret),
    ])->json('recovery_codes');

    $c2 = challengeFor(CORPORATIVO_EMAIL);
    $this->postJson('/api/v1/auth/mfa/verify', ['mfa_challenge_id' => $c2['mfa_challenge_id'], 'recovery_code' => strtolower($codes[0])])
        ->assertOk()
        ->assertJsonPath('recovery_codes_left', 7);

    $c3 = challengeFor(CORPORATIVO_EMAIL);
    $this->postJson('/api/v1/auth/mfa/verify', ['mfa_challenge_id' => $c3['mfa_challenge_id'], 'recovery_code' => $codes[0]])
        ->assertStatus(401);
});

it('rechaza un desafío inexistente o vencido', function () {
    $this->postJson('/api/v1/auth/mfa/verify', ['mfa_challenge_id' => '01900000-0000-7000-8000-00000000abcd', 'code' => '123456'])
        ->assertStatus(401)
        ->assertJsonPath('type', 'mfa_challenge_expired');
});

it('no deja desactivar el segundo factor si el rol lo exige', function () {
    $token = loginAs(PROFESIONAL_EMAIL);

    $this->getJson('/api/v1/auth/mfa', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('mfa_enabled', true)
        ->assertJsonPath('mfa_required', true)
        ->assertJsonPath('recovery_codes_left', 8);

    $this->postJson('/api/v1/auth/mfa/disable', ['code' => '123456'], authHeaders($token))
        ->assertStatus(403);
});

it('genera códigos de recuperación nuevos con un código vigente', function () {
    $token = loginAs(PROFESIONAL_EMAIL);
    $code = totpFor(userIdOf(PROFESIONAL_EMAIL), mfaSecrets()[PROFESIONAL_EMAIL]);

    $this->postJson('/api/v1/auth/mfa/recovery-codes', ['code' => $code], authHeaders($token))
        ->assertOk()
        ->assertJsonCount(8, 'recovery_codes');
});

// ---------------------------------------------------------------- Código por correo

it('envía un código por correo y con él permite ingresar, aunque no tenga la app configurada', function () {
    Mail::fake();
    $c = challengeFor(ESENCIAL_EMAIL);

    $this->postJson('/api/v1/auth/mfa/email', ['mfa_challenge_id' => $c['mfa_challenge_id']])
        ->assertStatus(202)
        ->assertJsonPath('sent_to', 'e*******@escudo.test');

    $sent = null;
    Mail::assertSent(MfaCodeMail::class, function ($mail) use (&$sent) {
        $sent = $mail;

        return $mail->hasTo(ESENCIAL_EMAIL);
    });
    expect($sent->code)->toMatch('/^\d{6}$/')
        ->and($sent->envelope()->subject)->toContain($sent->code);

    $this->postJson('/api/v1/auth/mfa/verify', ['mfa_challenge_id' => $c['mfa_challenge_id'], 'email_code' => $sent->code])
        ->assertOk()
        ->assertJsonStructure(['token']);
});

it('rechaza un código por correo equivocado y no deja reenviar antes de un minuto', function () {
    Mail::fake();
    $c = challengeFor(ESENCIAL_EMAIL);

    $this->postJson('/api/v1/auth/mfa/email', ['mfa_challenge_id' => $c['mfa_challenge_id']])->assertStatus(202);
    $this->postJson('/api/v1/auth/mfa/email', ['mfa_challenge_id' => $c['mfa_challenge_id']])
        ->assertStatus(429)
        ->assertJsonPath('type', 'rate_limited');

    $this->postJson('/api/v1/auth/mfa/verify', ['mfa_challenge_id' => $c['mfa_challenge_id'], 'email_code' => '000000'])
        ->assertStatus(401)
        ->assertJsonPath('type', 'invalid_mfa_code');

    Mail::assertSentCount(1);
});

it('el código por correo sirve solo para su propio desafío', function () {
    Mail::fake();
    $c1 = challengeFor(ESENCIAL_EMAIL);
    $this->postJson('/api/v1/auth/mfa/email', ['mfa_challenge_id' => $c1['mfa_challenge_id']]);
    $code = null;
    Mail::assertSent(MfaCodeMail::class, function ($m) use (&$code) {
        $code = $m->code;

        return true;
    });

    $c2 = challengeFor(ESENCIAL_EMAIL);
    $this->postJson('/api/v1/auth/mfa/verify', ['mfa_challenge_id' => $c2['mfa_challenge_id'], 'email_code' => $code])
        ->assertStatus(401);
});
