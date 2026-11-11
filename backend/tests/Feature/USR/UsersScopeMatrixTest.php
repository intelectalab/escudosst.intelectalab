<?php

// Usuarios de la empresa (M-USR SCR-USR-02/03/04): columnas de la lista (correo, alcance, doble factor, último acceso),
// invitación con alcance por sede/área y vigencia (obligatoria para el auditor externo) y matriz de roles y permisos.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $GLOBALS['usm_emails'] = [];
    Mail::fake();
});

// Los usuarios nuevos se crean con la conexión de la plataforma (fuera de la transacción): se borran al final.
afterEach(function () {
    DB::rollBack();
    $platform = DB::connection('pgsql_owner');
    foreach ($GLOBALS['usm_emails'] as $email) {
        $u = $platform->selectOne('select id from users where lower(email) = ?', [$email]);
        if ($u) {
            $platform->delete('delete from personal_access_tokens where tokenable_id = ?', [$u->id]);
            $platform->delete('delete from users where id = ?', [$u->id]);
        }
    }
});

function usm(): array
{
    return authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
}

function usmEmail(string $p): string
{
    $e = $p.'.'.Str::lower(Str::random(8)).'@escudo.test';
    $GLOBALS['usm_emails'][] = $e;

    return $e;
}

it('invita con alcance por sede y área, y la lista muestra correo, alcance, doble factor y último acceso', function () {
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'USM-S', 'name' => 'Sede Alcance'], usm())->assertCreated()->json('data.id');
    $area = $this->postJson('/api/v1/records/TEN/areas', ['site_id' => $site, 'code' => 'USM-A', 'name' => 'Área Alcance'], usm())->assertCreated()->json('data.id');

    $opts = $this->getJson('/api/v1/company/scope-options', usm())->assertOk();
    expect(collect($opts->json('data.sites'))->pluck('id'))->toContain($site)
        ->and(collect($opts->json('data.areas'))->firstWhere('id', $area)['site_id'])->toBe($site);

    $this->postJson('/api/v1/company/users', ['email' => usmEmail('x'), 'full_name' => 'Con Sede Mala', 'role_code' => 'area_lead', 'site_ids' => [Str::uuid7()->toString()]], usm())
        ->assertStatus(422)->assertJsonStructure(['errors' => ['site_ids']]);

    $email = usmEmail('lead');
    $m = $this->postJson('/api/v1/company/users', ['email' => $email, 'full_name' => 'Lía Jefa de Área', 'role_code' => 'area_lead', 'site_ids' => [$site], 'area_ids' => [$area]], usm())
        ->assertCreated()->json('data.id');
    $row = collect($this->getJson('/api/v1/company/users', usm())->assertOk()->json('data'))->firstWhere('id', $m);
    expect($row['email'])->toBe($email)->and($row['mfa_enabled'])->toBeFalse()->and($row['expires_at'])->toBeNull()
        ->and(collect($row['scopes'])->pluck('name')->all())->toEqualCanonicalizing(['Sede Alcance', 'Área Alcance'])
        ->and($row)->toHaveKey('last_access_at');

    // Cambiar el alcance: queda solo la sede; sin alcance = toda la empresa.
    $this->patchJson("/api/v1/company/users/{$m}", ['site_ids' => [$site], 'area_ids' => []], usm())->assertOk();
    $row = collect($this->getJson('/api/v1/company/users', usm())->json('data'))->firstWhere('id', $m);
    expect(collect($row['scopes'])->pluck('type')->all())->toBe(['site']);
    $this->patchJson("/api/v1/company/users/{$m}", ['site_ids' => [], 'area_ids' => []], usm())->assertOk();
    expect(collect($this->getJson('/api/v1/company/users', usm())->json('data'))->firstWhere('id', $m)['scopes'])->toBe([]);
});

