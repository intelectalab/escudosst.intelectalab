<?php

// Roles personalizados de la cuenta (M-USR SCR-USR-04, RN-USR-03, RN-USR-09): derivan de un rol base sin exceder sus
// permisos, los médicos sensibles solo en roles de base médica, los base no se tocan, y quien recibe un rol
// personalizado tiene exactamente sus permisos pero se comporta como su rol base en las reglas.

use App\Modules\USR\Mail\AccountInvitationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $GLOBALS['cr_emails'] = [];
    Mail::fake();
});

// Los usuarios invitados se crean con la conexión de la plataforma (fuera de la transacción de la prueba).
afterEach(function () {
    DB::rollBack();
    $owner = DB::connection('pgsql_owner');
    foreach ($GLOBALS['cr_emails'] as $email) {
        $u = $owner->selectOne('select id from users where lower(email) = ?', [$email]);
        if ($u) {
            $owner->delete('delete from personal_access_tokens where tokenable_id = ?', [$u->id]);
            $owner->delete('delete from users where id = ?', [$u->id]);
        }
    }
});

function crH(): array
{
    return authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
}

/** Roles base, personalizados y módulos de la empresa (GET /company/roles/manage). */
function crManage(array $headers): array
{
    return test()->getJson('/api/v1/company/roles/manage', $headers)->assertOk()->json('data');
}

function crBase(array $data, string $code): array
{
    return collect($data['roles'])->firstWhere('code', $code);
}

/** Invita con un rol, acepta la invitación con la contraseña demo y devuelve [membership_id, email]. */
function crInviteAndAccept(array $headers, string $roleCode): array
{
    $email = 'rolpers.'.Str::lower(Str::random(8)).'@escudo.test';
    $GLOBALS['cr_emails'][] = $email;
    $membership = test()->postJson('/api/v1/company/users', ['email' => $email, 'full_name' => 'Persona Rol Personalizado', 'role_code' => $roleCode], $headers)
        ->assertCreated()->json('data.id');
    $url = null;
    Mail::assertSent(AccountInvitationMail::class, function ($m) use (&$url, $email) {
        if ($m->hasTo($email)) {
            $url = $m->url;
        }

        return true;
    });
    test()->postJson('/api/v1/auth/invitations/'.basename((string) $url).'/accept', ['password' => DEMO_PASSWORD, 'password_confirmation' => DEMO_PASSWORD])->assertOk();

    return [$membership, $email];
}

it('lista los roles base en solo lectura y crea un rol a partir del Jefe SST con parte de sus permisos', function () {
    $data = crManage(crH());
    $base = crBase($data, 'sst_manager');
    expect($data['can']['create'])->toBeTrue()
        ->and($base['is_custom'])->toBeFalse()
        ->and($base['permissions'])->toContain('ipc.iperc_matrices.read')
        ->and(collect($data['roles'])->pluck('code'))->not->toContain('platform_owner')
        ->and(collect($data['modules'])->firstWhere('code', 'IPC')['available'])->toBeTrue();

    $subset = collect($base['permissions'])->filter(fn ($c) => str_starts_with($c, 'ipc.') || str_starts_with($c, 'trb.'))->values()->all();
    $res = $this->postJson('/api/v1/company/roles', ['base_role_code' => 'sst_manager', 'name' => 'Analista IPERC', 'description' => 'Solo IPERC y trabajadores', 'permissions' => $subset], crH())
        ->assertCreated();
    expect($res->json('data.code'))->toStartWith('sst_manager:');
    $id = $res->json('data.id');

    $custom = collect(crManage(crH())['custom_roles'])->firstWhere('id', $id);
    expect($custom['name'])->toBe('Analista IPERC')
        ->and($custom['base_role_code'])->toBe('sst_manager')
        ->and($custom['scope_level'])->toBe($base['scope_level'])
        ->and($custom['permissions'])->toEqualCanonicalizing($subset);

    // Se ofrece al invitar o cambiar el rol, junto a los roles base.
    expect(collect($this->getJson('/api/v1/company/roles', crH())->json('data'))->firstWhere('code', $res->json('data.code'))['base_role_code'])->toBe('sst_manager');

    // Renombrar y quitar un permiso queda en la bitácora.
    $this->patchJson("/api/v1/company/roles/{$id}", ['name' => 'Analista de riesgos', 'permissions' => array_slice($subset, 1)], crH())->assertOk()->assertJsonPath('data.changed', true);
    $audit = collect($this->getJson("/api/v1/company/audit?entity_id={$id}", crH())->assertOk()->json('data'))->pluck('action');
    expect($audit)->toContain('role_created')->toContain('role_updated');

    // El nombre no se repite.
    $this->postJson('/api/v1/company/roles', ['base_role_code' => 'sst_manager', 'name' => 'Analista de riesgos'], crH())
        ->assertStatus(422)->assertJsonPath('errors.name.0', 'Ya hay un rol con ese nombre.');
});

