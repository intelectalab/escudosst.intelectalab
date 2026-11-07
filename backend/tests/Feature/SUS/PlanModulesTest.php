<?php

// SCR-SUS-01 · Planes y módulos (docs/modulos/M-SUS.md, docs/08-api.md §16).
//
// La conexión 'platform' no entra en la transacción de la prueba, así que se guarda el estado de
// plan_modules y feature_flags antes de cada prueba y se restaura después.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $db = DB::connection('platform');
    $this->snapshotModules = $db->select('select plan_id, module_code, inclusion from plan_modules');
    $this->snapshotFlags = $db->select('select id, mode, source from feature_flags');
    $this->snapshotPlans = $db->select('select * from plans');
    $this->snapshotAddons = $db->select('select * from addons');
});

afterEach(function () {
    $db = DB::connection('platform');
    $db->transaction(function () use ($db) {
        $db->delete('delete from plan_modules');
        foreach ($this->snapshotModules as $r) {
            $db->insert('insert into plan_modules (plan_id, module_code, inclusion) values (?, ?, ?)', [$r->plan_id, $r->module_code, $r->inclusion]);
        }
        foreach ($this->snapshotFlags as $f) {
            $db->update('update feature_flags set mode = ?, source = ? where id = ?', [$f->mode, $f->source, $f->id]);
        }
        foreach ($this->snapshotPlans as $p) {
            $row = (array) $p;
            unset($row['id'], $row['created_at'], $row['updated_at']);
            $db->table('plans')->where('id', $p->id)->update($row);
        }
        foreach ($this->snapshotAddons as $a) {
            $row = (array) $a;
            unset($row['id'], $row['created_at'], $row['updated_at']);
            $db->table('addons')->where('id', $a->id)->update($row);
        }
    });
});

function moduleMode(string $email, string $companyId, string $code): array
{
    $m = collect(test()->getJson('/api/v1/me/modules', authHeaders(tokenFor($email), $companyId))->assertOk()->json('data'))
        ->firstWhere('code', $code);

    return ['mode' => $m['mode'], 'required_plan' => $m['required_plan']];
}

function togglePlanModule(string $plan, string $module, bool $included)
{
    return test()->putJson("/api/v1/internal/plans/{$plan}/modules/{$module}", ['included' => $included], authHeaders(tokenFor(ADMIN_EMAIL)));
}

it('solo el equipo de la plataforma entra al panel interno', function () {
    $this->getJson('/api/v1/internal/plans', authHeaders(tokenFor(PROFESIONAL_EMAIL)))
        ->assertStatus(403)
        ->assertJsonPath('type', 'forbidden');

    app('auth')->forgetGuards();
    $this->getJson('/api/v1/internal/plans')->assertStatus(401);
});

it('el superadmin exige segundo factor al ingresar', function () {
    $this->postJson('/api/v1/auth/login', ['email' => ADMIN_EMAIL, 'password' => DEMO_PASSWORD])
        ->assertOk()
        ->assertJsonPath('mfa_required', true);
});

it('muestra la matriz de 61 módulos, los 3 planes y los complementos', function () {
    $res = $this->getJson('/api/v1/internal/plans', authHeaders(tokenFor(ADMIN_EMAIL)))->assertOk();

    expect($res->json('modules'))->toHaveCount(61)
        ->and(collect($res->json('plans'))->pluck('modules_count', 'code')->all())->toBe(['E' => 22, 'P' => 39, 'C' => 51, 'K' => 1])
        ->and($res->json('addons'))->toHaveCount(7);

    $mrl = collect($res->json('modules'))->firstWhere('code', 'MRL');
    expect($mrl['plans'])->toBe(['E' => null, 'P' => 'native', 'C' => 'inherited'])
        ->and($mrl['assignable'])->toBeTrue();
});

