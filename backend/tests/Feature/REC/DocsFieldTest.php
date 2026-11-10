<?php

// Versiones de documentos (política, RISST, PETS), reportes de actos y condiciones, ATS, simulacros con
// ejecución automática en el PASST, equipos de emergencia y metas de objetivos.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function df(): array
{
    return authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
}

it('versiona la política: una sola vigente, la anterior queda obsoleta y no se edita', function () {
    $h = df();
    $v1 = $this->postJson('/api/v1/records/POL/sst_policies', ['title' => 'Política SST', 'content_html' => '<p>Compromiso 1</p>'], $h)->assertCreated();
    $n1 = $v1->json('data.version_number');
    $id1 = $v1->json('data.id');
    $this->patchJson("/api/v1/records/POL/sst_policies/{$id1}", ['status' => 'current'], $h)->assertStatus(422);
    $this->patchJson("/api/v1/records/POL/sst_policies/{$id1}", ['status' => 'approved'], $h)
        ->assertStatus(422)->assertJsonPath('errors.status.0', fn ($m) => str_starts_with($m, 'Quien elaboró la versión no puede aprobarla: la aprueba otra persona (gerencia, comité o supervisor).'));
    DB::update('update sst_policies set prepared_by = ? where id = ?', ['01900000-0000-7000-8000-000000000301', $id1]);
    $this->patchJson("/api/v1/records/POL/sst_policies/{$id1}", ['status' => 'approved'], $h)->assertOk();
    $this->patchJson("/api/v1/records/POL/sst_policies/{$id1}", ['status' => 'current'], $h)->assertOk()->assertJsonPath('data.status', 'current');

    $id2 = $this->postJson('/api/v1/records/POL/sst_policies', ['title' => 'Política SST', 'content_html' => '<p>Compromiso 2</p>'], $h)
        ->assertJsonPath('data.version_number', $n1 + 1)->json('data.id');
    DB::update('update sst_policies set prepared_by = ? where id = ?', ['01900000-0000-7000-8000-000000000301', $id2]);
    $this->patchJson("/api/v1/records/POL/sst_policies/{$id2}", ['status' => 'approved'], $h)->assertOk();
    $this->patchJson("/api/v1/records/POL/sst_policies/{$id2}", ['status' => 'current'], $h)->assertOk();
    $this->getJson("/api/v1/records/POL/sst_policies/{$id1}", $h)->assertJsonPath('data.status', 'obsolete');
    $this->patchJson("/api/v1/records/POL/sst_policies/{$id2}", ['title' => 'Otro'], $h)->assertStatus(409);
});

it('el PETS vigente queda como versión actual del procedimiento', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID); // PET es del plan Corporativo
    $proc = $this->postJson('/api/v1/records/PET/procedures', ['code' => 'PETS-01', 'title' => 'Trabajo en altura', 'procedure_type' => 'pets'], $h)->assertCreated()->json('data.id');
    $v = $this->postJson('/api/v1/records/PET/procedure_versions', ['procedure_id' => $proc, 'content_html' => '<ol><li>Inspeccionar arnés</li></ol>'], $h)
        ->assertCreated()->assertJsonPath('data.version_number', 1)->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update('update procedure_versions set prepared_by = ? where id = ?', ['01900000-0000-7000-8000-000000000301', $v]);
    $this->patchJson("/api/v1/records/PET/procedure_versions/{$v}", ['status' => 'approved'], $h)->assertOk();
    $this->patchJson("/api/v1/records/PET/procedure_versions/{$v}", ['status' => 'current'], $h)->assertOk();
    $this->getJson("/api/v1/records/PET/procedures/{$proc}", $h)->assertJsonPath('data.current_version_id', $v);
});

it('revisa y descarta reportes de actos y condiciones con sus datos obligatorios', function () {
    $h = df();
    $r = $this->postJson('/api/v1/records/ACT/substandard_reports', ['report_type' => 'unsafe_condition', 'description' => 'Cable expuesto en pasillo'], $h)
        ->assertCreated()->assertJsonPath('data.status', 'received');
    expect($r->json('data.code'))->toStartWith('REP-');
    $id = $r->json('data.id');
    $this->patchJson("/api/v1/records/ACT/substandard_reports/{$id}", ['status' => 'triaged'], $h)
        ->assertStatus(422)->assertJsonPath('errors.risk_level.0', 'Para revisar el reporte indica su nivel de riesgo.');
    $this->patchJson("/api/v1/records/ACT/substandard_reports/{$id}", ['status' => 'triaged', 'risk_level' => 'high'], $h)->assertOk();
    $me = collect($this->getJson('/api/v1/records/ACR/corrective_actions/options/responsible_user_id', $h)->json('data'))->first()['id'];
    $this->postJson("/api/v1/acr/from/substandard_reports/{$id}", ['title' => 'Canalizar cable', 'responsible_user_id' => $me, 'due_on' => now()->addDays(3)->toDateString()], $h)
        ->assertCreated();
    $this->getJson("/api/v1/records/ACT/substandard_reports/{$id}", $h)->assertJsonPath('data.status', 'action_assigned');
});

