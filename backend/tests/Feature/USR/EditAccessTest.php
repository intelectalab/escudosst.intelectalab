<?php

// «Editar acceso» de un usuario existente (M-USR SCR-USR-03): cambiar alcance (sedes/áreas) y vigencia; el auditor
// externo siempre tiene fecha de fin, también al pasar a ese rol desde otro.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $GLOBALS['uea_emails'] = [];
    Mail::fake();
});

afterEach(function () {
    DB::rollBack();
    $platform = DB::connection('pgsql_owner');
    foreach ($GLOBALS['uea_emails'] as $email) {
        $u = $platform->selectOne('select id from users where lower(email) = ?', [$email]);
        if ($u) {
            $platform->delete('delete from personal_access_tokens where tokenable_id = ?', [$u->id]);
            $platform->delete('delete from users where id = ?', [$u->id]);
        }
    }
});

function ueaH(): array
{
    return authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
}

function ueaEmail(string $p): string
{
    $e = $p.'.'.Str::lower(Str::random(8)).'@escudo.test';
    $GLOBALS['uea_emails'][] = $e;

    return $e;
}

it('edita alcance y vigencia de un usuario existente y conserva los demás alcances', function () {
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'UEA-S', 'name' => 'Sede Edición'], ueaH())->assertCreated()->json('data.id');
    $area = $this->postJson('/api/v1/records/TEN/areas', ['site_id' => $site, 'code' => 'UEA-A', 'name' => 'Área Edición'], ueaH())->assertCreated()->json('data.id');
    $m = $this->postJson('/api/v1/company/users', ['email' => ueaEmail('jefe'), 'full_name' => 'Jefe Editado', 'role_code' => 'area_lead'], ueaH())->assertCreated()->json('data.id');
    $until = now('America/Lima')->addWeeks(2)->toDateString();

    $this->patchJson("/api/v1/company/users/{$m}", ['site_ids' => [], 'area_ids' => [$area], 'expires_on' => $until], ueaH())->assertOk();
    $row = collect($this->getJson('/api/v1/company/users', ueaH())->json('data'))->firstWhere('id', $m);
    expect(collect($row['scopes'])->pluck('name')->all())->toBe(['Área Edición'])
        ->and(substr((string) $row['expires_at'], 0, 10))->toBe($until);

    // Queda en la bitácora de la empresa.
    $log = DB::selectOne("select changes from audit_logs where entity_id = ? and action = 'member_updated' order by created_at desc limit 1", [$m]);
    expect(json_decode($log->changes, true))->toHaveKeys(['scope', 'expires_at']);

    // Área inexistente: no cambia nada.
    $this->patchJson("/api/v1/company/users/{$m}", ['site_ids' => [], 'area_ids' => [Str::uuid7()->toString()]], ueaH())->assertStatus(422)->assertJsonStructure(['errors' => ['area_ids']]);
    expect(collect($this->getJson('/api/v1/company/users', ueaH())->json('data'))->firstWhere('id', $m)['scopes'])->toHaveCount(1);
});

it('pasa a auditor externo solo con fecha de fin y no permite quitársela', function () {
    $until = now('America/Lima')->addMonth()->toDateString();
    $m = $this->postJson('/api/v1/company/users', ['email' => ueaEmail('aud'), 'full_name' => 'Futuro Auditor', 'role_code' => 'sst_supervisor'], ueaH())->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/company/users/{$m}", ['role_code' => 'external_auditor', 'expires_on' => ''], ueaH())->assertStatus(422);
    // Fecha inválida: el rol tampoco cambia.
    $this->patchJson("/api/v1/company/users/{$m}", ['role_code' => 'external_auditor', 'expires_on' => '2020-01-01'], ueaH())->assertStatus(422);
    expect(collect($this->getJson('/api/v1/company/users', ueaH())->json('data'))->firstWhere('id', $m)['role_code'])->toBe('sst_supervisor');
    $this->patchJson("/api/v1/company/users/{$m}", ['role_code' => 'external_auditor', 'site_ids' => [], 'area_ids' => [], 'expires_on' => $until], ueaH())->assertOk();
    $row = collect($this->getJson('/api/v1/company/users', ueaH())->json('data'))->firstWhere('id', $m);
    expect($row['role_code'])->toBe('external_auditor')->and(substr((string) $row['expires_at'], 0, 10))->toBe($until);

    $this->patchJson("/api/v1/company/users/{$m}", ['expires_on' => null], ueaH())->assertStatus(422)->assertJsonStructure(['errors' => ['expires_on']]);
    $this->patchJson("/api/v1/company/users/{$m}", ['expires_on' => '2020-01-01'], ueaH())->assertStatus(422);
});
