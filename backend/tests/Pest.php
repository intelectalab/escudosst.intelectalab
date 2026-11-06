<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(DatabaseTransactions::class)
    ->in('Feature');

/*
| Datos de docker/postgres/dev-seed.sql usados en las pruebas.
| Un usuario Jefe SST por plan, cada uno solo en su empresa.
*/
const AGRO_ID = '01900000-0000-7000-8000-000000000103';      // plan Esencial
const ANDINAS_ID = '01900000-0000-7000-8000-000000000101';   // plan Profesional
const HUAYLAS_ID = '01900000-0000-7000-8000-000000000102';   // plan Corporativo
const ESENCIAL_EMAIL = 'esencial@escudo.test';
const PROFESIONAL_EMAIL = 'profesional@escudo.test';
const CORPORATIVO_EMAIL = 'corporativo@escudo.test';
const DEMO_PASSWORD = 'EscudoDemo2026';
const ADMIN_EMAIL = 'admin@escudo.test';                      // equipo de la plataforma

/** Una sesión por usuario dentro de cada prueba (el mismo código TOTP no se acepta dos veces). */
function tokenFor(string $email): string
{
    return $GLOBALS['sus_tokens'][$email] ??= loginAs($email);
}

/** Secretos TOTP de los usuarios de prueba que ya configuraron MFA en esta prueba. */
function mfaSecrets(): ArrayObject
{
    static $secrets;

    return $secrets ??= new ArrayObject;
}

/**
 * Código TOTP válido y no usado todavía: si el código actual ya se usó (protección contra
 * repetición), genera el de la siguiente ventana de 30 s, que también se acepta.
 */
function totpFor(string $userId, string $secret): string
{
    $g = new Google2FA;
    $step = max($g->getTimestamp(), (int) Cache::get('mfa_last_ts:'.$userId, 0) + 1);

    return $g->oathTotp($secret, $step);
}

/**
 * Inicia sesión por la API, pasando el segundo factor si hace falta (lo configura la primera vez),
 * y devuelve el token.
 */
function loginAs(string $email): string
{
    app('auth')->forgetGuards();

    $res = test()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => DEMO_PASSWORD])->assertOk();

    if (! $res->json('mfa_required')) {
        return $res->json('token');
    }

    $challenge = $res->json('mfa_challenge_id');
    $userId = DB::selectOne('select id from app_auth_lookup_user(?)', [$email])->id;

    if ($res->json('mfa_setup_required')) {
        $secret = test()->postJson('/api/v1/auth/mfa/setup', ['mfa_challenge_id' => $challenge])->assertOk()->json('secret');
        mfaSecrets()[$email] = $secret;

        return test()->postJson('/api/v1/auth/mfa/confirm', ['mfa_challenge_id' => $challenge, 'code' => totpFor($userId, $secret)])
            ->assertOk()->json('token');
    }

    return test()->postJson('/api/v1/auth/mfa/verify', ['mfa_challenge_id' => $challenge, 'code' => totpFor($userId, mfaSecrets()[$email])])
        ->assertOk()->json('token');
}

/** @return array<string, string> */
function authHeaders(string $token, ?string $companyId = null): array
{
    app('auth')->forgetGuards();

    return array_filter([
        'Authorization' => "Bearer {$token}",
        'X-Company-Id' => $companyId,
    ]);
}
