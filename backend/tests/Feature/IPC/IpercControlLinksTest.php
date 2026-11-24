<?php

// Controles del IPERC vinculados a EPP, PETS y capacitación (M-IPC SCR-IPC-04, RN-IPC-10): se guardan, se validan
// (misma empresa, vigentes, EPP solo en controles de EPP), se muestran en la fila y al aprobar alimentan los EPP del puesto.

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function iclH(): array
{
    return authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
}

/** Matriz nueva con criterios R.M. 050 y un puesto: [matriz, versión, puesto]. */
function iclMatrix($test, string $code): array
{
    $h = iclH();
    $criteria = collect($test->getJson('/api/v1/records/IPC/iperc_matrices/options/risk_criteria_id?q=R.M.', $h)->json('data'))->first()['id'];
    $position = $test->postJson('/api/v1/records/TEN/positions', ['code' => 'POS-'.$code.'-'.random_int(100, 99999), 'name' => 'Soldador'], $h)->json('data.id');
    $matrix = $test->postJson('/api/v1/records/IPC/iperc_matrices', ['code' => $code.'-'.random_int(100, 99999), 'title' => 'Producción', 'risk_criteria_id' => $criteria], $h)
        ->assertCreated()->json('data.id');
    $version = $test->getJson("/api/v1/ipc/matrices/{$matrix}/workspace", $h)->assertOk()->json('data.version_id');

    return [$matrix, $version, $position];
}

function iclRow($test, string $version, array $extra = []): TestResponse
{
    return $test->postJson('/api/v1/records/IPC/iperc_rows', array_merge([
        'iperc_version_id' => $version, 'activity' => 'Soldadura', 'task' => 'Corte con amoladora', 'hazard' => 'Partículas proyectadas',
        'risk' => 'Lesión ocular', 'probability_detail' => ['exposed_people' => 2, 'procedures' => 2, 'training' => 2, 'exposure' => 3], 'severity_value' => 2,
    ], $extra), iclH());
}

/** Inserta como la empresa dada y devuelve el id. */
function iclInsert(string $company, string $sql, array $bind): string
{
    $prev = (string) DB::selectOne("select current_setting('app.company_id', true) as c")->c;
    DB::select("select set_config('app.company_id', ?, false)", [$company]);
    $id = DB::selectOne($sql.' returning id', $bind)->id;
    DB::select("select set_config('app.company_id', ?, false)", [$prev]);

    return $id;
}

function iclPpe(string $company, bool $active = true): string
{
    return iclInsert($company, 'insert into ppe_items (company_id, code, name, category, is_active) values (?, ?, ?, ?, ?)',
        [$company, 'EPP-ICL-'.random_int(1000, 999999), 'Lentes de seguridad', 'eye_face', $active ? 'true' : 'false']);
}

function iclPets(string $company): string
{
    return iclInsert($company, "insert into procedures (company_id, code, title, procedure_type) values (?, ?, 'Corte seguro con amoladora', 'pets')",
        [$company, 'PETS-ICL-'.random_int(1000, 999999)]);
}

function iclTraining(string $company): string
{
    return iclInsert($company, "insert into trainings (company_id, code, title, training_type) values (?, ?, 'Uso de amoladora', 'specific')",
        [$company, 'CAP-ICL-'.random_int(1000, 999999)]);
}

it('guarda los vínculos del control a EPP, PETS y capacitación y los muestra en la fila', function () {
    [$matrix, $version, $position] = iclMatrix($this, 'IPERC-ICL1');
    $row = iclRow($this, $version, ['position_id' => $position])->json('data.id');
    $ppe = iclPpe(ANDINAS_ID);
    $pets = iclPets(ANDINAS_ID);
    $training = iclTraining(ANDINAS_ID);

    // Las opciones del selector ofrecen el EPP activo del catálogo de la empresa (no el inactivo).
    $inactive = iclPpe(ANDINAS_ID, false);
    $opts = collect($this->getJson('/api/v1/records/IPC/iperc_controls/options/ppe_item_id', iclH())->assertOk()->json('data'))->pluck('id');
    expect($opts)->toContain($ppe)->not->toContain($inactive);

    $this->postJson('/api/v1/records/IPC/iperc_controls', ['iperc_row_id' => $row, 'hierarchy' => 'ppe', 'description' => 'Lentes de seguridad',
        'ppe_item_id' => $ppe, 'training_id' => $training], iclH())->assertCreated()->assertJsonPath('data.ppe_item_id', $ppe)->assertJsonPath('data.training_id', $training);
    $adm = $this->postJson('/api/v1/records/IPC/iperc_controls', ['iperc_row_id' => $row, 'hierarchy' => 'administrative', 'description' => 'Aplicar el PETS',
        'procedure_id' => $pets, 'training_id' => $training], iclH())->assertCreated()->assertJsonPath('data.procedure_id', $pets)->json('data.id');

    $controls = collect($this->getJson("/api/v1/ipc/matrices/{$matrix}/workspace", iclH())->assertOk()->json('data.rows.0.controls'));
    $p = $controls->firstWhere('hierarchy', 'ppe');
    $a = $controls->firstWhere('id', $adm);
    expect($p['ppe_item_id'])->toBe($ppe)->and($p['ppe_item_name'])->toBe('Lentes de seguridad')->and($p['training_name'])->toContain('Uso de amoladora')
        ->and($a['procedure_id'])->toBe($pets)->and($a['procedure_name'])->toContain('Corte seguro con amoladora');

    // En borrador el vínculo se cambia o se quita.
    $this->patchJson("/api/v1/records/IPC/iperc_controls/{$adm}", ['training_id' => null], iclH())->assertOk()->assertJsonPath('data.training_id', null);
});

