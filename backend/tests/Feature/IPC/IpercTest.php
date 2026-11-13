<?php

// Matriz IPERC (M-IPC): cálculo P × S con criterios R.M. 050, residual, reglas de envío, aprobación por otra persona,
// versiones congeladas, acciones correctivas y exportación.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function ipc(): array
{
    return authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
}

/** Matriz nueva con criterios R.M. 050 y un puesto; devuelve [matriz, versión, puesto, criterios]. */
function ipcMatrix($test, string $code = 'IPERC-T1'): array
{
    $h = ipc();
    $criteria = collect($test->getJson('/api/v1/records/IPC/iperc_matrices/options/risk_criteria_id?q=R.M.', $h)->json('data'))->first()['id'];
    $position = $test->postJson('/api/v1/records/TEN/positions', ['code' => 'SOLD-'.$code, 'name' => 'Soldador'], $h)->json('data.id');
    $matrix = $test->postJson('/api/v1/records/IPC/iperc_matrices', ['code' => $code, 'title' => 'Producción', 'risk_criteria_id' => $criteria], $h)
        ->assertCreated()->json('data.id');
    $version = $test->getJson("/api/v1/ipc/matrices/{$matrix}/workspace", $h)->assertOk()->json('data.version_id');

    return [$matrix, $version, $position, $criteria];
}

function ipcRow($test, string $version, array $extra = []): \Illuminate\Testing\TestResponse
{
    return $test->postJson('/api/v1/records/IPC/iperc_rows', array_merge([
        'iperc_version_id' => $version, 'activity' => 'Soldadura', 'task' => 'Corte con amoladora', 'hazard' => 'Partículas proyectadas',
        'risk' => 'Lesión ocular', 'probability_detail' => ['exposed_people' => 2, 'procedures' => 2, 'training' => 2, 'exposure' => 3], 'severity_value' => 2,
    ], $extra), ipc());
}

it('crea la matriz con su versión 1 en borrador', function () {
    [$matrix] = ipcMatrix($this);
    $ws = $this->getJson("/api/v1/ipc/matrices/{$matrix}/workspace", ipc())->assertOk();

    expect($ws->json('data.versions'))->toHaveCount(1)
        ->and($ws->json('data.versions.0.status'))->toBe('draft')
        ->and($ws->json('data.criteria.method'))->toBe('pxs_index');
});

it('calcula probabilidad 9, puntaje 18 y nivel con los índices de la R.M. 050 (criterio 1)', function () {
    [, $version] = ipcMatrix($this);

    ipcRow($this, $version)->assertCreated()
        ->assertJsonPath('data.probability_value', 9)
        ->assertJsonPath('data.risk_score', 18)
        ->assertJsonPath('data.risk_level', 'important')
        ->assertJsonPath('data.is_significant', true)
        ->assertJsonPath('data.row_number', 1);

    ipcRow($this, $version, ['probability_detail' => ['exposed_people' => 2]])
        ->assertStatus(422)->assertJsonPath('errors.probability_detail.0', 'Elige el índice de «Procedimientos existentes».');
});

it('rechaza un riesgo residual mayor que el inicial (criterio 3)', function () {
    [, $version] = ipcMatrix($this);

    ipcRow($this, $version, ['residual_probability' => 12, 'residual_severity' => 3])
        ->assertStatus(422)->assertJsonPath('errors.residual_probability.0', 'El riesgo residual (36) no puede ser mayor que el inicial (18).');

    ipcRow($this, $version, ['residual_probability' => 5, 'residual_severity' => 1])
        ->assertCreated()->assertJsonPath('data.residual_score', 5)->assertJsonPath('data.residual_level', 'tolerable');
});

it('completa peligro y riesgo desde el catálogo general', function () {
    [, $version] = ipcMatrix($this);
    $hazard = collect($this->getJson('/api/v1/records/IPC/iperc_rows/options/hazard_catalog_id?q=a', ipc())->json('data'))->first()['id'];

    $row = ipcRow($this, $version, ['hazard' => null, 'risk' => null, 'hazard_catalog_id' => $hazard])->assertCreated();
    expect($row->json('data.hazard'))->not->toBeEmpty()->and($row->json('data.risk'))->not->toBeEmpty();
});

