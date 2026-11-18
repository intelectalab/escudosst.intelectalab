<?php

// Plan anual, auditorías (hallazgo → acción → cierre), gestión del cambio y monitoreo contra el límite.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function imp(): array
{
    return authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID); // plan Corporativo: AUI, RVD, MAG, MOC (complemento según plan)
}

it('aprueba el plan anual con ítems y lo deja con estructura congelada', function () {
    $h = imp();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update companies set sst_model = 'supervisor' where id = ?", [HUAYLAS_ID]);
    $year = (int) now()->year + 3;
    $base = DB::selectOne("insert into baseline_assessments (company_id, title, assessment_on, status) values (?, 'LB', current_date, 'approved') returning id", [HUAYLAS_ID])->id;
    $pol = DB::selectOne("select id from sst_policies where status in ('current', 'review_required') and deleted_at is null limit 1")?->id
        ?? DB::selectOne("insert into sst_policies (company_id, title, version_number, content_html, status, effective_on) values (?, 'Política', 901, '<p>x</p>', 'current', current_date) returning id", [HUAYLAS_ID])->id;
    DB::insert("insert into sst_objectives (company_id, policy_id, year, code, title, status) values (?, ?, ?, ?, 'Objetivo', 'active')", [HUAYLAS_ID, $pol, $year, 'O-'.random_int(1000, 999999)]);
    DB::insert("insert into annual_programs (company_id, title, year) values (?, 'PASST', ?)", [HUAYLAS_ID, $year]);
    $plan = $this->postJson('/api/v1/records/PLA/annual_plans', ['year' => $year, 'title' => 'Plan anual', 'baseline_assessment_id' => $base, 'policy_id' => $pol], $h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/PLA/annual_plans/{$plan}", ['status' => 'approved'], $h)
        ->assertStatus(422)->assertJsonPath('errors.status.0', 'El plan no tiene ítems (objetivos, metas y recursos).');
    $this->postJson('/api/v1/records/PLA/annual_plan_items', ['annual_plan_id' => $plan, 'code' => 'P1', 'description' => 'Reducir accidentes 20 %'], $h)->assertCreated();
    // RN-PLA-03: con Supervisor SST la aprobación lleva su firma.
    $this->patchJson("/api/v1/records/PLA/annual_plans/{$plan}", ['status' => 'approved'], $h)->assertStatus(422)->assertJsonValidationErrors('status');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $req = DB::selectOne("insert into signature_requests (company_id, subject_type, subject_id, subject_module, document_hash, signer_user_id, role_in_document, sequence_order, status, signed_at)
        values (?, 'annual_plans', ?, 'PLA', decode(repeat('ab', 32), 'hex'), '01900000-0000-7000-8000-000000000303', 'approver', 1, 'signed', now()) returning id", [HUAYLAS_ID, $plan])->id;
    DB::insert("insert into signatures (company_id, signature_request_id, subject_type, subject_id, signer_user_id, signer_name, method, document_hash, evidence_hash, evidence, signed_at)
        values (?, ?, 'annual_plans', ?, '01900000-0000-7000-8000-000000000303', 'Supervisor', 'pin', decode(repeat('ab', 32), 'hex'), decode(repeat('cd', 32), 'hex'), '{}'::jsonb, now())", [HUAYLAS_ID, $req, $plan]);
    $this->patchJson("/api/v1/records/PLA/annual_plans/{$plan}", ['status' => 'approved'], $h)->assertOk();
    $this->postJson('/api/v1/records/PLA/annual_plan_items', ['annual_plan_id' => $plan, 'code' => 'P2', 'description' => 'Otro'], $h)->assertStatus(409);
});

it('cierra la auditoría solo con sus hallazgos atendidos', function () {
    $h = imp();
    $a = $this->postJson('/api/v1/records/AUI/audits', ['audit_type' => 'internal', 'standard' => 'ley_29783', 'starts_on' => now()->toDateString()], $h)->assertCreated();
    expect($a->json('data.code'))->toStartWith('AUD-');
    $id = $a->json('data.id');
    $f = $this->postJson('/api/v1/records/AUI/audit_findings', ['audit_id' => $id, 'finding_type' => 'minor_nc', 'description' => 'Registros incompletos'], $h)->assertCreated()->json('data.id');
    // Planificada → en curso → completada (con conclusiones) → cerrada (sin hallazgos pendientes).
    $this->patchJson("/api/v1/records/AUI/audits/{$id}", ['status' => 'closed'], $h)->assertStatus(422);
    $this->patchJson("/api/v1/records/AUI/audits/{$id}", ['status' => 'in_progress'], $h)->assertOk();
    $this->patchJson("/api/v1/records/AUI/audits/{$id}", ['status' => 'completed'], $h)->assertStatus(422);
    $this->patchJson("/api/v1/records/AUI/audit_findings/{$f}", ['status' => 'resolved'], $h)->assertStatus(422);
    $this->patchJson("/api/v1/records/AUI/audits/{$id}", ['status' => 'completed', 'conclusions' => 'Sistema implementado con observaciones'], $h)->assertOk();
    $this->patchJson("/api/v1/records/AUI/audits/{$id}", ['status' => 'closed'], $h)->assertStatus(422);
    $me = collect($this->getJson('/api/v1/records/ACR/corrective_actions/options/responsible_user_id', $h)->json('data'))->first()['id'];
    $this->postJson("/api/v1/acr/from/audit_findings/{$f}", ['title' => 'Completar registros', 'responsible_user_id' => $me, 'due_on' => now()->addDays(10)->toDateString()], $h)
        ->assertCreated();
    $this->getJson("/api/v1/records/AUI/audit_findings/{$f}", $h)->assertJsonPath('data.status', 'action_assigned');
});

it('la gestión del cambio exige revisión aprobatoria y evaluación de riesgos', function () {
    $h = imp();
    $mod = $this->getJson('/api/v1/records/MOC', $h);
    if ($mod->status() === 403) {
        $this->markTestSkipped('MOC es un complemento no contratado por la empresa de prueba.');
    }
    $c = $this->postJson('/api/v1/records/MOC/change_requests', ['title' => 'Nueva línea de envasado', 'change_type' => 'process', 'description' => 'Se agrega una línea'], $h)
        ->assertCreated();
    $id = $c->json('data.id');
    expect($c->json('data.code'))->toStartWith('MOC-');
    $this->patchJson("/api/v1/records/MOC/change_requests/{$id}", ['status' => 'submitted'], $h)->assertOk();
    $this->patchJson("/api/v1/records/MOC/change_requests/{$id}", ['status' => 'under_review'], $h)->assertOk();
    $this->patchJson("/api/v1/records/MOC/change_requests/{$id}", ['status' => 'approved'], $h)->assertStatus(422);
});

it('compara la medición con el límite de exposición', function () {
    $h = imp();
    $agent = collect($this->getJson('/api/v1/records/MAG/measurements/options/agent_type_id?q=ruido', $h)->json('data'))->first()['id'] ?? null;
    $limit = $this->postJson('/api/v1/records/MAG/exposure_limits', ['agent_type_id' => $agent, 'substance_name' => 'Ruido', 'limit_type' => 'TWA', 'limit_value' => 85, 'unit' => 'dB(A)',
        'valid_from' => '2020-01-01'], $h);
    // Si la empresa no puede crear límites propios, se usa el del catálogo general para ese agente.
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $limitId = $limit->status() === 201 ? $limit->json('data.id') : DB::selectOne("select id from exposure_limits where agent_type_id = ? and unit = 'dB(A)' and deleted_at is null limit 1", [$agent])?->id;
    if (! $limitId) {
        $this->markTestSkipped('No hay límite de ruido en el catálogo.');
    }
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'MAG-S', 'name' => 'Sede ruido'], $h)->json('data.id');
    $camp = $this->postJson('/api/v1/records/MAG/monitoring_campaigns', ['title' => 'Monitoreo de ruido 2026'], $h)->assertCreated()->json('data.id');
    $area = $this->postJson('/api/v1/records/TEN/areas', ['site_id' => $site, 'code' => 'MAG-A', 'name' => 'Prensas'], $h)->assertCreated()->json('data.id');
    $point = $this->postJson('/api/v1/records/MAG/monitoring_points', ['site_id' => $site, 'area_id' => $area, 'agent_type_id' => $agent, 'code' => 'P-01', 'name' => 'Prensa'], $h)
        ->assertCreated()->json('data.id');
    $m = $this->postJson('/api/v1/records/MAG/measurements', ['campaign_id' => $camp, 'monitoring_point_id' => $point, 'agent_type_id' => $agent,
        'exposure_limit_id' => $limitId, 'measured_at' => now()->toIso8601String(), 'value' => 92, 'unit' => 'dB(A)'], $h)->assertCreated();
    expect($m->json('data.result'))->toBe('exceeds_limit');

    // RN-MAG-03: al aprobarla, el IPERC del área queda «por revisar».
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $crit = DB::selectOne('select id from risk_criteria where company_id = ? or company_id is null order by company_id nulls last limit 1', [HUAYLAS_ID])->id;
    $mx = DB::selectOne("insert into iperc_matrices (company_id, code, title, risk_criteria_id, area_id, status) values (?, ?, 'IPERC prensas', ?, ?, 'active') returning id",
        [HUAYLAS_ID, 'IPC-MAG-'.random_int(1000, 99999), $crit, $area])->id;
    $this->patchJson('/api/v1/records/MAG/measurements/'.$m->json('data.id'), ['status' => 'approved'], $h)->assertOk();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne('select status from iperc_matrices where id = ?', [$mx])->status)->toBe('review_required');
});

