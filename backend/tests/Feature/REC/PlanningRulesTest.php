<?php

// Reglas de planificación según sus fichas: requisito legal incumplido con su acción (RN-MRL-04), brechas de la línea base,
// objetivos ligados a la política vigente (RN-POL-05), entrega del RISST (RN-RIS-05/07) y sobreejecución del presupuesto (RN-PRE-04).

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
});

it('un requisito legal que no se cumple crea su acción correctiva', function () {
    $norm = collect($this->getJson('/api/v1/records/MRL/legal_requirements/options/legal_norm_id?q=29783', $this->h)->json('data'))->first()['id'];
    $req = $this->postJson('/api/v1/records/MRL/legal_requirements', ['legal_norm_id' => $norm, 'article' => 'Art. 35', 'requirement' => 'Entregar el RISST a cada trabajador'], $this->h)
        ->assertCreated()->json('data.id');
    $ev = $this->postJson('/api/v1/records/MRL/requirement_evaluations', ['legal_requirement_id' => $req, 'result' => 'non_compliant', 'evidence_notes' => 'Faltan 12 cargos'], $this->h)
        ->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $a = DB::selectOne('select c.source_type, c.priority from requirement_evaluations e join corrective_actions c on c.id = e.corrective_action_id where e.id = ?', [$ev]);
    expect($a->source_type)->toBe('legal_requirement')->and($a->priority)->toBe('high');
});

it('el objetivo se liga a la política vigente y se activa con su meta', function () {
    $current = DB::selectOne("select id from sst_policies where company_id = ? and status = 'current' and deleted_at is null", [HUAYLAS_ID]);
    $r = $this->postJson('/api/v1/records/POL/sst_objectives', ['year' => (int) date('Y'), 'code' => 'OBJ-'.substr(uniqid(), -5), 'title' => 'Reducir accidentes'], $this->h);
    if (! $current) {
        $r->assertStatus(422)->assertJsonValidationErrors('policy_id');

        return;
    }
    $id = $r->assertCreated()->assertJsonPath('data.policy_id', $current->id)->json('data.id');
    $this->patchJson("/api/v1/records/POL/sst_objectives/{$id}", ['status' => 'active'], $this->h)->assertStatus(422)->assertJsonValidationErrors('target_value');
    $this->patchJson("/api/v1/records/POL/sst_objectives/{$id}", ['status' => 'active', 'target_value' => 20, 'unit' => '%'], $this->h)->assertOk();
});

it('la entrega física del RISST pide el cargo y una firmada solo se anula con motivo', function () {
    $v = DB::selectOne("select id from risst_versions where company_id = ? and status = 'current' and deleted_at is null", [HUAYLAS_ID])?->id;
    $v ??= DB::selectOne("insert into risst_versions (company_id, version_number, title, status) values (?, 90, 'RISST de prueba', 'current') returning id", [HUAYLAS_ID])->id;
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '64646401', 'first_name' => 'Nuevo', 'paternal_last_name' => 'Ingreso'], $this->h)->json('data.id');
    $this->postJson('/api/v1/records/RIS/risst_deliveries', ['risst_version_id' => $v, 'worker_id' => $w, 'method' => 'physical_signed'], $this->h)
        ->assertStatus(422)->assertJsonValidationErrors('acknowledgement_file_id');
    $file = $this->post('/api/v1/files', ['file' => UploadedFile::fake()->create('cargo.pdf', 20, 'application/pdf'), 'module' => 'RIS'], $this->h + ['Accept' => 'application/json'])->json('data.id');
    $d = $this->postJson('/api/v1/records/RIS/risst_deliveries', ['risst_version_id' => $v, 'worker_id' => $w, 'method' => 'physical_signed', 'acknowledgement_file_id' => $file], $this->h)
        ->assertCreated()->assertJsonPath('data.status', 'signed')->json('data.id');
    $this->patchJson("/api/v1/records/RIS/risst_deliveries/{$d}", ['delivered_on' => now()->subDay()->toDateString()], $this->h)->assertStatus(409);
    $this->patchJson("/api/v1/records/RIS/risst_deliveries/{$d}", ['status' => 'voided'], $this->h)->assertStatus(422)->assertJsonValidationErrors('void_reason');
    $this->patchJson("/api/v1/records/RIS/risst_deliveries/{$d}", ['status' => 'voided', 'void_reason' => 'Se registró a otro trabajador'], $this->h)->assertOk();
});

it('un gasto que pasa el monto de la línea pide motivo y queda como sobreejecución', function () {
    $b = $this->postJson('/api/v1/records/PRE/budgets', ['year' => 2027, 'title' => 'Presupuesto SST 2027'], $this->h)->assertCreated()->json('data.id');
    $l = $this->postJson('/api/v1/records/PRE/budget_lines', ['budget_id' => $b, 'category' => 'ppe', 'description' => 'Cascos', 'planned_amount' => 1000], $this->h)->json('data.id');
    $this->patchJson("/api/v1/records/PRE/budgets/{$b}", ['status' => 'approved'], $this->h)->assertOk();
    $this->postJson('/api/v1/records/PRE/budget_executions', ['budget_line_id' => $l, 'amount' => 800, 'description' => 'Compra 1'], $this->h)->assertCreated()->assertJsonPath('data.is_overrun', false);
    $this->postJson('/api/v1/records/PRE/budget_executions', ['budget_line_id' => $l, 'amount' => 300, 'description' => 'Compra 2'], $this->h)->assertStatus(422)->assertJsonValidationErrors('overrun_reason');
    $this->postJson('/api/v1/records/PRE/budget_executions', ['budget_line_id' => $l, 'amount' => 300, 'description' => 'Compra 2', 'overrun_reason' => 'Subió el precio'], $this->h)
        ->assertCreated()->assertJsonPath('data.is_overrun', true);
});