it('quitar un módulo de un plan lo deja en solo lectura en las empresas de ese plan, sin tocar los superiores', function () {
    expect(moduleMode(PROFESIONAL_EMAIL, ANDINAS_ID, 'MRL')['mode'])->toBe('enabled');

    togglePlanModule('P', 'MRL', false)
        ->assertOk()
        ->assertJsonPath('changed', [['plan' => 'P', 'included' => false]])
        ->assertJsonPath('companies_refreshed', 1);

    expect(moduleMode(PROFESIONAL_EMAIL, ANDINAS_ID, 'MRL'))->toBe(['mode' => 'read_only', 'required_plan' => 'C'])
        ->and(moduleMode(CORPORATIVO_EMAIL, HUAYLAS_ID, 'MRL')['mode'])->toBe('enabled');

    // Al volver a incluirlo, se habilita otra vez.
    togglePlanModule('P', 'MRL', true)->assertOk();
    expect(moduleMode(PROFESIONAL_EMAIL, ANDINAS_ID, 'MRL')['mode'])->toBe('enabled');
});

it('incluir un módulo en Esencial lo agrega también a los planes superiores y lo activa en la empresa', function () {
    expect(moduleMode(ESENCIAL_EMAIL, AGRO_ID, 'MRL'))->toBe(['mode' => 'locked', 'required_plan' => 'P']);

    togglePlanModule('E', 'MRL', true)
        ->assertOk()
        ->assertJsonPath('changed', [['plan' => 'E', 'included' => true]]);

    expect(moduleMode(ESENCIAL_EMAIL, AGRO_ID, 'MRL'))->toBe(['mode' => 'enabled', 'required_plan' => 'E']);

    $matrix = $this->getJson('/api/v1/internal/plans', authHeaders(tokenFor(ADMIN_EMAIL)))->json('modules');
    expect(collect($matrix)->firstWhere('code', 'MRL')['plans'])->toBe(['E' => 'native', 'P' => 'inherited', 'C' => 'inherited']);
});

it('quitar un módulo de Corporativo lo quita también de los planes inferiores', function () {
    togglePlanModule('C', 'MRL', false)
        ->assertOk()
        ->assertJsonPath('changed', [['plan' => 'P', 'included' => false], ['plan' => 'C', 'included' => false]]);

    expect(moduleMode(PROFESIONAL_EMAIL, ANDINAS_ID, 'MRL')['mode'])->toBe('read_only')
        ->and(moduleMode(CORPORATIVO_EMAIL, HUAYLAS_ID, 'MRL')['mode'])->toBe('read_only');
});

it('no deja quitar el núcleo ni asignar complementos a un plan', function () {
    togglePlanModule('E', 'TEN', false)->assertStatus(409)->assertJsonPath('title', 'Módulo del núcleo');
    togglePlanModule('C', 'OFF', true)->assertStatus(409)->assertJsonPath('title', 'Este módulo no se asigna a planes');
    togglePlanModule('X', 'MRL', true)->assertStatus(404);
    $this->putJson('/api/v1/internal/plans/P/modules/MRL', ['included' => 'si'], authHeaders(tokenFor(ADMIN_EMAIL)))->assertStatus(422);
});

it('registra cada cambio en la bitácora como evento de plataforma', function () {
    togglePlanModule('P', 'MAP', false)->assertOk();

    $log = DB::connection('platform')->selectOne(
        "select action, changes from audit_logs where company_id is null and entity_type = 'plan_modules' order by occurred_at desc limit 1"
    );
    $changes = json_decode($log->changes, true);

    expect($log->action)->toBe('plan_module_removed')
        ->and($changes['module'])->toBe('MAP')
        ->and($changes['before'])->toBe(['P', 'C'])
        ->and($changes['after'])->toBe(['C']);
});

