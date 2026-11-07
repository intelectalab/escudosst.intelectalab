<?php

// Selector de empresa, contexto X-Company-Id, aislamiento RLS y feature flags
// (docs/08-api.md §3, docs/01-arquitectura.md §4).

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

it('lista solo las empresas donde el usuario tiene membresía activa', function (string $email, string $companyId, string $plan) {
    $res = $this->getJson('/api/v1/me/companies', authHeaders(loginAs($email)))->assertOk();

    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0.company_id'))->toBe($companyId)
        ->and($res->json('data.0.role.code'))->toBe('sst_manager');
})->with([
    'Esencial' => [ESENCIAL_EMAIL, AGRO_ID, 'E'],
    'Profesional' => [PROFESIONAL_EMAIL, ANDINAS_ID, 'P'],
    'Corporativo' => [CORPORATIVO_EMAIL, HUAYLAS_ID, 'C'],
]);

it('cambia de empresa solo si tiene membresía', function () {
    $token = loginAs(PROFESIONAL_EMAIL);

    $this->postJson('/api/v1/me/switch-company', ['company_id' => ANDINAS_ID], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('company_id', ANDINAS_ID);

    $this->postJson('/api/v1/me/switch-company', ['company_id' => HUAYLAS_ID], authHeaders($token))
        ->assertStatus(404);
});

it('exige X-Company-Id en las rutas de empresa', function () {
    $this->getJson('/api/v1/me/permissions', authHeaders(loginAs(PROFESIONAL_EMAIL)))
        ->assertStatus(400)
        ->assertJsonPath('type', 'bad_request');
});

it('responde 404 para una empresa sin membresía, sin revelar si existe', function () {
    $token = loginAs(PROFESIONAL_EMAIL);

    $this->getJson('/api/v1/me/permissions', authHeaders($token, HUAYLAS_ID))
        ->assertStatus(404)
        ->assertJsonPath('type', 'not_found');

    $this->getJson('/api/v1/me/permissions', authHeaders($token, '01900000-0000-7000-8000-00000000ffff'))
        ->assertStatus(404);
});

it('recorta los permisos del mismo rol según el plan', function () {
    $permissions = fn (string $email, string $company) => collect(
        $this->getJson('/api/v1/me/permissions', authHeaders(loginAs($email), $company))->assertOk()->json('data')
    );

    $esencial = $permissions(ESENCIAL_EMAIL, AGRO_ID);
    $profesional = $permissions(PROFESIONAL_EMAIL, ANDINAS_ID);
    $corporativo = $permissions(CORPORATIVO_EMAIL, HUAYLAS_ID);

    $hasModule = fn ($perms, string $code) => $perms->contains(fn ($p) => str_starts_with($p, "{$code}."));

    // IPERC está en todos; Mapa de riesgos desde Profesional; Presupuesto solo en Corporativo.
    expect($hasModule($esencial, 'ipc'))->toBeTrue()
        ->and($hasModule($esencial, 'map'))->toBeFalse()
        ->and($hasModule($profesional, 'map'))->toBeTrue()
        ->and($hasModule($profesional, 'pre'))->toBeFalse()
        ->and($hasModule($corporativo, 'pre'))->toBeTrue()
        ->and($esencial->count())->toBeLessThan($profesional->count())
        ->and($profesional->count())->toBeLessThan($corporativo->count());
});

it('muestra el modo de cada módulo según el plan', function () {
    $modes = fn (string $email, string $company) => collect(
        $this->getJson('/api/v1/me/modules', authHeaders(loginAs($email), $company))->assertOk()->json('data')
    )->pluck('mode', 'code');

    $esencial = $modes(ESENCIAL_EMAIL, AGRO_ID);
    expect($esencial['TRB'])->toBe('enabled')
        ->and($esencial['FIR'])->toBe('locked')
        ->and($esencial->filter(fn ($m) => $m === 'enabled'))->toHaveCount(22);

    $profesional = $modes(PROFESIONAL_EMAIL, ANDINAS_ID);
    expect($profesional['FIR'])->toBe('enabled')
        ->and($profesional['PRE'])->toBe('locked')
        ->and($profesional['VTA'])->toBe('disabled_by_company');

    expect($modes(CORPORATIVO_EMAIL, HUAYLAS_ID)['PRE'])->toBe('enabled');
});

it('aísla los datos por empresa con RLS aunque la consulta no filtre', function () {
    $userId = DB::selectOne('select id from app_auth_lookup_user(?)', [PROFESIONAL_EMAIL])->id;

    $companiesSeen = function (string $companyId) use ($userId) {
        return DB::transaction(function () use ($companyId, $userId) {
            DB::select("select set_config('app.user_id', ?, true), set_config('app.company_id', ?, true)", [$userId, $companyId]);

            // Sin WHERE company_id: RLS debe filtrar solo.
            return collect(DB::select('select distinct company_id from memberships'))->pluck('company_id')->all();
        });
    };

    expect($companiesSeen(ANDINAS_ID))->toBe([ANDINAS_ID])
        ->and($companiesSeen(HUAYLAS_ID))->toBe([HUAYLAS_ID]);
});

it('bloquea un módulo fuera del plan con module_not_in_plan', function () {
    Route::middleware(['api', 'auth:sanctum', 'company', 'module:PRE'])
        ->get('/api/v1/_test/pre', fn () => ['ok' => true]);

    $this->getJson('/api/v1/_test/pre', authHeaders(loginAs(PROFESIONAL_EMAIL), ANDINAS_ID))
        ->assertStatus(403)
        ->assertJsonPath('type', 'module_not_in_plan')
        ->assertJsonPath('module', 'PRE')
        ->assertJsonPath('required_plan', 'C');

    $this->getJson('/api/v1/_test/pre', authHeaders(loginAs(CORPORATIVO_EMAIL), HUAYLAS_ID))->assertOk();
});

it('exige el permiso module.resource.action del rol', function () {
    Route::middleware(['api', 'auth:sanctum', 'company', 'permission:trb.workers.read'])
        ->get('/api/v1/_test/workers', fn () => ['ok' => true]);
    Route::middleware(['api', 'auth:sanctum', 'company', 'permission:sus.plans.update'])
        ->get('/api/v1/_test/plans', fn () => ['ok' => true]);

    $token = loginAs(PROFESIONAL_EMAIL);

    $this->getJson('/api/v1/_test/workers', authHeaders($token, ANDINAS_ID))->assertOk();
    $this->getJson('/api/v1/_test/plans', authHeaders($token, ANDINAS_ID))
        ->assertStatus(403)
        ->assertJsonPath('type', 'forbidden');
});