it('no deja que un rol personalizado exceda los permisos de su rol base', function () {
    $data = crManage(crH());
    $extra = array_values(array_diff(crBase($data, 'sst_manager')['permissions'], crBase($data, 'sst_supervisor')['permissions']));
    expect($extra)->not->toBeEmpty();

    $this->postJson('/api/v1/company/roles', ['base_role_code' => 'sst_supervisor', 'name' => 'Supervisor ampliado', 'permissions' => [...array_slice(crBase($data, 'sst_supervisor')['permissions'], 0, 3), $extra[0]]], crH())
        ->assertStatus(422)->assertJsonPath('type', 'exceeds_base_role')->assertJsonPath('not_in_base.0', $extra[0]);
});

it('rechaza un permiso médico sensible en un rol derivado de Recursos humanos (criterio 5)', function () {
    $hr = crBase(crManage(crH()), 'hr');
    $this->postJson('/api/v1/company/roles', ['base_role_code' => 'hr', 'name' => 'RR. HH. con exámenes', 'permissions' => [...array_slice($hr['permissions'], 0, 3), 'emo.medical_exams.read_sensitive']], crH())
        ->assertStatus(422)->assertJsonPath('type', 'medical_permission_not_allowed')->assertJsonPath('medical_permissions', ['emo.medical_exams.read_sensitive']);

    // Tampoco al editar un rol ya creado.
    $id = $this->postJson('/api/v1/company/roles', ['base_role_code' => 'hr', 'name' => 'Asistente de RR. HH.', 'permissions' => array_slice($hr['permissions'], 0, 5)], crH())
        ->assertCreated()->json('data.id');
    $res = $this->patchJson("/api/v1/company/roles/{$id}", ['permissions' => [...array_slice($hr['permissions'], 0, 5), 'vmd.clinical_notes.read']], crH())
        ->assertStatus(422)->assertJsonPath('type', 'medical_permission_not_allowed');
    expect($res->json('detail'))->toContain('rol médico');

    // Un rol derivado del médico ocupacional sí los conserva.
    $doctor = crBase(crManage(crH()), 'occupational_doctor');
    $this->postJson('/api/v1/company/roles', ['base_role_code' => 'occupational_doctor', 'name' => 'Médico de sede', 'permissions' => array_values(array_filter($doctor['permissions'], fn ($c) => str_starts_with($c, 'emo.')))], crH())
        ->assertCreated();
});

it('no deja editar ni eliminar un rol base', function () {
    $base = crBase(crManage(crH()), 'sst_supervisor');
    $this->patchJson("/api/v1/company/roles/{$base['id']}", ['name' => 'Otro nombre'], crH())->assertStatus(409)->assertJsonPath('type', 'base_role_locked');
    $this->deleteJson("/api/v1/company/roles/{$base['id']}", [], crH())->assertStatus(409)->assertJsonPath('type', 'base_role_locked');
});

it('quien recibe un rol personalizado tiene exactamente sus permisos y solo ve los módulos que puede leer', function () {
    $data = crManage(crH());
    $sup = crBase($data, 'sst_supervisor')['permissions'];
    $available = collect($data['modules'])->where('mode', 'enabled')->pluck('code')->map(fn ($c) => strtolower($c).'.')->all();
    $ipc = array_values(array_filter($sup, fn ($c) => str_starts_with($c, 'ipc.')));
    // De Inspecciones solo un permiso de crear, sin «Ver»: el módulo no debe mostrarse.
    $insCreate = array_values(array_filter($sup, fn ($c) => str_starts_with($c, 'ins.') && str_ends_with($c, '.create')));
    expect($ipc)->not->toBeEmpty()->and($insCreate)->not->toBeEmpty()->and($available)->toContain('ipc.')->toContain('ins.');
    $chosen = [...$ipc, $insCreate[0]];
    $code = $this->postJson('/api/v1/company/roles', ['base_role_code' => 'sst_supervisor', 'name' => 'Revisor de matrices', 'permissions' => $chosen], crH())
        ->assertCreated()->json('data.code');

    [, $email] = crInviteAndAccept(crH(), $code);
    $h = authHeaders(tokenFor($email), ANDINAS_ID);

    $perms = $this->getJson('/api/v1/me/permissions', $h)->assertOk();
    expect($perms->json('data'))->toEqualCanonicalizing($chosen)
        ->and($perms->json('role_code'))->toBe('sst_supervisor')
        ->and($perms->json('custom_role_code'))->toBe($code);

    $modules = collect($this->getJson('/api/v1/me/modules', $h)->assertOk()->json('data'))->keyBy('code');
    expect($modules['IPC']['role_can_use'])->toBeTrue()
        ->and($modules['INS']['role_can_use'])->toBeFalse()
        ->and($modules['ACC']['role_can_use'])->toBeFalse();

    // En el selector de empresas figura con el nombre del rol personalizado y el código de su rol base.
    $company = collect($this->getJson('/api/v1/me/companies', $h)->json('data'))->firstWhere('company_id', ANDINAS_ID);
    expect($company['role']['code'])->toBe('sst_supervisor')->and($company['role']['name'])->toBe('Revisor de matrices');

    // Sin el permiso, la API lo rechaza.
    $this->getJson('/api/v1/records/ACC/sst_events', $h)->assertStatus(403);
    $this->getJson('/api/v1/records/IPC/iperc_matrices', $h)->assertOk();
});