it('no deja enviar si faltan puesto, controles de mayor jerarquía o participantes (criterio 2)', function () {
    [, $version, $position] = ipcMatrix($this);
    $row = ipcRow($this, $version)->json('data.id');
    $this->postJson('/api/v1/records/IPC/iperc_controls', ['iperc_row_id' => $row, 'hierarchy' => 'ppe', 'description' => 'Lentes de seguridad'], ipc())->assertCreated();

    $issues = $this->postJson("/api/v1/ipc/versions/{$version}/submit", [], ipc())
        ->assertStatus(422)->assertJsonPath('type', 'iperc_incomplete')->json('issues');
    $texts = collect($issues)->pluck('text')->implode(' | ');
    expect($texts)->toContain('indica el puesto expuesto')->toContain('mayor jerarquía que el EPP')->toContain('participaron');

    $this->patchJson("/api/v1/records/IPC/iperc_rows/{$row}", ['position_id' => $position, 'notes' => 'Sin alternativa de mayor jerarquía: tarea manual en campo.'], ipc())->assertOk();
    $this->postJson("/api/v1/ipc/versions/{$version}/submit", ['participants' => [['name' => 'Juan Pérez', 'role' => 'Soldador']]], ipc())->assertNoContent();
});

it('aprueba solo otra persona, congela la versión, programa la revisión y abre acciones correctivas (criterio 4)', function () {
    [$matrix, $version, $position] = ipcMatrix($this);
    $row = ipcRow($this, $version, ['position_id' => $position, 'residual_probability' => 5, 'residual_severity' => 1])->json('data.id');
    $user = collect($this->getJson('/api/v1/records/IPC/iperc_controls/options/responsible_user_id', ipc())->json('data'))->first()['id'];
    $this->postJson('/api/v1/records/IPC/iperc_controls', ['iperc_row_id' => $row, 'hierarchy' => 'engineering', 'description' => 'Guarda en la amoladora',
        'responsible_user_id' => $user, 'due_on' => '2026-12-15'], ipc())->assertCreated();
    $this->postJson("/api/v1/ipc/versions/{$version}/submit", ['participants' => ['Ana Quispe']], ipc())->assertNoContent();

    $this->postJson("/api/v1/ipc/versions/{$version}/approve", [], ipc())
        ->assertStatus(409)->assertJsonPath('title', 'Aprueba otra persona');

    // Simula que la elaboró otra persona del equipo.
    DB::update('update iperc_versions set prepared_by = ? where id = ?', ['01900000-0000-7000-8000-000000000301', $version]);
    $res = $this->postJson("/api/v1/ipc/versions/{$version}/approve", [], ipc())->assertOk();
    expect($res->json('data.corrective_actions'))->toBe(1)
        ->and($res->json('data.next_review_on'))->toBe(now('America/Lima')->addMonthsNoOverflow(12)->toDateString());

    $ws = $this->getJson("/api/v1/ipc/matrices/{$matrix}/workspace", ipc())->json('data');
    expect($ws['versions'][0]['status'])->toBe('current')->and($ws['matrix']['current_version_id'])->toBe($version);
    expect(DB::table('corrective_actions')->where('source_type', 'iperc')->where('source_id', $row)->count())->toBe(1);

    // Congelada: editar la fila no se permite; se abre una nueva versión y la vigente no cambia.
    $this->patchJson("/api/v1/records/IPC/iperc_rows/{$row}", ['task' => 'Otra tarea'], ipc())->assertStatus(409);
    $this->postJson("/api/v1/ipc/matrices/{$matrix}/versions", [], ipc())->assertStatus(422);
    $new = $this->postJson("/api/v1/ipc/matrices/{$matrix}/versions", ['change_reason' => 'Revisión anual'], ipc())
        ->assertCreated()->assertJsonPath('data.version_number', 2)->json('data.id');
    $this->postJson("/api/v1/ipc/matrices/{$matrix}/versions", ['change_reason' => 'Otra'], ipc())->assertStatus(409);

    $ws2 = $this->getJson("/api/v1/ipc/matrices/{$matrix}/workspace", ipc())->json('data');
    expect($ws2['version_id'])->toBe($new)->and($ws2['rows'])->toHaveCount(1)->and($ws2['rows'][0]['controls'])->toHaveCount(1)
        ->and(collect($ws2['versions'])->firstWhere('id', $version)['status'])->toBe('current');

    // El control de la versión vigente sigue su implementación (estado), aunque la versión esté congelada.
    $control = $ws['rows'][0]['controls'][0]['id'];
    $this->patchJson("/api/v1/records/IPC/iperc_controls/{$control}", ['status' => 'implemented'], ipc())->assertOk();
    $this->patchJson("/api/v1/records/IPC/iperc_controls/{$control}", ['description' => 'Cambio'], ipc())->assertStatus(409);
});

