<?php

// Panel de inicio (M-DSH SCR-DSH-01): programa anual, índices mensuales, acciones abiertas, mapa de calor del IPERC
// vigente, actividad reciente sin datos médicos y Panel de Gerencia.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function home(): array
{
    return authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
}

it('devuelve los bloques del inicio con datos reales', function () {
    $res = $this->getJson('/api/v1/dsh/home', home())->assertOk();

    expect($res->json('data'))->toHaveKeys(['year', 'passt', 'monthly', 'actions', 'heatmap', 'activity'])
        ->and($res->json('data'))->not->toHaveKey('management');
    if ($res->json('data.passt')) {
        expect($res->json('data.passt.bars'))->toHaveCount(5)
            ->and(array_column($res->json('data.passt.bars'), 'label'))->toContain('Simulacros');
    }
    if ($res->json('data.monthly')) {
        expect($res->json('data.monthly.current.months.0'))->toHaveKeys(['month', 'fi', 'si', 'ai']);
    }
});

it('lista las acciones correctivas abiertas con su responsable', function () {
    $h = home();
    $user = collect($this->getJson('/api/v1/records/ACR/corrective_actions/options/responsible_user_id', $h)->json('data'))->first();
    $id = $this->postJson('/api/v1/records/ACR/corrective_actions', ['code' => 'AC-HOME-1', 'source_type' => 'other', 'title' => 'Colocar guarda',
        'description' => 'Pedido del comité', 'responsible_user_id' => $user['id'], 'due_on' => now('America/Lima')->toDateString()], $h)->assertCreated()->json('data.id');
    DB::update("update corrective_actions set due_on = date '2000-01-01' where id = ?", [$id]);

    $a = $this->getJson('/api/v1/dsh/home', $h)->assertOk()->json('data.actions');
    expect($a['open'])->toBeGreaterThanOrEqual(1)
        ->and($a['items'][0]['code'])->toBe('AC-HOME-1')
        ->and($a['items'][0]['responsible'])->not->toBeEmpty();
});

it('cuenta en el mapa de calor las filas de la matriz IPERC vigente', function () {
    $h = home();
    $criteria = collect($this->getJson('/api/v1/records/IPC/iperc_matrices/options/risk_criteria_id?q=R.M.', $h)->json('data'))->first()['id'];
    $matrix = $this->postJson('/api/v1/records/IPC/iperc_matrices', ['code' => 'HOME-1', 'title' => 'Almacén', 'risk_criteria_id' => $criteria], $h)->json('data.id');
    $version = $this->getJson("/api/v1/ipc/matrices/{$matrix}/workspace", $h)->json('data.version_id');
    $this->postJson('/api/v1/records/IPC/iperc_rows', ['iperc_version_id' => $version, 'activity' => 'Despacho', 'task' => 'Apilar cajas',
        'hazard' => 'Carga suspendida', 'risk' => 'Golpe', 'probability_detail' => ['exposed_people' => 3, 'procedures' => 3, 'training' => 3, 'exposure' => 3],
        'severity_value' => 3], $h)->assertCreated();
    $before = $this->getJson('/api/v1/dsh/home', $h)->json('data.heatmap.total');

    // Al quedar vigente, la fila entra al mapa de calor en la celda alta × alta.
    DB::update("update iperc_versions set status = 'current' where id = ?", [$version]);
    DB::update('update iperc_matrices set current_version_id = ? where id = ?', [$version, $matrix]);
    $heat = $this->getJson('/api/v1/dsh/home', $h)->json('data.heatmap');

    expect($heat['total'])->toBe($before + 1);
    $cell = collect($heat['cells'])->first(fn ($c) => collect($c['rows'])->contains('matrix_id', $matrix));
    expect($cell['p'])->toBe(3)->and($cell['s'])->toBe(3)
        ->and(collect($cell['rows'])->firstWhere('matrix_id', $matrix)['hazard'])->toBe('Carga suspendida');
});

it('muestra la actividad reciente sin módulos médicos', function () {
    $h = home();
    $this->postJson('/api/v1/records/TEN/positions', ['code' => 'HOME-P', 'name' => 'Montacarguista'], $h)->assertCreated();
    DB::insert("insert into audit_logs (company_id, actor_type, action, module_code, entity_type, changes) values (?, 'system', 'create', 'EMO', 'medical_exam_orders', '{}'::jsonb)", [ANDINAS_ID]);

    $act = $this->getJson('/api/v1/dsh/home', $h)->assertOk()->json('data.activity');
    expect($act)->not->toBeEmpty()
        ->and(collect($act)->pluck('module')->all())->not->toContain('EMO')
        ->and($act[0])->toHaveKeys(['actor', 'verb', 'what', 'at']);
});

it('arma el Panel de Gerencia con cumplimiento, accidentes, pendientes y sedes', function () {
    $m = $this->getJson('/api/v1/dsh/home?view=management', home())->assertOk()->json('data.management');

    expect($m)->toHaveKeys(['compliance', 'accidents', 'sunafil', 'accident_rate', 'pending', 'sites', 'critical'])
        ->and($m['sites'])->toBeArray()
        ->and($m['pending'])->toBeArray();
});

it('valida el año y la sede', function () {
    $this->getJson('/api/v1/dsh/home?year=1990', home())->assertStatus(400);
    $this->getJson('/api/v1/dsh/home?site_id=00000000-0000-0000-0000-000000000000', home())->assertNotFound();
});
