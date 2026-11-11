<?php

// SCR-USR-01 · Inicio de sesión con correo o DNI (docs/08-api.md §2, RN-USR-06).

use App\Modules\REC\Rules\WorkerRules;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

it('con la contraseña correcta no entrega token si el rol exige segundo factor', function () {
    $this->postJson('/api/v1/auth/login', ['email' => PROFESIONAL_EMAIL, 'password' => DEMO_PASSWORD])
        ->assertOk()
        ->assertJsonPath('mfa_required', true)
        ->assertJsonPath('mfa_setup_required', true)
        ->assertJsonStructure(['mfa_challenge_id', 'expires_in'])
        ->assertJsonMissingPath('token');
});

it('entrega el token y los datos del usuario al completar el segundo factor', function () {
    $token = loginAs(PROFESIONAL_EMAIL);

    $this->getJson('/api/v1/me', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('email', PROFESIONAL_EMAIL)
        ->assertJsonPath('full_name', 'Rocío Profesional Villanueva')
        ->assertJsonPath('mfa_enabled', true)
        ->assertJsonMissingPath('password_hash')
        ->assertJsonMissingPath('mfa_secret');
});

it('acepta el correo sin importar mayúsculas', function () {
    $this->postJson('/api/v1/auth/login', ['email' => strtoupper(PROFESIONAL_EMAIL), 'password' => DEMO_PASSWORD])
        ->assertOk()
        ->assertJsonPath('mfa_required', true);
});

it('responde igual si la contraseña es incorrecta o el correo no existe', function () {
    $wrongPassword = $this->postJson('/api/v1/auth/login', ['email' => PROFESIONAL_EMAIL, 'password' => 'otra'])
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json');

    $unknownEmail = $this->postJson('/api/v1/auth/login', ['email' => 'nadie@ejemplo.test', 'password' => 'otra'])
        ->assertStatus(401);

    expect($wrongPassword->json('type'))->toBe('invalid_credentials')
        ->and($unknownEmail->json('type'))->toBe('invalid_credentials')
        ->and($wrongPassword->json('detail'))->toBe($unknownEmail->json('detail'));
});

it('valida los campos con mensajes en español', function () {
    $this->postJson('/api/v1/auth/login', ['email' => 'no-es-correo'])
        ->assertStatus(422)
        ->assertJsonPath('type', 'validation_failed')
        ->assertJsonPath('errors.email.0', 'Ingresa un correo válido o un DNI de 8 dígitos.')
        ->assertJsonPath('errors.password.0', 'Ingresa tu contraseña.');
});

it('bloquea la cuenta tras 5 intentos fallidos seguidos', function () {
    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/login', ['email' => ESENCIAL_EMAIL, 'password' => 'mala'])->assertStatus(401);
    }

    // Aun con la contraseña correcta, queda bloqueada temporalmente.
    $this->postJson('/api/v1/auth/login', ['email' => ESENCIAL_EMAIL, 'password' => DEMO_PASSWORD])
        ->assertStatus(401)
        ->assertJsonPath('type', 'account_locked');
});

it('reinicia el contador de intentos al ingresar bien', function () {
    $this->postJson('/api/v1/auth/login', ['email' => ESENCIAL_EMAIL, 'password' => 'mala'])->assertStatus(401);
    loginAs(ESENCIAL_EMAIL);

    foreach (range(1, 4) as $_) {
        $this->postJson('/api/v1/auth/login', ['email' => ESENCIAL_EMAIL, 'password' => 'mala'])->assertStatus(401);
    }

    loginAs(ESENCIAL_EMAIL);
});

it('cierra la sesión y el token deja de servir', function () {
    $token = loginAs(PROFESIONAL_EMAIL);

    $this->getJson('/api/v1/me', authHeaders($token))->assertOk();
    $this->postJson('/api/v1/auth/logout', [], authHeaders($token))->assertNoContent();

    app('auth')->forgetGuards();

    $this->getJson('/api/v1/me', authHeaders($token))
        ->assertStatus(401)
        ->assertJsonPath('type', 'unauthenticated');
});

it('rechaza /me sin token', function () {
    $this->getJson('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('type', 'unauthenticated');
});

/** Ficha de trabajador con DNI vinculada a la cuenta del usuario (en la transacción de la prueba). */
function workerWithDni(string $companyId, string $email, string $dni): void
{
    DB::select("select set_config('app.company_id', ?, false)", [$companyId]);
    DB::insert("insert into workers (company_id, user_id, document_type, document_number, document_number_hash, document_last4, first_name, paternal_last_name)
                values (?, (select id from app_auth_lookup_user(?)), 'DNI', ?, decode(?, 'hex'), ?, 'Prueba', 'Dni')",
        [$companyId, $email, Crypt::encryptString($dni), WorkerRules::documentHash('DNI', $dni), substr($dni, -4)]);
}

it('ingresa con el DNI del trabajador vinculado y la contraseña', function () {
    workerWithDni(AGRO_ID, ESENCIAL_EMAIL, '40123987');

    $res = $this->postJson('/api/v1/auth/login', ['email' => '40123987', 'password' => DEMO_PASSWORD])->assertOk();
    expect($res->json('token') ?? $res->json('mfa_challenge_id'))->not->toBeNull();
});

it('con un DNI que no existe, contraseña errada o DNI repetido responde igual que con un correo desconocido', function () {
    workerWithDni(AGRO_ID, ESENCIAL_EMAIL, '40123987');

    $unknownEmail = $this->postJson('/api/v1/auth/login', ['email' => 'nadie@ejemplo.test', 'password' => 'otra'])->assertStatus(401);
    $unknownDni = $this->postJson('/api/v1/auth/login', ['email' => '49999999', 'password' => DEMO_PASSWORD])->assertStatus(401);
    $wrongPassword = $this->postJson('/api/v1/auth/login', ['email' => '40123987', 'password' => 'otra'])->assertStatus(401);

    // El mismo DNI en dos cuentas distintas: no se adivina cuál es, se rechaza igual.
    workerWithDni(HUAYLAS_ID, CORPORATIVO_EMAIL, '40123987');
    $duplicated = $this->postJson('/api/v1/auth/login', ['email' => '40123987', 'password' => DEMO_PASSWORD])->assertStatus(401);

    foreach ([$unknownDni, $wrongPassword, $duplicated] as $r) {
        expect($r->json('type'))->toBe('invalid_credentials')
            ->and($r->json('detail'))->toBe($unknownEmail->json('detail'));
    }
});
