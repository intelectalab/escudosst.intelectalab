<?php

// M-PET RN-PET-03 / criterio 2: al aprobarse una versión del PETS se crea su capacitación de difusión y queda pendiente
// para los trabajadores de los puestos vinculados (el del procedimiento y los de las filas del IPERC vinculadas) en la matriz
// de CAP; volver a aprobar no duplica y una versión nueva exige volver a capacitar.

use Illuminate\Support\Facades\DB;

function petAs(callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    try {
        return $fn();
    } finally {
        DB::select("select set_config('app.company_id', '', false)");
    }
}

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    petAs(fn () => DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('PET', 'CAP', 'IPC') and deleted_at is null", [HUAYLAS_ID]));
    app('auth')->forgetGuards();
    \App\Core\Auth\Permissions::flush();
});

/** Lleva la versión a revisión y la aprueba otra persona (quien la elaboró no se aprueba a sí mismo). */
function petApprove($test, string $version): void
{
    $test->putJson("/api/v1/pet/versions/{$version}/steps", ['steps' => [['step' => 'Bloquear la energía', 'risk' => 'Contacto eléctrico', 'control' => 'Candado y tarjeta', 'significant' => true]],
        'required_training_ids' => [], 'iperc_row_ids' => $test->rows], $test->h)->assertOk();
    $test->patchJson("/api/v1/records/PET/procedure_versions/{$version}", ['status' => 'in_review'], $test->h)->assertOk();
    petAs(fn () => DB::update('update procedure_versions set prepared_by = ? where id = ?', ['01900000-0000-7000-8000-000000000301', $version]));
    $test->patchJson("/api/v1/records/PET/procedure_versions/{$version}", ['status' => 'approved'], $test->h)->assertOk();
}