it('completa la revisión por la dirección indicando los participantes (uno por línea en el formulario)', function () {
    $h = imp();
    $id = $this->postJson('/api/v1/records/RVD/management_reviews', ['year' => (int) now()->year + 4, 'title' => 'Revisión anual'], $h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/RVD/management_reviews/{$id}", ['status' => 'in_progress'], $h)->assertOk();
    $this->patchJson("/api/v1/records/RVD/management_reviews/{$id}", ['status' => 'completed', 'conclusions' => 'Sistema eficaz'], $h)
        ->assertStatus(422)->assertJsonPath('errors.participants.0', 'Indica quiénes participaron (la alta dirección participa en la revisión).');
    $this->patchJson("/api/v1/records/RVD/management_reviews/{$id}", ['participants' => ['Gerente general', 'Jefe SST'], 'status' => 'completed', 'conclusions' => 'Sistema eficaz'], $h)
        ->assertOk()->assertJsonPath('data.status', 'completed');
    expect($this->getJson("/api/v1/records/RVD/management_reviews/{$id}", $h)->json('data.participants'))->toBe(['Gerente general', 'Jefe SST']);
});

it('un cambio temporal se revierte con constancia o se vuelve permanente con una revisión nueva (RN-MOC-04)', function () {
    $h = imp();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'MOC' and deleted_at is null", [HUAYLAS_ID]);
    app('auth')->forgetGuards();
    \App\Core\Auth\Permissions::flush();
    $mk = fn (string $code) => DB::selectOne("insert into change_requests (company_id, code, title, change_type, description, is_temporary, temporary_until, status, impact_level, risk_assessment, implemented_at)
        values (?, ?, 'Desvío temporal de tránsito', 'process', 'Se desvía el tránsito por obras', true, current_date + 10, 'implemented', 'low', '{\"resumen\": \"Riesgo bajo\"}'::jsonb, now() - interval '1 day') returning id",
        [HUAYLAS_ID, $code.'-'.random_int(1000, 999999)])->id;
    $me = DB::connection('platform')->selectOne('select id from users where email = ?', [CORPORATIVO_EMAIL])->id;
    $revert = $mk('MOC-T1');
    $perm = $mk('MOC-T2');
    DB::insert("insert into change_reviews (company_id, change_request_id, reviewer_user_id, review_area, decision, reviewed_at, is_required) values (?, ?, ?, 'sst', 'approve', now() - interval '2 days', true)", [HUAYLAS_ID, $perm, $me]);
    DB::select("select set_config('app.company_id', '', false)");

    // Revertir: se cierra con la constancia de cómo se revirtió.
    $this->patchJson("/api/v1/records/MOC/change_requests/{$revert}", ['status' => 'closed'], $h)->assertStatus(422)->assertJsonValidationErrors('closure_notes');
    $this->patchJson("/api/v1/records/MOC/change_requests/{$revert}", ['status' => 'closed', 'closure_notes' => 'Se restituyó la ruta original el viernes'], $h)->assertOk();

    // Hacerlo permanente: vuelve a revisión y la revisión antigua no basta para aprobarlo.
    $this->patchJson("/api/v1/records/MOC/change_requests/{$perm}", ['is_temporary' => false], $h)->assertOk()->assertJsonPath('data.status', 'under_review');
    $this->patchJson("/api/v1/records/MOC/change_requests/{$perm}", ['status' => 'approved'], $h)->assertStatus(422)
        ->assertJsonPath('errors.status.0', fn ($m) => str_contains($m, 'revisión nueva'));
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::insert("insert into change_reviews (company_id, change_request_id, reviewer_user_id, review_area, decision, reviewed_at, is_required) values (?, ?, ?, 'sst', 'approve', now(), true)", [HUAYLAS_ID, $perm, $me]);
    DB::select("select set_config('app.company_id', '', false)");
    $this->patchJson("/api/v1/records/MOC/change_requests/{$perm}", ['status' => 'approved'], $h)->assertOk();
});