it('exige vigencia para el auditor externo y la guarda', function () {
    $this->postJson('/api/v1/company/users', ['email' => usmEmail('aud'), 'full_name' => 'Auditor Externo', 'role_code' => 'external_auditor'], usm())
        ->assertStatus(422)->assertJsonStructure(['errors' => ['expires_on']]);
    $this->postJson('/api/v1/company/users', ['email' => usmEmail('aud'), 'full_name' => 'Auditor Externo', 'role_code' => 'external_auditor', 'expires_on' => '2020-01-01'], usm())
        ->assertStatus(422)->assertJsonPath('errors.expires_on.0', 'La fecha de fin no puede estar en el pasado.');
    $until = now()->addMonth()->toDateString();
    $m = $this->postJson('/api/v1/company/users', ['email' => usmEmail('aud'), 'full_name' => 'Auditor Externo', 'role_code' => 'external_auditor', 'expires_on' => $until], usm())
        ->assertCreated()->json('data.id');
    $row = collect($this->getJson('/api/v1/company/users', usm())->json('data'))->firstWhere('id', $m);
    expect(substr((string) $row['expires_at'], 0, 10))->toBe($until);

    // Vigencia opcional para otros roles; pasar a auditor sin fecha no se permite.
    $other = $this->postJson('/api/v1/company/users', ['email' => usmEmail('sup'), 'full_name' => 'Supervisor Temporal', 'role_code' => 'sst_supervisor'], usm())->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/company/users/{$other}", ['role_code' => 'external_auditor'], usm())->assertStatus(422);
    $this->patchJson("/api/v1/company/users/{$other}", ['expires_on' => $until], usm())->assertOk();
    $this->patchJson("/api/v1/company/users/{$other}", ['expires_on' => null], usm())->assertOk();
    expect(collect($this->getJson('/api/v1/company/users', usm())->json('data'))->firstWhere('id', $other)['expires_at'])->toBeNull();
});

it('muestra la matriz de roles y permisos por módulo con los sensibles marcados', function () {
    $res = $this->getJson('/api/v1/company/roles/matrix', usm())->assertOk();
    $roles = collect($res->json('data.roles'));
    expect($roles->pluck('code'))->toContain('sst_manager')->toContain('occupational_doctor')->not->toContain('platform_owner')
        ->and($roles->firstWhere('code', 'occupational_doctor')['is_medical'])->toBeTrue();

    $usr = collect($res->json('data.modules'))->firstWhere('code', 'USR');
    expect($usr['name'])->not->toBe('USR');
    $read = collect($usr['permissions'])->firstWhere('code', 'usr.memberships.read');
    expect($read['action_label'])->toBe('Ver')->and($read['roles'])->toContain('account_admin');

    // Los permisos sensibles (dato médico) solo están en roles médicos (RN-USR-03).
    $medical = $roles->where('is_medical', true)->pluck('code')->all();
    $sensitive = collect($res->json('data.modules'))->flatMap(fn ($m) => $m['permissions'])->where('is_sensitive', true)->where('action', 'read_sensitive')->filter(fn ($p) => in_array(strtok($p['code'], '.'), ['emo', 'vul', 'des'], true));
    expect($sensitive)->not->toBeEmpty();
    foreach ($sensitive as $p) {
        expect(array_diff($p['roles'], $medical))->toBe([], $p['code']);
    }
});

it('no deja ver la matriz ni invitar a un rol sin permisos de usuarios', function () {
    $email = usmEmail('worker');
    $this->postJson('/api/v1/company/users', ['email' => $email, 'full_name' => 'Tomás Trabajador', 'role_code' => 'worker'], usm())->assertCreated();
    $url = null;
    Mail::assertSent(\App\Modules\USR\Mail\AccountInvitationMail::class, function ($m) use (&$url) {
        $url = $m->url;

        return true;
    });
    $this->postJson('/api/v1/auth/invitations/'.basename((string) $url).'/accept', ['password' => DEMO_PASSWORD, 'password_confirmation' => DEMO_PASSWORD])->assertOk();
    $h = authHeaders(tokenFor($email), ANDINAS_ID);
    $this->getJson('/api/v1/company/roles/matrix', $h)->assertStatus(403);
    $this->getJson('/api/v1/company/scope-options', $h)->assertStatus(403);
});
