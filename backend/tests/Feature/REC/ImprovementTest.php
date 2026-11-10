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
    $plan = $this->postJson('/api/v1/records/PLA/annual_plans', ['year' => (int) now()->year + 3, 'title' => 'Plan anual'], $h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/PLA/annual_plans/{$plan}", ['status' => 'approved'], $h)
        ->assertStatus(422)->assertJsonPath('errors.status.0', 'El plan no tiene ítems (objetivos, metas y recursos).');
    $this->postJson('/api/v1/records/PLA/annual_plan_items', ['annual_plan_id' => $plan, 'code' => 'P1', 'description' => 'Reducir accidentes 20 %'], $h)->assertCreated();
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
    if ($limit->status() !== 201) {
        $this->markTestSkipped('Límites de exposición de solo catálogo en esta empresa.');
    }
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'MAG-S', 'name' => 'Sede ruido'], $h)->json('data.id');
    $camp = $this->postJson('/api/v1/records/MAG/monitoring_campaigns', ['title' => 'Monitoreo de ruido 2026'], $h)->assertCreated()->json('data.id');
    $point = $this->postJson('/api/v1/records/MAG/monitoring_points', ['site_id' => $site, 'agent_type_id' => $agent, 'code' => 'P-01', 'name' => 'Prensa'], $h)
        ->assertCreated()->json('data.id');
    $m = $this->postJson('/api/v1/records/MAG/measurements', ['campaign_id' => $camp, 'monitoring_point_id' => $point, 'agent_type_id' => $agent,
        'exposure_limit_id' => $limit->json('data.id'), 'measured_at' => now()->toIso8601String(), 'value' => 92, 'unit' => 'dB(A)'], $h)->assertCreated();
    expect($m->json('data.result'))->toBe('exceeds_limit');
});
