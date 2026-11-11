<?php

// Prueba de humo de TODOS los módulos con registros (resources/registry/registry.json):
//  - por cada tabla, con un rol que tiene acceso (Jefe SST de la empresa Corporativo, con los complementos activos):
//    ficha del módulo, lista paginada, opciones de cada referencia y detalle del primer registro;
//  - por cada módulo, un rol sin acceso (rol personalizado con un solo permiso de otro módulo) y el trabajador: 403 donde no tiene el permiso;
//  - por cada módulo, la empresa Esencial: los módulos fuera de su plan responden «no incluido en tu plan».

use App\Core\Auth\Permissions;
use App\Modules\USR\Mail\AccountInvitationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

function qaRegistry(): array
{
    static $r;

    return $r ??= json_decode((string) file_get_contents(__DIR__.'/../../../resources/registry/registry.json'), true);
}

/** Un caso por módulo y tabla: «INS · inspections». */
function qaTablesDataset(): array
{
    $out = [];
    foreach (qaRegistry()['modules'] as $module => $tables) {
        foreach ($tables as $table) {
            $out["{$module} · {$table}"] = [$module, $table];
        }
    }

    return $out;
}

function qaModulesDataset(): array
{
    $out = [];
    foreach (array_keys(qaRegistry()['modules']) as $module) {
        $out[$module] = [$module];
    }

    return $out;
}

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $GLOBALS['qa_smoke_emails'] = [];
    Mail::fake();
    // Complementos (y lo que el plan Corporativo no trae) activos para la empresa de prueba.
    $db = DB::connection('platform');
    $this->qaFlags = $db->select("select id, mode, source from feature_flags where company_id = ? and mode <> 'enabled' and deleted_at is null", [HUAYLAS_ID]);
    $db->update("update feature_flags set mode = 'enabled' where company_id = ? and mode <> 'enabled' and deleted_at is null", [HUAYLAS_ID]);
    Permissions::flush();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    $db = DB::connection('platform');
    foreach ($this->qaFlags as $f) {
        $db->update('update feature_flags set mode = ?, source = ? where id = ?', [$f->mode, $f->source, $f->id]);
    }
    Permissions::flush();
    // Las personas invitadas quedan creadas por la conexión de la plataforma: baja lógica (la de la base no las ve por RLS).
    if ($GLOBALS['qa_smoke_emails']) {
        $db->update('update users set deleted_at = now() where deleted_at is null and lower(email) = any(?::text[])', ['{'.implode(',', $GLOBALS['qa_smoke_emails']).'}']);
    }
});

/** Invita a la empresa Corporativo con un rol, acepta la invitación e inicia sesión. Devuelve los encabezados. */
function qaSmokeUser(string $roleCode): array
{
    $email = 'qa.smoke.'.Str::lower(Str::random(10)).'@escudo.test';
    $GLOBALS['qa_smoke_emails'][] = $email;
    test()->postJson('/api/v1/company/users', ['email' => $email, 'full_name' => 'Persona QA', 'role_code' => $roleCode], authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID))
        ->assertCreated();
    $url = null;
    Mail::assertSent(AccountInvitationMail::class, function ($m) use (&$url, $email) {
        if ($m->hasTo($email)) {
            $url = $m->url;
        }

        return true;
    });
    test()->postJson('/api/v1/auth/invitations/'.basename((string) $url).'/accept', ['password' => DEMO_PASSWORD, 'password_confirmation' => DEMO_PASSWORD])->assertOk();

    return authHeaders(tokenFor($email), HUAYLAS_ID);
}

function qaEffective(array $h): array
{
    return test()->getJson('/api/v1/me/permissions', $h)->assertOk()->json('data');
}

