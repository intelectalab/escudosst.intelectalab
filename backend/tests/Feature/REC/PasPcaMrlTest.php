<?php

// M-PAS RN-PAS-01/03 (solo validadas, parcial con su peso, versiones), SCR-PAS-04/05 (mis actividades, avance por mes y responsable);
// M-PCA RN-PCA-05/07 (firma del supervisor sin comité, temas obligatorios como requisitos del fotocheck) y §11 (PDF y Excel);
// M-MRL RN-MRL-08 (registro del sistema como evidencia).

use Illuminate\Support\Facades\DB;

function ppmAs(string $company, callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [$company]);
    try {
        return $fn();
    } finally {
        DB::select("select set_config('app.company_id', '', false)");
    }
}

afterEach(function () {
    // Algunas pruebas cambian el rol del usuario: los permisos guardados en memoria no deben pasar a la siguiente.
    app('auth')->forgetGuards();
    \App\Core\Auth\Permissions::flush();
    $GLOBALS['sus_tokens'] = [];
});

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $this->uid = ppmAs(ANDINAS_ID, fn () => DB::selectOne('select id from app_auth_lookup_user(?)', [PROFESIONAL_EMAIL])->id);
    ppmAs(ANDINAS_ID, fn () => DB::update("update companies set sst_model = 'supervisor' where id = ?", [ANDINAS_ID]));
});

