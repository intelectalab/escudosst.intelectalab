<?php

// Cabecera del editor IPERC: trabajadores expuestos del puesto de la matriz.

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

it('cuenta los trabajadores activos del puesto de la matriz', function () {
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $pos = $this->postJson('/api/v1/records/TEN/positions', ['code' => 'IPC-HDR', 'name' => 'Montacarguista'], $h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '45454545', 'first_name' => 'Ana', 'paternal_last_name' => 'Ríos', 'current_position_id' => $pos], $h)->assertCreated();
    $criteria = collect($this->getJson('/api/v1/records/IPC/iperc_matrices/options/risk_criteria_id?q=R.M.', $h)->json('data'))->first()['id'];
    $matrix = $this->postJson('/api/v1/records/IPC/iperc_matrices', ['code' => 'IPC-HDR', 'title' => 'Almacén', 'risk_criteria_id' => $criteria, 'position_id' => $pos], $h)
        ->assertCreated()->json('data.id');

    $this->getJson("/api/v1/ipc/matrices/{$matrix}/workspace", $h)->assertOk()->assertJsonPath('data.exposed_workers', 1)
        ->assertJsonPath('data.matrix.position_name', 'Montacarguista');
});
