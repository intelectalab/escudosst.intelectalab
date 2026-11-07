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

// ---------------------------------------------------------------- Agenda de demos (varias pruebas)

/** Un anfitrión que atiende todos los días de 09:00 a 12:00 y la agenda pública activa. */
function demoSetup(): string
{
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $rules = array_map(fn ($d) => ['weekday' => $d, 'start_time' => '09:00', 'end_time' => '12:00'], range(1, 7));
    $me = DB::connection('platform')->selectOne('select id from users where email = ?', [ADMIN_EMAIL])->id;
    $host = test()->postJson('/api/v1/internal/site/demos/hosts', ['user_id' => $me, 'display_name' => 'Asesora Comercial', 'email' => 'asesora@escudo.test', 'rules' => $rules, 'max_per_day' => 5], $h)->assertCreated()->json('data.id');
    test()->patchJson('/api/v1/internal/site/demos/settings', [
        'public_booking_enabled' => true, 'duration_minutes' => 30, 'buffer_minutes' => 15, 'min_notice_hours' => 24, 'max_days_ahead' => 30,
        'default_meeting_provider' => 'meet', 'reminder_24h' => true, 'reminder_1h' => true,
    ], $h)->assertNoContent();

    return $host;
}

function bookingForm(string $startsAt, array $extra = []): array
{
    return $extra + ['starts_at' => $startsAt, 'full_name' => 'Ana Visitante', 'email' => 'ana@visita.test', 'company_name' => 'Obras Ejemplo SAC',
        'meeting_provider' => 'meet', 'privacy_consent' => true, 'recording_consent' => false];
}
