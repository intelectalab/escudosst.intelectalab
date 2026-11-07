<?php

// Inactividad: una sesión sin uso por más de escudo.auth.idle_minutes se cierra sola (la API responde 401).

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
});

it('cierra la sesión que pasó el tiempo de inactividad y mantiene la que se usa', function () {
    $token = tokenFor(ESENCIAL_EMAIL);
    $this->getJson('/api/v1/me', authHeaders($token))->assertOk();

    $id = explode('|', $token)[0];
    DB::table('personal_access_tokens')->where('id', $id)->update(['last_used_at' => now()->subMinutes((int) config('escudo.auth.idle_minutes') + 1)]);
    app('auth')->forgetGuards();
    session()->flush();   // en las pruebas Laravel guarda al usuario en la sesión web; la API real no usa sesión
    $this->getJson('/api/v1/me', authHeaders($token))->assertUnauthorized();
    expect(DB::table('personal_access_tokens')->where('id', $id)->exists())->toBeFalse();
});