it('al aprobar el PETS sus dos puestos vinculados tienen la capacitación pendiente, sin duplicar, y la versión nueva exige volver a capacitar', function () {
    $sfx = random_int(1000, 9999);
    $posA = $this->postJson('/api/v1/records/TEN/positions', ['code' => "ELA-{$sfx}", 'name' => "Electricista PT {$sfx}"], $this->h)->assertCreated()->json('data.id');
    $posB = $this->postJson('/api/v1/records/TEN/positions', ['code' => "AYU-{$sfx}", 'name' => "Ayudante PT {$sfx}"], $this->h)->assertCreated()->json('data.id');
    [$row, $wA, $wB] = petAs(function () use ($posA, $posB, $sfx) {
        $crit = DB::selectOne('select id from risk_criteria where deleted_at is null order by created_at limit 1')->id;
        $mx = DB::selectOne("insert into iperc_matrices (company_id, code, title, risk_criteria_id, status) values (?, ?, 'Tablero PT', ?, 'active') returning id", [HUAYLAS_ID, "IP-PT-{$sfx}", $crit])->id;
        $v = DB::selectOne("insert into iperc_versions (company_id, iperc_matrix_id, version_number, status, approved_at) values (?, ?, 1, 'current', now()) returning id", [HUAYLAS_ID, $mx])->id;
        $r = DB::selectOne("insert into iperc_rows (company_id, iperc_version_id, row_number, activity, task, hazard, risk, probability_value, severity_value, risk_level, is_significant, position_id)
                            values (?, ?, 1, 'Mantenimiento', 'Cambiar interruptor', 'Energía eléctrica', 'Electrocución', 9, 3, 'important', true, ?) returning id", [HUAYLAS_ID, $v, $posB])->id;
        $mk = fn (string $doc, string $pos) => DB::selectOne("insert into workers (company_id, document_number, document_number_hash, first_name, paternal_last_name, status, current_position_id)
                                                              values (?, ?, decode(md5(?), 'hex'), 'Trabajador', 'PETS', 'active', ?) returning id", [HUAYLAS_ID, $doc, $doc, $pos])->id;

        return [$r, $mk("7{$sfx}101", $posA), $mk("7{$sfx}202", $posB)];
    });
    $this->rows = [$row];

    $proc = $this->postJson('/api/v1/records/PET/procedures', ['code' => "PETS-PT{$sfx}", 'title' => 'Cambio de interruptor', 'procedure_type' => 'pets', 'position_id' => $posA], $this->h)
        ->assertCreated()->json('data.id');
    $v1 = $this->postJson('/api/v1/records/PET/procedure_versions', ['procedure_id' => $proc], $this->h)->assertCreated()->json('data.id');
    petApprove($this, $v1);

    $t1 = petAs(fn () => DB::selectOne('select training_id from procedure_versions where id = ?', [$v1])->training_id);
    expect($t1)->not->toBeNull();
    expect(petAs(fn () => DB::selectOne('select title, topic from trainings where id = ?', [$t1])))->title->toContain("PETS-PT{$sfx}")->topic->toBe('PETS');
    $reqs = fn (string $t) => petAs(fn () => DB::select("select position_id from position_training_requirements where training_id = ? and deleted_at is null and requirement_level = 'mandatory' order by position_id", [$t]));
    expect(array_column($reqs($t1), 'position_id'))->toEqualCanonicalizing([$posA, $posB]);

    // En la matriz de CAP, los trabajadores de ambos puestos la tienen pendiente.
    $pending = function (string $t) {
        return collect($this->getJson('/api/v1/cap/compliance', $this->h)->assertOk()->json('data.workers'))
            ->filter(fn ($w) => collect($w['items'])->contains(fn ($i) => $i['training_id'] === $t && $i['state'] === 'missing'))->pluck('id')->values()->all();
    };
    expect($pending($t1))->toContain($wA)->toContain($wB);
    $cells = collect($this->getJson('/api/v1/cap/matrix', $this->h)->assertOk()->json('data.cells'))->where('training_id', $t1);
    expect($cells)->toHaveCount(2)->and($cells->sum('total'))->toBe(2)->and($cells->sum('done'))->toBe(0);

    // Ponerla vigente (otra vez «aprobada») no duplica la capacitación ni sus exigencias.
    $this->patchJson("/api/v1/records/PET/procedure_versions/{$v1}", ['status' => 'current'], $this->h)->assertOk();
    \App\Modules\PET\PetStepsController::requireTrainings($v1);
    expect($reqs($t1))->toHaveCount(2);
    expect((int) petAs(fn () => DB::selectOne("select count(*) as n from trainings where code = ? and deleted_at is null", ["PETS-PT{$sfx}-V1"])->n))->toBe(1);

    // Versión nueva: su propia capacitación pendiente; la de la versión anterior deja de exigirse (hay que volver a capacitar).
    $v2 = $this->postJson('/api/v1/records/PET/procedure_versions', ['procedure_id' => $proc], $this->h)->assertCreated()->json('data.id');
    petApprove($this, $v2);
    $t2 = petAs(fn () => DB::selectOne('select training_id from procedure_versions where id = ?', [$v2])->training_id);
    expect($t2)->not->toBeNull()->not->toBe($t1);
    expect(array_column($reqs($t2), 'position_id'))->toEqualCanonicalizing([$posA, $posB]);
    expect($reqs($t1))->toBeEmpty();
    expect($pending($t2))->toContain($wA)->toContain($wB);
    expect($pending($t1))->toBeEmpty();
});

it('aprobar el PETS sin puestos vinculados no crea capacitación', function () {
    $this->rows = [];
    $proc = $this->postJson('/api/v1/records/PET/procedures', ['code' => 'PETS-NP'.random_int(1000, 9999), 'title' => 'Sin puesto', 'procedure_type' => 'pets'], $this->h)->assertCreated()->json('data.id');
    $v = $this->postJson('/api/v1/records/PET/procedure_versions', ['procedure_id' => $proc], $this->h)->assertCreated()->json('data.id');
    petApprove($this, $v);
    expect(petAs(fn () => DB::selectOne('select training_id from procedure_versions where id = ?', [$v])->training_id))->toBeNull();
});