it('exporta la matriz en PDF y Excel', function () {
    [, $version, $position] = ipcMatrix($this);
    ipcRow($this, $version, ['position_id' => $position]);

    $pdf = $this->get("/api/v1/ipc/versions/{$version}/export.pdf", ipc())->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')->and(substr($pdf->getContent(), 0, 4))->toBe('%PDF');

    $xlsx = $this->get("/api/v1/ipc/versions/{$version}/export.xlsx", ipc())->assertOk();
    expect(substr($xlsx->getContent(), 0, 2))->toBe('PK');
    $file = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($file, $xlsx->getContent());
    $zip = new ZipArchive;
    $zip->open($file);
    expect($zip->getFromName('xl/worksheets/sheet1.xml'))->toContain('Corte con amoladora')->toContain('Importante');
    $zip->close();
    unlink($file);
});

it('no permite crear versiones a mano ni editar una versión enviada', function () {
    [$matrix, $version] = ipcMatrix($this);
    $this->postJson('/api/v1/records/IPC/iperc_versions', ['iperc_matrix_id' => $matrix, 'version_number' => 5], ipc())->assertStatus(409);
    $this->patchJson("/api/v1/records/IPC/iperc_versions/{$version}", ['change_reason' => 'Ajuste'], ipc())->assertOk();
});

it('un accidente ligado a una fila marca la matriz «requiere revisión» y la nueva versión aprobada la deja vigente (RN-IPC-09, RN-ACC-08)', function () {
    [$matrix, $version, $position] = ipcMatrix($this, 'IPERC-REV');
    $row = ipcRow($this, $version, ['position_id' => $position, 'residual_probability' => 5, 'residual_severity' => 1])->json('data.id');
    $user = collect($this->getJson('/api/v1/records/IPC/iperc_controls/options/responsible_user_id', ipc())->json('data'))->first()['id'];
    $this->postJson('/api/v1/records/IPC/iperc_controls', ['iperc_row_id' => $row, 'hierarchy' => 'engineering', 'description' => 'Guarda',
        'responsible_user_id' => $user, 'due_on' => '2026-12-15'], ipc())->assertCreated();
    $this->postJson("/api/v1/ipc/versions/{$version}/submit", ['participants' => ['Ana Quispe']], ipc())->assertNoContent();
    DB::update('update iperc_versions set prepared_by = ? where id = ?', ['01900000-0000-7000-8000-000000000301', $version]);
    $this->postJson("/api/v1/ipc/versions/{$version}/approve", [], ipc())->assertOk();

    // La fila vigente se ofrece en el selector del evento.
    expect(collect($this->getJson('/api/v1/records/ACC/sst_events/options/iperc_row_id', ipc())->json('data'))->pluck('id'))->toContain($row);
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'S-REV', 'name' => 'Planta revisión'], ipc())->json('data.id');
    $this->postJson('/api/v1/records/ACC/sst_events', ['event_type' => 'minor_accident', 'occurred_at' => now()->subHour()->toIso8601String(), 'site_id' => $site,
        'description' => 'Corte en la mano con la amoladora', 'iperc_row_id' => $row], ipc())->assertCreated();
    $m = $this->getJson("/api/v1/records/IPC/iperc_matrices/{$matrix}", ipc())->json('data');
    expect($m['status'])->toBe('review_required')->and($m['review_reason'])->toContain('accidente');

    // Nueva versión aprobada: vuelve a vigente.
    $new = $this->postJson("/api/v1/ipc/matrices/{$matrix}/versions", ['change_reason' => 'Revisión por accidente'], ipc())->assertCreated()->json('data.id');
    $this->postJson("/api/v1/ipc/versions/{$new}/submit", ['participants' => ['Ana Quispe']], ipc())->assertNoContent();
    DB::update('update iperc_versions set prepared_by = ? where id = ?', ['01900000-0000-7000-8000-000000000301', $new]);
    $this->postJson("/api/v1/ipc/versions/{$new}/approve", [], ipc())->assertOk();
    expect($this->getJson("/api/v1/records/IPC/iperc_matrices/{$matrix}", ipc())->json('data.status'))->toBe('active');

    // Pasada la fecha de revisión, la revisión periódica la marca «revisión vencida» (RN-IPC-08).
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    DB::update('update iperc_matrices set next_review_on = current_date - 1 where id = ?', [$matrix]);
    app(\App\Modules\NOT\SstAlerts::class)->refreshStatuses(DB::connection());
    expect(DB::selectOne('select status from iperc_matrices where id = ?', [$matrix])->status)->toBe('review_overdue');
});