it('solo cuentan las ejecuciones validadas, la parcial pesa la mitad y hay avance por mes y responsable', function () {
    $year = (int) now('America/Lima')->year;
    ppmAs(ANDINAS_ID, fn () => DB::update('update annual_programs set deleted_at = now() where year = ?', [$year]));
    $obj = ppmAs(ANDINAS_ID, fn () => DB::selectOne("insert into sst_objectives (company_id, year, code, title, status) values (?, ?, 'OBJ-V', 'Prevenir', 'active') returning id", [ANDINAS_ID, $year])->id);
    $prog = $this->postJson('/api/v1/records/PAS/annual_programs', ['year' => $year, 'title' => "PASST {$year}"], $this->h)->assertCreated()->json('data.id');
    $m = (int) now('America/Lima')->month;
    $a = $this->postJson('/api/v1/records/PAS/program_activities', ['program_id' => $prog, 'code' => 'V1', 'title' => 'Inspección mensual', 'activity_type' => 'inspection',
        'goal_quantity' => 2, 'planned_months' => [$m], 'objective_id' => $obj, 'responsible_user_id' => $this->uid], $this->h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/PAS/annual_programs/{$prog}", ['status' => 'approved'], $this->h)->assertOk();

    // Una ejecución creada por otro módulo (sin validar) no cuenta.
    $x = ppmAs(ANDINAS_ID, fn () => DB::selectOne("insert into activity_executions (company_id, activity_id, executed_on, quantity, description, source_module) values (?, ?, current_date, 1, 'Inspección cerrada', 'INS') returning id", [ANDINAS_ID, $a])->id);
    expect($this->getJson("/api/v1/pas/progress?program_id={$prog}", $this->h)->json('data.total.executed'))->toEqual(0);
    expect(collect($this->getJson('/api/v1/pas/pending-validation', $this->h)->json('data'))->pluck('id'))->toContain($x);
    $this->postJson("/api/v1/pas/executions/{$x}/validate", [], $this->h)->assertOk();
    $this->postJson("/api/v1/pas/executions/{$x}/validate", [], $this->h)->assertStatus(409);
    expect((float) ppmAs(ANDINAS_ID, fn () => DB::selectOne('select executed_quantity from program_activities where id = ?', [$a])->executed_quantity))->toEqual(1);

    // La que registra el Jefe SST queda validada; la parcial pesa partial_execution_weight (0,5).
    $this->postJson('/api/v1/records/PAS/activity_executions', ['activity_id' => $a, 'executed_on' => now('America/Lima')->toDateString(), 'quantity' => 1, 'status' => 'partial',
        'description' => 'Media inspección', 'evidence_file_id' => evidenceFile($this->h)], $this->h)->assertCreated();
    expect((float) ppmAs(ANDINAS_ID, fn () => DB::selectOne('select executed_quantity from program_activities where id = ?', [$a])->executed_quantity))->toEqual(1.5);

    $p = $this->getJson("/api/v1/pas/progress?program_id={$prog}", $this->h)->assertOk()->json('data');
    expect(collect($p['by_month'])->firstWhere('month', $m)['planned'])->toBe(1)->and($p['by_responsible'])->toHaveCount(1);
    $mine = $this->getJson('/api/v1/pas/mine', $this->h)->assertOk()->json('data.activities');
    expect(collect($mine)->pluck('id'))->toContain($a);
});

it('una nueva versión copia las actividades y al aprobarse cierra la anterior y lleva sus ejecuciones', function () {
    $year = (int) now('America/Lima')->year + 5;
    $obj = ppmAs(ANDINAS_ID, fn () => DB::selectOne("insert into sst_objectives (company_id, year, code, title, status) values (?, ?, 'OBJ-N', 'Prevenir', 'active') returning id", [ANDINAS_ID, $year])->id);
    $prog = $this->postJson('/api/v1/records/PAS/annual_programs', ['year' => $year, 'title' => "PASST {$year}"], $this->h)->assertCreated()->json('data.id');
    $a = $this->postJson('/api/v1/records/PAS/program_activities', ['program_id' => $prog, 'code' => 'N1', 'title' => 'Simulacro', 'activity_type' => 'drill',
        'goal_quantity' => 1, 'planned_months' => [5], 'objective_id' => $obj], $this->h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/pas/programs/{$prog}/new-version", [], $this->h)->assertStatus(409);
    $this->patchJson("/api/v1/records/PAS/annual_programs/{$prog}", ['status' => 'approved'], $this->h)->assertOk();
    $this->postJson('/api/v1/records/PAS/activity_executions', ['activity_id' => $a, 'executed_on' => "{$year}-05-10", 'quantity' => 1, 'description' => 'Simulacro hecho', 'evidence_file_id' => evidenceFile($this->h)], $this->h)->assertCreated();

    $v2 = $this->postJson("/api/v1/pas/programs/{$prog}/new-version", [], $this->h)->assertCreated()->json('data');
    expect($v2['version_number'])->toBe(2);
    $this->postJson("/api/v1/pas/programs/{$prog}/new-version", [], $this->h)->assertStatus(409);
    $this->postJson('/api/v1/records/PAS/program_activities', ['program_id' => $v2['id'], 'code' => 'N2', 'title' => 'Charla extra', 'activity_type' => 'training',
        'goal_quantity' => 1, 'planned_months' => [8], 'objective_id' => $obj], $this->h)->assertCreated();
    $this->patchJson("/api/v1/records/PAS/annual_programs/{$v2['id']}", ['status' => 'approved'], $this->h)->assertOk();

    $old = ppmAs(ANDINAS_ID, fn () => DB::selectOne('select status from annual_programs where id = ?', [$prog]));
    $copy = ppmAs(ANDINAS_ID, fn () => DB::selectOne('select id, executed_quantity from program_activities where program_id = ? and previous_activity_id = ?', [$v2['id'], $a]));
    expect($old->status)->toBe('closed')->and((float) $copy->executed_quantity)->toEqual(1);
});

it('sin comité el programa de capacitación se aprueba con la firma del supervisor y sus temas obligatorios van al fotocheck', function () {
    $year = (int) now('America/Lima')->year + 6;
    $pos = ppmAs(ANDINAS_ID, fn () => DB::selectOne("insert into positions (company_id, code, name) values (?, 'SOLD-PCA', 'Soldador PCA') returning id", [ANDINAS_ID])->id);
    $type = ppmAs(ANDINAS_ID, fn () => DB::selectOne("insert into credential_types (company_id, code, name, applies_to, is_active) values (?, 'FT-PCA', 'Fotocheck PCA', 'workers', true) returning id", [ANDINAS_ID])->id);
    $tr = $this->postJson('/api/v1/records/CAP/trainings', ['title' => 'Trabajos en caliente', 'training_type' => 'specific'], $this->h)->assertCreated()->json('data.id');
    $p = $this->postJson('/api/v1/records/PCA/training_programs', ['year' => $year, 'title' => "Programa de capacitación {$year}"], $this->h)->assertCreated()->json('data.id');
    for ($i = 1; $i <= 4; $i++) {
        $this->postJson('/api/v1/records/PCA/training_program_items', ['training_program_id' => $p, 'topic' => "Tema {$i}", 'training_type' => 'specific', 'planned_month' => $i,
            'modality' => 'in_person', 'is_mandatory_legal' => $i === 1, 'target_positions' => $i === 1 ? [$pos] : [], 'training_id' => $i === 1 ? $tr : null, 'target_audience' => 'Todos'], $this->h)->assertCreated();
    }
    $this->patchJson("/api/v1/records/PCA/training_programs/{$p}", ['status' => 'approved'], $this->h)->assertStatus(422)->assertJsonValidationErrors('status');
    // Firma del supervisor (el usuario de prueba pasa a supervisor y firma).
    ppmAs(ANDINAS_ID, function () use ($p) {
        DB::update("update memberships set role_id = (select id from roles where code = 'sst_supervisor' and account_id is null) where user_id = ? and company_id = ?", [$this->uid, ANDINAS_ID]);
        DB::insert("insert into signatures (company_id, subject_type, subject_id, signer_user_id, signer_name, method, document_hash, evidence_hash, signed_at)
                    values (?, 'training_programs', ?, ?, 'Supervisor', 'pin', 'x', 'x', now())", [ANDINAS_ID, $p, $this->uid]);
    });
    $GLOBALS['sus_tokens'] = [];
    app('auth')->forgetGuards();
    \App\Core\Auth\Permissions::flush();
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $this->patchJson("/api/v1/records/PCA/training_programs/{$p}", ['status' => 'approved'], $h)->assertOk();
    $req = ppmAs(ANDINAS_ID, fn () => DB::selectOne('select requirement_type, position_id, is_blocking from credential_requirements where credential_type_id = ? and training_id = ?', [$type, $tr]));
    expect($req)->not->toBeNull()->and($req->position_id)->toBe($pos)->and($req->is_blocking)->toBeTrue();

    $this->getJson("/api/v1/reports/training_programs/{$p}", $h)->assertOk()->assertJsonPath('data.totals.items', 4)->assertJsonPath('data.totals.mandatory', 1);
    $this->get("/api/v1/reports/training_programs/{$p}/compliance.xlsx", $h)->assertOk();
});

it('acepta un registro del sistema de la empresa como evidencia del requisito legal', function () {
    $req = ppmAs(ANDINAS_ID, fn () => DB::selectOne("insert into legal_requirements (company_id, legal_norm_id, requirement, applies, status)
                                                      select ?, id, 'Capacitar 4 veces al año', true, 'active' from legal_norms limit 1 returning id", [ANDINAS_ID])->id);
    $tr = $this->postJson('/api/v1/records/CAP/trainings', ['title' => 'Inducción general', 'training_type' => 'induction'], $this->h)->assertCreated()->json('data.id');
    $base = ['legal_requirement_id' => $req, 'result' => 'compliant', 'evidence_notes' => 'Ver capacitación'];
    $this->postJson('/api/v1/records/MRL/requirement_evaluations', $base + ['evidence_record_type' => 'workers', 'evidence_record_id' => $tr], $this->h)
        ->assertStatus(422)->assertJsonValidationErrors('evidence_record_id');
    $this->postJson('/api/v1/records/MRL/requirement_evaluations', $base + ['evidence_record_type' => 'trainings', 'evidence_record_id' => '01900000-0000-7000-8000-000000000999'], $this->h)
        ->assertStatus(422)->assertJsonValidationErrors('evidence_record_id');
    $this->postJson('/api/v1/records/MRL/requirement_evaluations', $base + ['evidence_record_type' => 'trainings', 'evidence_record_id' => $tr], $this->h)->assertCreated();
    expect(collect($this->getJson('/api/v1/mrl/evidence-options?type=trainings', $this->h)->assertOk()->json('data'))->pluck('id'))->toContain($tr);
});