it('en el menú de la empresa el rol ve solo módulos que puede usar', function () {
    $mods = collect($this->getJson('/api/v1/me/modules', authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID))->json('data'));

    // El Jefe SST puede usar IPERC; los internos (SUS, WEB) no aparecen en la lista.
    expect($mods->firstWhere('code', 'IPC')['role_can_use'])->toBeTrue()
        ->and($mods->pluck('code'))->not->toContain('WEB');
});

// ---------------------------------------------------------------- Datos de planes y complementos

it('edita los datos, el precio y los límites de un plan y lo registra en la bitácora', function () {
    $this->patchJson('/api/v1/internal/plans/P', [
        'name' => 'Profesional Plus',
        'description' => 'Para empresas con Comité SST.',
        'price_reference' => 350.5,
        'billing_periods' => ['monthly'],
        'is_public' => false,
        'is_active' => true,
        'limits' => ['max_workers' => 200, 'storage_gb' => 50],
    ], authHeaders(tokenFor(ADMIN_EMAIL)))->assertNoContent();

    $p = collect($this->getJson('/api/v1/internal/plans', authHeaders(tokenFor(ADMIN_EMAIL)))->json('plans'))->firstWhere('code', 'P');
    expect($p['name'])->toBe('Profesional Plus')
        ->and($p['price_reference'])->toBe('350.50')
        ->and($p['billing_periods'])->toBe(['monthly'])
        ->and($p['is_public'])->toBeFalse()
        ->and($p['limits']['max_workers'])->toBe(200)
        ->and($p['limits']['max_sites'])->toBeNull();

    $log = DB::connection('platform')->selectOne("select action, changes from audit_logs where entity_type = 'plans' order by occurred_at desc limit 1");
    expect($log->action)->toBe('plan_updated')
        ->and(json_decode($log->changes, true))->toHaveKeys(['name', 'price_reference', 'is_public', 'billing_periods']);
});

it('valida los datos del plan con mensajes en español', function () {
    $res = $this->patchJson('/api/v1/internal/plans/E', [
        'name' => '', 'price_reference' => -5, 'billing_periods' => [], 'is_public' => true, 'is_active' => true,
        'limits' => ['max_workers' => -1],
    ], authHeaders(tokenFor(ADMIN_EMAIL)));

    $res->assertStatus(422)
        ->assertJsonPath('errors.name.0', 'Escribe el nombre del plan.')
        ->assertJsonPath('errors.price_reference.0', 'El precio no puede ser negativo.')
        ->assertJsonPath('errors.billing_periods.0', 'Elige al menos un periodo de cobro.');
    expect($res->json('errors')['limits.max_workers'][0])->toBe('Los límites no pueden ser negativos.');
});

it('no deja editar planes que no existen ni a usuarios de empresa', function () {
    $body = ['name' => 'Plan X', 'billing_periods' => ['monthly'], 'is_public' => true, 'is_active' => true];
    $this->patchJson('/api/v1/internal/plans/I', $body, authHeaders(tokenFor(ADMIN_EMAIL)))->assertStatus(404);
    $this->patchJson('/api/v1/internal/plans/P', $body, authHeaders(tokenFor(PROFESIONAL_EMAIL)))->assertStatus(403);
});

it('edita un complemento: precio y estado', function () {
    $this->patchJson('/api/v1/internal/addons/OFF', [
        'name' => 'App móvil offline', 'description' => 'Registro sin señal.', 'price_reference' => 99, 'is_active' => false,
    ], authHeaders(tokenFor(ADMIN_EMAIL)))->assertNoContent();

    $a = collect($this->getJson('/api/v1/internal/plans', authHeaders(tokenFor(ADMIN_EMAIL)))->json('addons'))->firstWhere('code', 'OFF');
    expect($a['price_reference'])->toBe('99.00')->and($a['is_active'])->toBeFalse();

    $this->patchJson('/api/v1/internal/addons/XYZ', ['name' => 'Otro complemento', 'is_active' => true], authHeaders(tokenFor(ADMIN_EMAIL)))->assertStatus(404);
});
