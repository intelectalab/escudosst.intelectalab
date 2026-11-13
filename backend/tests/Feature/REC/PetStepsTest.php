<?php

// PETS (M-PET): tabla paso–riesgo–control, RN-PET-04 (riesgo significativo con control), RN-PET-03 (capacitación exigida
// al puesto al aprobar) y RN-PET-02 (aprobado no se edita).

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

it('valida los pasos, exige el control del riesgo significativo y al aprobar deja la capacitación exigida al puesto', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $pos = $this->postJson('/api/v1/records/TEN/positions', ['code' => 'SOLD-PET'.random_int(10, 99), 'name' => 'Soldador PETS'], $h)->assertCreated()->json('data.id');
    $tr = $this->postJson('/api/v1/records/CAP/trainings', ['title' => 'Soldadura segura PETS', 'training_type' => 'specific', 'modality' => 'in_person', 'duration_minutes' => 60], $h)->assertCreated()->json('data.id');
    $proc = $this->postJson('/api/v1/records/PET/procedures', ['code' => 'PETS-ST'.random_int(10, 99), 'title' => 'Soldadura en taller', 'procedure_type' => 'pets', 'position_id' => $pos], $h)->assertCreated()->json('data.id');
    $v = $this->postJson('/api/v1/records/PET/procedure_versions', ['procedure_id' => $proc], $h)->assertCreated()->json('data.id');

    $this->putJson("/api/v1/pet/versions/{$v}/steps", ['steps' => [
        ['step' => 'Revisar el equipo de soldadura', 'risk' => 'Contacto eléctrico', 'control' => '', 'significant' => true],
    ], 'required_training_ids' => [$tr], 'iperc_row_ids' => []], $h)->assertOk()->assertJsonPath('data.steps.0.significant', true);
    $this->patchJson("/api/v1/records/PET/procedure_versions/{$v}", ['status' => 'in_review'], $h)
        ->assertStatus(422)->assertJsonPath('errors.status.0', fn ($m) => str_contains($m, 'riesgo significativo sin control'));

    $this->putJson("/api/v1/pet/versions/{$v}/steps", ['steps' => [
        ['step' => 'Revisar el equipo de soldadura', 'risk' => 'Contacto eléctrico', 'control' => 'Bloqueo y etiquetado antes de revisar', 'significant' => true],
    ], 'required_training_ids' => [$tr], 'iperc_row_ids' => []], $h)->assertOk();
    $this->patchJson("/api/v1/records/PET/procedure_versions/{$v}", ['status' => 'in_review'], $h)->assertOk();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update('update procedure_versions set prepared_by = ? where id = ?', ['01900000-0000-7000-8000-000000000301', $v]);
    $this->patchJson("/api/v1/records/PET/procedure_versions/{$v}", ['status' => 'approved'], $h)->assertOk();

    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect((int) DB::selectOne('select count(*) as n from position_training_requirements where position_id = ? and training_id = ? and deleted_at is null', [$pos, $tr])->n)->toBe(1);
    $this->putJson("/api/v1/pet/versions/{$v}/steps", ['steps' => [], 'required_training_ids' => [], 'iperc_row_ids' => []], $h)->assertStatus(409);
});
