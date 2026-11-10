<?php

// Usuarios de la empresa: invitar con rol, no duplicar, cambiar rol, suspender y reglas de protección.

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Mail::fake();
});

function cu(): array
{
    return authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
}

it('invita a una persona con un rol y la lista la muestra como invitada', function () {
    $email = 'supervisor.'.Str::lower(Str::random(8)).'@escudo.test';
    $roles = collect($this->getJson('/api/v1/company/roles', cu())->assertOk()->json('data'))->pluck('code');
    expect($roles)->toContain('sst_supervisor')->not->toContain('platform_owner')->not->toContain('account_admin'); // el Jefe SST no da ese rol

    $this->postJson('/api/v1/company/users', ['email' => 'no-es-correo', 'full_name' => 'X', 'role_code' => 'platform_owner'], cu())
        ->assertStatus(422)->assertJsonStructure(['errors' => ['email', 'full_name', 'role_code']]);
    $this->postJson('/api/v1/company/users', ['email' => $email, 'full_name' => 'Sara Supervisora', 'role_code' => 'account_admin'], cu())
        ->assertStatus(422)->assertJsonPath('errors.role_code.0', 'Solo un administrador de cuenta puede dar ese rol.');

    $m = $this->postJson('/api/v1/company/users', ['email' => $email, 'full_name' => 'Sara Supervisora', 'role_code' => 'sst_supervisor'], cu())
        ->assertCreated()->assertJsonPath('data.status', 'invited')->json('data.id');
    Mail::assertSent(\App\Modules\USR\Mail\AccountInvitationMail::class);

    $this->postJson('/api/v1/company/users', ['email' => $email, 'full_name' => 'Sara', 'role_code' => 'worker'], cu())->assertStatus(409);
    $row = collect($this->getJson('/api/v1/company/users', cu())->json('data'))->firstWhere('id', $m);
    expect($row['role_code'])->toBe('sst_supervisor')->and($row['status'])->toBe('invited');

    $this->patchJson("/api/v1/company/users/{$m}", ['role_code' => 'committee_member'], cu())->assertOk();
    expect(collect($this->getJson('/api/v1/company/users', cu())->json('data'))->firstWhere('id', $m)['role_code'])->toBe('committee_member');
    $this->patchJson("/api/v1/company/users/{$m}", ['status' => 'active'], cu())->assertStatus(422);
    $this->postJson("/api/v1/company/users/{$m}/resend", [], cu())->assertOk();
});

it('no deja cambiar el propio acceso', function () {
    $me = collect($this->getJson('/api/v1/company/users', cu())->json('data'))->firstWhere('email', PROFESIONAL_EMAIL)['id'];
    $this->patchJson("/api/v1/company/users/{$me}", ['status' => 'suspended'], cu())->assertStatus(409);
});

it('muestra la bitácora de la empresa con lo que cambió cada registro', function () {
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'AUD-S', 'name' => 'Sede bitácora'], cu())->json('data.id');
    $res = $this->getJson("/api/v1/company/audit?entity_id={$site}", cu())->assertOk();
    expect($res->json('data.0.action'))->toBe('create')->and($res->json('data.0.tab'))->toBe('Sedes')->and($res->json('data.0.module_of_entity'))->toBe('TEN');
});