it('con acceso: ficha, lista paginada, opciones de referencias y detalle', function (string $module, string $table) {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $res = qaRegistry()['resources'][$table];
    $base = "/api/v1/records/{$module}";
    $perm = strtolower($module).".{$table}.read";

    $schema = $this->getJson($base, $h);
    expect($schema->status())->toBe(200, "ficha {$module}: ".$schema->getContent());
    $tables = collect($schema->json('resources'))->pluck('table');

    if (! in_array($perm, qaEffective($h), true)) {
        // Tabla que ningún rol lee (p. ej. respuestas anónimas): no aparece en la ficha y la lista responde 403.
        expect($tables)->not->toContain($table);
        $this->getJson("{$base}/{$table}", $h)->assertForbidden();

        return;
    }
    expect($tables)->toContain($table);

    $list = $this->getJson("{$base}/{$table}?per_page=5", $h);
    expect($list->status())->toBe(200, "lista {$module}/{$table}: ".$list->getContent());
    $list->assertJsonStructure(['data', 'meta' => ['page', 'per_page', 'total', 'pages']]);
    expect($list->json('meta.page'))->toBe(1)->and($list->json('meta.per_page'))->toBe(5);

    foreach ($res['fields'] as $f) {
        if ($f['type'] !== 'ref') {
            continue;
        }
        $o = $this->getJson("{$base}/{$table}/options/{$f['name']}", $h);
        expect($o->status())->toBe(200, "opciones {$table}.{$f['name']}: ".$o->getContent());
        expect($o->json('data'))->toBeArray();
    }

    $first = $list->json('data.0.id');
    if ($first) {
        $one = $this->getJson("{$base}/{$table}/{$first}", $h);
        expect($one->status())->toBe(200, "detalle {$table}/{$first}: ".$one->getContent());
        expect($one->json('data.id'))->toBe($first);
        $this->getJson("{$base}/{$table}/{$first}/history", $h)->assertOk();
        $this->getJson("{$base}/{$table}/{$first}/trace", $h)->assertOk();
    }
    // Un id que no existe no rompe nada.
    expect($this->getJson("{$base}/{$table}/".Str::uuid7(), $h)->status())->toBeIn([403, 404]);
})->with(qaTablesDataset());

it('sin acceso: un rol sin permisos del módulo y el trabajador solo ven lo que su rol permite', function (string $module) {
    // Un rol necesita al menos un permiso: el único que se deja es de otro módulo.
    $admin = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $workerPerms = collect($this->getJson('/api/v1/company/roles/manage', $admin)->assertOk()->json('data.roles'))->firstWhere('code', 'worker')['permissions'];
    $other = collect($workerPerms)->first(fn ($c) => ! str_starts_with($c, strtolower($module).'.'));
    $none = $this->postJson('/api/v1/company/roles', ['base_role_code' => 'worker', 'name' => 'Sin acceso QA '.Str::random(6), 'permissions' => [$other]], $admin)
        ->assertCreated()->json('data.code');

    $h = qaSmokeUser($none);
    expect(qaEffective($h))->toBe([$other]);
    $this->getJson("/api/v1/records/{$module}", $h)->assertOk()->assertJsonPath('resources', []);
    foreach (qaRegistry()['modules'][$module] as $table) {
        $r = $this->getJson("/api/v1/records/{$module}/{$table}", $h);
        expect($r->status())->toBe(403, "{$module}/{$table} sin permisos: ".$r->getContent());
    }

    $w = qaSmokeUser('worker');
    $perms = qaEffective($w);
    foreach (qaRegistry()['modules'][$module] as $table) {
        $r = $this->getJson("/api/v1/records/{$module}/{$table}", $w);
        $expected = in_array(strtolower($module).".{$table}.read", $perms, true) ? 200 : 403;
        expect($r->status())->toBe($expected, "{$module}/{$table} trabajador: ".$r->getContent());
    }
})->with(qaModulesDataset());

it('plan Esencial: los módulos fuera del plan responden «no incluido en tu plan»', function (string $module) {
    $h = authHeaders(tokenFor(ESENCIAL_EMAIL), AGRO_ID);
    $mode = DB::connection('platform')->selectOne('select mode from feature_flags where company_id = ? and module_code = ? and deleted_at is null', [AGRO_ID, $module])?->mode ?? 'locked';
    $table = qaRegistry()['modules'][$module][0];

    if ($mode === 'enabled') {
        $this->getJson("/api/v1/records/{$module}", $h)->assertOk()->assertJsonPath('mode', 'enabled');

        return;
    }
    foreach (["/api/v1/records/{$module}", "/api/v1/records/{$module}/{$table}"] as $url) {
        $r = $this->getJson($url, $h);
        expect($r->status())->toBe(403, "{$url}: ".$r->getContent())
            ->and($r->json('type'))->toBe('module_not_in_plan')
            ->and($r->json('module'))->toBe($module)
            ->and($r->json('detail'))->toBeString()->not->toBeEmpty();
    }
    // Tampoco se puede crear.
    $this->postJson("/api/v1/records/{$module}/{$table}", [], $h)->assertForbidden()->assertJsonPath('type', 'module_not_in_plan');
})->with(qaModulesDataset());