it('un rol derivado del administrador de contratista sigue acotado a su contratista', function () {
    $main = fn () => authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $email = 'ctrpers.'.Str::lower(Str::random(8)).'@escudo.test';
    $GLOBALS['cr_emails'][] = $email;
    $cid = $this->postJson('/api/v1/ctr/invitations', ['ruc' => '20100070970', 'legal_name' => 'Montajes Andinos SAC', 'admin_email' => $email, 'admin_name' => 'Rosa Admin',
        'services_description' => 'Mantenimiento eléctrico', 'risk_level' => 'high'], $main())->assertCreated()->json('data.contractor_id');
    $url = null;
    Mail::assertSent(AccountInvitationMail::class, function ($m) use (&$url) {
        $url = $m->url;

        return true;
    });
    $this->postJson('/api/v1/auth/invitations/'.basename((string) $url).'/accept', ['password' => DEMO_PASSWORD, 'password_confirmation' => DEMO_PASSWORD])->assertOk();
    // Otro contratista de la empresa principal, que el contratista no debe ver.
    $other = $this->postJson('/api/v1/records/CTR/contractors', ['ruc' => '20100047218', 'legal_name' => 'Otra Contratista SAC'], $main())->assertCreated()->json('data.id');

    $base = crBase(crManage($main()), 'contractor_admin')['permissions'];
    $code = $this->postJson('/api/v1/company/roles', ['base_role_code' => 'contractor_admin', 'name' => 'Contratista sin firmas', 'permissions' => array_slice($base, 1)], $main())
        ->assertCreated()->json('data.code');
    $membership = collect($this->getJson('/api/v1/company/users', $main())->json('data'))->firstWhere('email', $email)['id'];
    $this->patchJson("/api/v1/company/users/{$membership}", ['role_code' => $code], $main())->assertOk();

    $ch = authHeaders(tokenFor($email), HUAYLAS_ID);
    $this->getJson('/api/v1/ctr/portal', $ch)->assertOk()->assertJsonPath('data.contractor.id', $cid);
    $ids = collect($this->getJson('/api/v1/records/CTR/contractors', $ch)->assertOk()->json('data'))->pluck('id');
    expect($ids)->toContain($cid)->not->toContain($other);
    expect($this->getJson('/api/v1/me/permissions', $ch)->json('data'))->not->toContain($base[0]);
});

it('no elimina un rol con personas activas salvo que se reasignen', function () {
    $sup = crBase(crManage(crH()), 'sst_supervisor')['permissions'];
    $role = $this->postJson('/api/v1/company/roles', ['base_role_code' => 'sst_supervisor', 'name' => 'Supervisor de turno', 'permissions' => array_slice($sup, 0, 10)], crH())
        ->assertCreated()->json('data');
    [$membership] = crInviteAndAccept(crH(), $role['code']);

    $this->deleteJson("/api/v1/company/roles/{$role['id']}", [], crH())->assertStatus(409)->assertJsonPath('members', 1);
    $this->deleteJson("/api/v1/company/roles/{$role['id']}", ['reassign_to' => 'no_existe'], crH())->assertStatus(422);
    $this->deleteJson("/api/v1/company/roles/{$role['id']}", ['reassign_to' => 'sst_supervisor'], crH())->assertOk()->assertJsonPath('data.reassigned', 1);

    expect(collect($this->getJson('/api/v1/company/users', crH())->json('data'))->firstWhere('id', $membership)['role_code'])->toBe('sst_supervisor')
        ->and(collect(crManage(crH())['custom_roles'])->pluck('id'))->not->toContain($role['id']);
    expect(collect($this->getJson("/api/v1/company/audit?entity_id={$role['id']}", crH())->json('data'))->pluck('action'))->toContain('role_deleted');

    // Uno sin personas se elimina directamente.
    $free = $this->postJson('/api/v1/company/roles', ['base_role_code' => 'sst_supervisor', 'name' => 'Rol sin uso', 'permissions' => array_slice($sup, 0, 2)], crH())->json('data.id');
    $this->deleteJson("/api/v1/company/roles/{$free}", [], crH())->assertOk()->assertJsonPath('data.reassigned', 0);
});