it('un ATS no se firma sin pasos ni participantes', function () {
    $h = df();
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'ATS-S', 'name' => 'Obra ATS'], $h)->json('data.id');
    $ats = $this->postJson('/api/v1/records/ATS/ats_forms', ['task' => 'Montaje de andamio', 'site_id' => $site, 'performed_on' => now()->toDateString()], $h)
        ->assertCreated();
    expect($ats->json('data.code'))->toStartWith('ATS-');
    $this->patchJson("/api/v1/records/ATS/ats_forms/{$ats->json('data.id')}", ['status' => 'signed'], $h)
        ->assertStatus(422)->assertJsonPath('errors.status.0', 'Registra los pasos de la tarea con sus peligros y controles antes de firmar el ATS.');
});

it('el simulacro evaluado vinculado al PASST registra su ejecución sola', function () {
    $h = df();
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    DB::update("update companies set sst_model = 'supervisor' where id = ?", [ANDINAS_ID]);
    $prog = $this->postJson('/api/v1/records/PAS/annual_programs', ['year' => (int) now()->year + 2, 'title' => 'PASST'], $h)->json('data.id');
    $act = $this->postJson('/api/v1/records/PAS/program_activities', ['program_id' => $prog, 'code' => 'SIM-1', 'title' => 'Simulacro de sismo',
        'activity_type' => 'drill', 'goal_quantity' => 2], $h)->json('data.id');
    $this->patchJson("/api/v1/records/PAS/annual_programs/{$prog}", ['status' => 'approved'], $h)->assertOk();

    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'SIM-S', 'name' => 'Sede simulacro'], $h)->json('data.id');
    $d = $this->postJson('/api/v1/records/SIM/drills', ['title' => 'Simulacro de sismo', 'drill_type' => 'earthquake', 'site_id' => $site,
        'scheduled_at' => now()->toIso8601String(), 'program_activity_id' => $act], $h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/SIM/drills/{$d}", ['status' => 'evaluated'], $h)->assertStatus(422);
    $this->patchJson("/api/v1/records/SIM/drills/{$d}", ['status' => 'executed'], $h)->assertOk();
    $this->patchJson("/api/v1/records/SIM/drills/{$d}", ['status' => 'evaluated', 'result' => 'satisfactory'], $h)->assertOk();

    $this->getJson("/api/v1/records/PAS/program_activities/{$act}", $h)->assertJsonPath('data.executed_quantity', 1)->assertJsonPath('data.progress_pct', 50);
    $tr = $this->getJson("/api/v1/records/SIM/drills/{$d}/trace", $h)->json('data');
    expect(collect($tr['parents'])->pluck('table')->all())->toContain('program_activities');
});

it('la revisión de un equipo de emergencia actualiza su estado y próximo mantenimiento', function () {
    $h = df();
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'EQE-S', 'name' => 'Sede equipos'], $h)->json('data.id');
    $eq = $this->postJson('/api/v1/records/EQE/emergency_equipment', ['code' => 'EXT-01', 'equipment_type' => 'fire_extinguisher', 'site_id' => $site,
        'location_text' => 'Pasillo A'], $h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/EQE/equipment_checks', ['equipment_id' => $eq, 'check_type' => 'monthly_inspection', 'checked_at' => now()->toIso8601String(),
        'result' => 'failed', 'next_due_on' => now()->addMonth()->toDateString()], $h)->assertCreated();
    $this->getJson("/api/v1/records/EQE/emergency_equipment/{$eq}", $h)->assertJsonPath('data.status', 'out_of_service')
        ->assertJsonPath('data.next_maintenance_on', now()->addMonth()->toDateString());
});

it('calcula el logro de la meta de un objetivo', function () {
    $h = df();
    $obj = $this->postJson('/api/v1/records/POL/sst_objectives', ['year' => 2026, 'code' => 'OBJ-1', 'title' => 'Reducir accidentes', 'target_value' => 0], $h)->json('data.id');
    $t = $this->postJson('/api/v1/records/POL/objective_targets', ['objective_id' => $obj, 'period_label' => 'Semestre 1', 'target_value' => 10, 'actual_value' => 8], $h)
        ->assertCreated();
    expect($t->json('data.achievement_pct'))->toEqual(80)->and($t->json('data.status'))->toBe('on_track');
});