it('rechaza vínculos de otra empresa, EPP inactivos y EPP en un control que no es de EPP (422)', function () {
    [, $version, $position] = iclMatrix($this, 'IPERC-ICL2');
    $row = iclRow($this, $version, ['position_id' => $position])->json('data.id');
    $base = ['iperc_row_id' => $row, 'hierarchy' => 'ppe', 'description' => 'Lentes'];

    $this->postJson('/api/v1/records/IPC/iperc_controls', $base + ['ppe_item_id' => iclPpe(HUAYLAS_ID)], iclH())->assertStatus(422)->assertJsonValidationErrors('ppe_item_id');
    $this->postJson('/api/v1/records/IPC/iperc_controls', $base + ['procedure_id' => iclPets(HUAYLAS_ID)], iclH())->assertStatus(422)->assertJsonValidationErrors('procedure_id');
    $this->postJson('/api/v1/records/IPC/iperc_controls', $base + ['training_id' => iclTraining(HUAYLAS_ID)], iclH())->assertStatus(422)->assertJsonValidationErrors('training_id');
    $this->postJson('/api/v1/records/IPC/iperc_controls', $base + ['ppe_item_id' => iclPpe(ANDINAS_ID, false)], iclH())->assertStatus(422)->assertJsonValidationErrors('ppe_item_id');
    $this->postJson('/api/v1/records/IPC/iperc_controls', ['iperc_row_id' => $row, 'hierarchy' => 'engineering', 'description' => 'Guarda', 'ppe_item_id' => iclPpe(ANDINAS_ID)], iclH())
        ->assertStatus(422)->assertJsonPath('errors.ppe_item_id.0', 'Solo un control de EPP se vincula a un ítem de EPP.');
    expect(DB::table('iperc_controls')->where('iperc_row_id', $row)->count())->toBe(0);
});

it('al aprobar, el control de EPP guardado desde el formulario pasa a los EPP obligatorios del puesto y el vínculo queda congelado', function () {
    [, $version, $position] = iclMatrix($this, 'IPERC-ICL3');
    $row = iclRow($this, $version, ['position_id' => $position, 'residual_probability' => 5, 'residual_severity' => 1])->json('data.id');
    $ppe = iclPpe(ANDINAS_ID);
    $this->postJson('/api/v1/records/IPC/iperc_controls', ['iperc_row_id' => $row, 'hierarchy' => 'engineering', 'description' => 'Guarda en la amoladora'], iclH())->assertCreated();
    $control = $this->postJson('/api/v1/records/IPC/iperc_controls', ['iperc_row_id' => $row, 'hierarchy' => 'ppe', 'description' => 'Lentes de seguridad', 'ppe_item_id' => $ppe], iclH())
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/ipc/versions/{$version}/submit", ['participants' => ['Ana Quispe']], iclH())->assertNoContent();
    DB::update('update iperc_versions set prepared_by = ? where id = ?', ['01900000-0000-7000-8000-000000000301', $version]);
    $this->postJson("/api/v1/ipc/versions/{$version}/approve", ['committee_meeting_id' => heldMeetingForTests(ANDINAS_ID)], iclH())->assertOk();

    expect(DB::selectOne('select count(*) as n from ppe_position_requirements where position_id = ? and ppe_item_id = ? and deleted_at is null', [$position, $ppe])->n)->toBe(1);
    $this->patchJson("/api/v1/records/IPC/iperc_controls/{$control}", ['ppe_item_id' => null], iclH())->assertStatus(409);
});
