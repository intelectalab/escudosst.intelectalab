<?php

use Illuminate\Support\Facades\DB;

// Tablero de indicadores (M-DSH): valores reales con numerador/denominador, «No aplica», bloqueo por plan,
// filtro por sede, protección de datos de salud y alertas.

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function dsh(string $email = PROFESIONAL_EMAIL, string $company = ANDINAS_ID): array
{
    return authHeaders(tokenFor($email), $company);
}

function kpi(array $kpis, string $code): array
{
    return collect($kpis)->firstWhere('code', $code);
}

it('muestra «No aplica» cuando no hay nada que medir (criterio 4, RN-DSH-08)', function () {
    $res = $this->getJson('/api/v1/dsh/overview', dsh())->assertOk();
    $insp = kpi($res->json('data.kpis'), 'inspection_compliance');

    expect($insp['locked'])->toBeFalse();
    if ($insp['denominator'] === 0) {
        expect($insp['label'])->toBe('No aplica')->and($insp['color'])->toBeNull();
    }
    expect($res->json('data.counts'))->toHaveKeys(['workers', 'sites', 'positions']);
});

it('bloquea los indicadores de módulos fuera del plan (criterio 1)', function () {
    // EMO es del plan Profesional: la empresa Esencial lo ve bloqueado con el plan necesario.
    $emo = kpi($this->getJson('/api/v1/dsh/overview', dsh(ESENCIAL_EMAIL, AGRO_ID))->assertOk()->json('data.kpis'), 'emo_valid');

    expect($emo['locked'])->toBeTrue()->and($emo['required_plan'])->toBe('Profesional')->and($emo)->not->toHaveKey('value');
});

it('calcula el cumplimiento de inspecciones con su numerador y denominador (RN-DSH-03)', function () {
    $h = dsh();
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'DSH-S', 'name' => 'Sede tablero'], $h)->json('data.id');
    $year = now('America/Lima')->year;
    $day = now('America/Lima')->subDay()->toDateString();
    foreach (['completed', 'completed', 'completed', 'scheduled'] as $i => $status) {
        $this->postJson('/api/v1/records/INS/inspections', ['code' => "INS-D{$i}", 'title' => "Inspección {$i}", 'inspection_type' => 'planned',
            'site_id' => $site, 'scheduled_on' => $day, 'status' => $status], $h)->assertCreated();
    }

    $k = kpi($this->getJson("/api/v1/dsh/overview?year={$year}&site_id={$site}", $h)->assertOk()->json('data.kpis'), 'inspection_compliance');
    expect($k['numerator'])->toBe(3)->and($k['denominator'])->toBe(4)->and($k['value'])->toEqual(75)
        ->and($k['label'])->toBe('75 %')->and($k['color'])->toBe('amber');

    $alerts = $this->getJson("/api/v1/dsh/overview?site_id={$site}", $h)->json('data.alerts');
    expect(collect($alerts)->firstWhere('module', 'INS')['count'])->toBe(1);
});

it('cuenta acciones correctivas vencidas', function () {
    $h = dsh();
    $user = collect($this->getJson('/api/v1/records/ACR/corrective_actions/options/responsible_user_id', $h)->json('data'))->first()['id'];
    $id = $this->postJson('/api/v1/records/ACR/corrective_actions', ['code' => 'AC-DSH-1', 'source_type' => 'other', 'description' => 'Pedido del comité',
        'title' => 'Reparar baranda', 'responsible_user_id' => $user, 'due_on' => now('America/Lima')->toDateString()], $h)->assertCreated()->json('data.id');
    \Illuminate\Support\Facades\DB::update("update corrective_actions set due_on = due_on - 3 where id = ?", [$id]);

    $res = $this->getJson('/api/v1/dsh/overview', $h)->json('data');
    expect(kpi($res['kpis'], 'corrective_actions_overdue')['value'])->toBeGreaterThanOrEqual(1)
        ->and(collect($res['alerts'])->firstWhere('module', 'ACR')['count'])->toBeGreaterThanOrEqual(1)
        ->and(collect($res['alerts'])->firstWhere('module', 'ACR')['link']['filters'])->toBe(['status' => 'open,in_progress,overdue', 'due_on__lt' => 'today'])
        ->and(kpi($res['kpis'], 'corrective_actions_overdue')['link']['table'])->toBe('corrective_actions');
});

it('no muestra la aptitud médica con grupos pequeños (criterio 5, RN-DSH-05)', function () {
    $h = dsh();
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'DSH-M', 'name' => 'Sede pequeña'], $h)->json('data.id');
    $pos = $this->postJson('/api/v1/records/TEN/positions', ['code' => 'DSH-P', 'name' => 'Operario'], $h)->json('data.id');
    foreach (['11111111', '22222222'] as $i => $dni) {
        $this->postJson('/api/v1/records/TRB/workers', ['document_number' => $dni, 'first_name' => "T{$i}", 'paternal_last_name' => 'Prueba',
            'current_site_id' => $site, 'current_position_id' => $pos], $h)->assertCreated();
    }

    $emo = kpi($this->getJson("/api/v1/dsh/overview?site_id={$site}", $h)->json('data.kpis'), 'emo_valid');
    expect($emo['label'])->toBe('No aplica')->and($emo['basis'])->toContain('proteger datos de salud');
});

it('valida el año y la sede', function () {
    $this->getJson('/api/v1/dsh/overview?year=1990', dsh())->assertStatus(400);
    $this->getJson('/api/v1/dsh/overview?site_id=no-uuid', dsh())->assertNotFound();
});

it('muestra el tablero consolidado solo con las empresas del usuario, cada una con sus datos (SCR-DSH-04)', function () {
    // El Jefe SST de Huaylas también trabaja en Andinas (mismo usuario, otra membresía).
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $uid = DB::selectOne('select id from users where email = ?', [CORPORATIVO_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $role = DB::selectOne("select id from roles where account_id is null and code = 'sst_manager'")->id;
    DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, ?, 'active', now())", [ANDINAS_ID, $uid, $role]);
    DB::select("select set_config('app.company_id', '', false)");

    // Una acción vencida en Andinas: debe contarse solo en Andinas.
    $ha = authHeaders(tokenFor(CORPORATIVO_EMAIL), ANDINAS_ID);
    $this->postJson('/api/v1/records/ACR/corrective_actions', ['title' => 'Vencida grupo', 'action_type' => 'corrective', 'due_on' => now()->subDays(3)->toDateString()], $ha);

    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $res = $this->getJson('/api/v1/dsh/group', $h)->assertOk();
    $companies = collect($res->json('data.companies'));
    expect($companies->pluck('company_id')->sort()->values()->all())->toBe(collect([ANDINAS_ID, HUAYLAS_ID])->sort()->values()->all())
        ->and($companies->firstWhere('company_id', HUAYLAS_ID)['is_active'])->toBeTrue()
        ->and($res->json('data.columns'))->not->toBeEmpty();
    $kpi = collect($companies->first()['kpis'])->firstWhere('locked', false);
    expect($kpi)->toHaveKeys(['code', 'label', 'link']);

    // Otro usuario sin esa membresía solo ve su empresa.
    $p = $this->getJson('/api/v1/dsh/group', dsh())->assertOk();
    expect(collect($p->json('data.companies'))->pluck('company_id')->all())->toBe([ANDINAS_ID]);
});
