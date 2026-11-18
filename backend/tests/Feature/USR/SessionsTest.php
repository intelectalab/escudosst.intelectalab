<?php

// M-USR SCR-USR-05: sesiones activas de mi cuenta y cierre de las de otros equipos.

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

it('lista mis sesiones, marca la actual y cierra las de otros equipos', function () {
    $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0 Mobile');
    $other = loginAs(PROFESIONAL_EMAIL);
    $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0');
    $mine = loginAs(PROFESIONAL_EMAIL);
    app('auth')->forgetGuards();
    $h = ['Authorization' => "Bearer {$mine}", 'Accept' => 'application/json'];

    $list = $this->getJson('/api/v1/auth/sessions', $h)->assertOk()->json('data');
    $current = collect($list)->firstWhere('current', true);
    $phone = collect($list)->firstWhere('device', 'Chrome en Android');
    expect($current['device'])->toBe('Chrome en Windows')->and($phone)->not->toBeNull();

    $this->deleteJson("/api/v1/auth/sessions/{$current['id']}", [], $h)->assertStatus(409);
    $this->deleteJson("/api/v1/auth/sessions/{$phone['id']}", [], $h)->assertOk();
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$other}", 'Accept' => 'application/json'])->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/auth/sessions/close-others', [], $h)->assertOk();
    $this->getJson('/api/v1/me', $h)->assertOk();
});
