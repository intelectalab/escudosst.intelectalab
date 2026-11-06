<?php

// Mi cuenta (nombre, contraseña, correo con confirmación), «Olvidé mi contraseña» y equipo de la plataforma.

use App\Modules\WEB\Mail\SiteRequestMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Mail::fake();
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

/** Token del último correo con botón enviado a $email. */
function linkTokenFor(string $email): string
{
    $url = null;
    Mail::assertSent(SiteRequestMail::class, function ($m) use ($email, &$url) {
        if ($m->hasTo($email) && $m->buttonUrl) {
            $url = $m->buttonUrl;
        }

        return true;
    });

    return basename((string) $url);
}

/**
 * Fila del usuario como la ve la aplicación: los cambios de la propia persona van por la conexión «pgsql» (dentro de
 * la transacción de la prueba); si esa conexión aún no la ve (recién invitada), se lee con la de la plataforma.
 */
function userRow(string $email): ?object
{
    $id = DB::connection('platform')->selectOne('select id from users where lower(email) = ?', [strtolower($email)])?->id;

    return $id ? userById($id) : null;
}

function userById(string $id): ?object
{
    $db = DB::connection('pgsql');
    $db->select("select set_config('app.user_id', ?, true)", [$id]);

    return $db->selectOne('select * from users where id = ?', [$id])
        ?? DB::connection('platform')->selectOne('select * from users where id = ?', [$id]);
}

it('cambia el nombre y la contraseña (pide la actual)', function () {
    $h = authHeaders(tokenFor(ESENCIAL_EMAIL));
    $this->patchJson('/api/v1/me/profile', ['full_name' => 'Nombre Nuevo'], $h)->assertNoContent();
    expect(userRow(ESENCIAL_EMAIL)->full_name)->toBe('Nombre Nuevo');

    $this->postJson('/api/v1/me/password', ['current_password' => 'mala', 'password' => 'NuevaClave2026', 'password_confirmation' => 'NuevaClave2026'], $h)
        ->assertStatus(422)->assertJsonValidationErrors('current_password');
    $this->postJson('/api/v1/me/password', ['current_password' => DEMO_PASSWORD, 'password' => 'corta', 'password_confirmation' => 'corta'], $h)
        ->assertStatus(422)->assertJsonValidationErrors('password');
    $this->postJson('/api/v1/me/password', ['current_password' => DEMO_PASSWORD, 'password' => 'NuevaClave2026', 'password_confirmation' => 'NuevaClave2026'], $h)
        ->assertNoContent();
    expect(Hash::check('NuevaClave2026', userRow(ESENCIAL_EMAIL)->password_hash))->toBeTrue();
});

it('el correo nuevo solo se usa al confirmarlo', function () {
    $h = authHeaders(tokenFor(ESENCIAL_EMAIL));
    $id = userRow(ESENCIAL_EMAIL)->id;
    $this->postJson('/api/v1/me/email', ['email' => PROFESIONAL_EMAIL, 'current_password' => DEMO_PASSWORD], $h)->assertStatus(422)->assertJsonValidationErrors('email');
    $this->postJson('/api/v1/me/email', ['email' => 'nuevo@escudo.test', 'current_password' => DEMO_PASSWORD], $h)->assertNoContent();
    expect(userRow(ESENCIAL_EMAIL))->not->toBeNull();   // aún el anterior

    $token = linkTokenFor('nuevo@escudo.test');
    $this->postJson("/api/v1/auth/email/confirm/{$token}")->assertOk()->assertJsonPath('data.email', 'nuevo@escudo.test');
    expect(userById($id)->email)->toBe('nuevo@escudo.test');
    $this->postJson("/api/v1/auth/email/confirm/{$token}")->assertStatus(410);   // un solo uso
});

it('«Olvidé mi contraseña» no revela si el correo existe y el enlace sirve una vez', function () {
    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nadie@escudo.test'])->assertStatus(202);
    Mail::assertNothingSent();

    $this->postJson('/api/v1/auth/password/forgot', ['email' => ESENCIAL_EMAIL])->assertStatus(202);
    $token = linkTokenFor(ESENCIAL_EMAIL);
    expect($this->getJson("/api/v1/auth/links/{$token}")->json('data.purpose'))->toBe('reset');
    $this->postJson("/api/v1/auth/links/{$token}/password", ['password' => 'OtraClave2026', 'password_confirmation' => 'OtraClave2026'])->assertOk();
    expect(Hash::check('OtraClave2026', userRow(ESENCIAL_EMAIL)->password_hash))->toBeTrue();
    $this->postJson("/api/v1/auth/links/{$token}/password", ['password' => 'OtraClave2026', 'password_confirmation' => 'OtraClave2026'])->assertStatus(410);
});

it('el Dueño invita al equipo; la persona crea su contraseña; Soporte no administra ni publica', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $this->postJson('/api/v1/internal/team', ['full_name' => 'Ana Soporte', 'email' => ESENCIAL_EMAIL, 'platform_role' => 'support'], $h)
        ->assertStatus(422)->assertJsonValidationErrors('email');   // ya es usuario de empresa
    $id = $this->postJson('/api/v1/internal/team', ['full_name' => 'Ana Soporte', 'email' => 'ana@escudo.test', 'platform_role' => 'support'], $h)
        ->assertCreated()->json('data.id');
    expect(collect($this->getJson('/api/v1/internal/team', $h)->json('data'))->firstWhere('id', $id)['status'])->toBe('invited');

    $token = linkTokenFor('ana@escudo.test');
    expect($this->getJson("/api/v1/auth/links/{$token}")->json('data'))->toMatchArray(['purpose' => 'team', 'role' => 'Soporte de la plataforma']);
    $this->postJson("/api/v1/auth/links/{$token}/password", ['password' => 'ClaveAna2026', 'password_confirmation' => 'ClaveAna2026'])->assertOk();
    $ana = userRow('ana@escudo.test');
    expect($ana->status)->toBe('active')->and($ana->platform_role)->toBe('support');

    // Soporte entra al panel pero no a lo reservado al Dueño (se prueba pasando al Dueño a Soporte en esta transacción).
    $admin = userRow(ADMIN_EMAIL)->id;
    DB::connection('pgsql')->select("select set_config('app.user_id', ?, false)", [$admin]);
    DB::connection('pgsql')->update("update users set platform_role = 'support' where id = ?", [$admin]);
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/internal/notifications', $h)->assertOk();
    $this->getJson('/api/v1/internal/team', $h)->assertForbidden();
    $this->getJson('/api/v1/internal/site/leads/export', $h)->assertForbidden();
});

it('no se queda sin Dueño ni se desactiva a sí mismo', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $me = userRow(ADMIN_EMAIL)->id;
    $this->postJson("/api/v1/internal/team/{$me}/disable", [], $h)->assertStatus(422);
    $this->patchJson("/api/v1/internal/team/{$me}", ['platform_role' => 'support'], $h)->assertStatus(422);
});
