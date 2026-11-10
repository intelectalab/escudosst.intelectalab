<?php

// Vigilancia médica (M-VMD): solo el médico, con contraseña confirmada; todo en la base médica y con registro de accesos.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::connection('medical')->beginTransaction();
    // El usuario Esencial atiende también como médico ocupacional de Huaylas (solo durante la prueba).
    DB::select("select set_config('app.company_id', ?, false)", [AGRO_ID]);
    $uid = DB::selectOne('select id from users where email = ?', [ESENCIAL_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $role = DB::selectOne("select id from roles where account_id is null and code = 'occupational_doctor'")->id;
    DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, ?, 'active', now())", [HUAYLAS_ID, $uid, $role]);
    DB::select("select set_config('app.company_id', '', false)");
});

afterEach(function () {
    DB::connection('medical')->rollBack();
});

it('exige confirmar la contraseña, registra cada acceso y no edita notas firmadas', function () {
    $sst = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '62626262', 'first_name' => 'Pedro', 'paternal_last_name' => 'Ruido'], $sst)->json('data.id');

    $doc = authHeaders(tokenFor(ESENCIAL_EMAIL), HUAYLAS_ID);
    // Sin contraseña confirmada no se ve nada médico; contraseña errada se rechaza.
    $this->getJson('/api/v1/vmd/programs', $doc)->assertStatus(403)->assertJsonPath('type', 'reauth_required');
    $this->getJson('/api/v1/vmd/cases', $doc)->assertStatus(403)->assertJsonPath('type', 'reauth_required');
    $this->postJson('/api/v1/vmd/reauth', ['password' => 'otra'], $doc)->assertStatus(422);
    $r = $this->postJson('/api/v1/vmd/reauth', ['password' => DEMO_PASSWORD], $doc)->assertOk()->json('data.reauth_id');
    $m = $doc + ['X-Medical-Reauth' => $r];

    $p = $this->postJson('/api/v1/vmd/programs', ['code' => 'VMD-RUI', 'name' => 'Conservación auditiva', 'target_risk' => 'noise'], $m)->assertCreated()->json('data.id');
    $this->getJson('/api/v1/vmd/programs', $m)->assertOk()->assertJsonPath('data.0.name', 'Conservación auditiva');

    $case = $this->postJson('/api/v1/vmd/cases', ['program_id' => $p, 'worker_id' => $w, 'reason' => 'Exposición > 85 dB', 'risk_classification' => 'high',
        'next_control_on' => now()->subDay()->toDateString()], $m)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/vmd/cases', ['program_id' => $p, 'worker_id' => $w], $m)->assertStatus(409);
    $this->getJson('/api/v1/vmd/cases?overdue=1', $m)->assertOk()->assertJsonPath('data.0.worker', 'Pedro Ruido')->assertJsonPath('data.0.overdue', true);

    $n = $this->postJson("/api/v1/vmd/cases/{$case}/notes", ['note_type' => 'evaluation', 'assessment' => 'Hipoacusia leve'], $m)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/vmd/cases/{$case}/notes", ['note_type' => 'other', 'assessment' => 'Corrige: hipoacusia moderada', 'amended_note_id' => $n], $m)->assertCreated();
    $show = $this->getJson("/api/v1/vmd/cases/{$case}", $m)->assertOk();
    expect($show->json('notes'))->toBeNull()->and($show->json('data.notes'))->toHaveCount(2)
        ->and($show->json('data.case.status'))->toBe('in_follow_up');

    // Cerrar exige motivo.
    $this->patchJson("/api/v1/vmd/cases/{$case}", ['status' => 'closed'], $m)->assertStatus(422);
    $this->patchJson("/api/v1/vmd/cases/{$case}", ['status' => 'closed', 'closure_reason' => 'Reubicado a zona sin ruido'], $m)->assertOk();

    // Todo quedó en la base médica y registrado (con la clave de re-autenticación).
    $med = DB::connection('medical');
    $med->select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect($med->table('medical_access_logs')->where('reauth_session_id', $r)->count())->toBeGreaterThanOrEqual(6);
    $med->select("select set_config('app.company_id', '', false)");

    // El Jefe SST (sin rol médico) no entra a los casos.
    $this->getJson('/api/v1/vmd/cases', authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID) + ['X-Medical-Reauth' => $r])->assertStatus(403);
});
