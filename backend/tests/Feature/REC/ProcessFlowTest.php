<?php

// Proceso de mejora continua de punta a punta: inspección con checklist → puntaje y hallazgos → acción correctiva
// → evidencia → verificación por otra persona → hallazgo resuelto e inspección cerrada. Con trazabilidad y filtros.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function pf(): array
{
    return authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
}

/** Plantilla con 10 ítems de peso 1 (uno crítico) e inspección programada para ayer. */
function pfInspection($test): array
{
    $h = pf();
    $site = $test->postJson('/api/v1/records/TEN/sites', ['code' => 'PF-S', 'name' => 'Planta flujo'], $h)->json('data.id');
    $tpl = $test->postJson('/api/v1/records/INS/inspection_templates', ['code' => 'PF-TPL', 'name' => 'Orden y limpieza', 'inspection_type' => 'housekeeping',
        'scoring_method' => 'weighted', 'status' => 'active'], $h)->assertCreated()->json('data.id');
    $items = [];
    for ($k = 1; $k <= 10; $k++) {
        $items[] = $test->postJson('/api/v1/records/INS/inspection_template_items', ['template_id' => $tpl, 'item_code' => "I{$k}", 'question' => "¿Ítem {$k} en orden?",
            'answer_type' => 'yes_no_na', 'weight' => 1, 'is_critical' => $k === 10, 'sort_order' => $k], $h)->assertCreated()->json('data.id');
    }
    $insp = $test->postJson('/api/v1/records/INS/inspections', ['title' => 'Inspección mensual', 'inspection_type' => 'housekeeping', 'template_id' => $tpl,
        'site_id' => $site, 'scheduled_on' => now('America/Lima')->subDay()->toDateString()], $h)->assertCreated();
    expect($insp->json('data.code'))->toStartWith('INS-');

    return [$insp->json('data.id'), $items, $site];
}

it('ejecuta el checklist: 7 cumple, 2 no cumple y 1 NA dan 77,8 % y 2 hallazgos (criterio INS-1)', function () {
    [$insp, $items] = pfInspection($this);
    $answers = [];
    foreach ($items as $k => $id) {
        $answers[] = ['item_id' => $id, 'answer' => $k < 7 ? 'yes' : ($k < 9 ? 'no' : 'na'), 'comment' => $k === 7 ? 'Pasillo obstruido' : null];
    }
    // Falta un ítem → no deja finalizar.
    $this->postJson("/api/v1/ins/inspections/{$insp}/answers", ['answers' => array_slice($answers, 0, 9)], pf())->assertOk()
        ->assertJsonPath('data.inspection.status', 'in_progress');
    $this->postJson("/api/v1/ins/inspections/{$insp}/finish", [], pf())->assertStatus(422);

    $this->postJson("/api/v1/ins/inspections/{$insp}/answers", ['answers' => [$answers[9]]], pf())->assertOk();
    $this->postJson("/api/v1/ins/inspections/{$insp}/finish", ['summary' => 'Revisión de planta'], pf())->assertOk()
        ->assertJsonPath('data.score_pct', 77.78)->assertJsonPath('data.findings_created', 2);

    $this->patchJson("/api/v1/records/INS/inspections/{$insp}", ['title' => 'Otro'], pf())->assertStatus(409);
    $this->postJson("/api/v1/ins/inspections/{$insp}/answers", ['answers' => [$answers[0]]], pf())->assertStatus(409);
});

it('sigue el ciclo de la acción correctiva hasta cerrar el hallazgo y la inspección, con trazabilidad', function () {
    [$insp, $items] = pfInspection($this);
    $answers = array_map(fn ($id, $k) => ['item_id' => $id, 'answer' => $k === 0 ? 'no' : 'yes'], $items, array_keys($items));
    $this->postJson("/api/v1/ins/inspections/{$insp}/answers", ['answers' => $answers], pf())->assertOk();
    $this->postJson("/api/v1/ins/inspections/{$insp}/finish", [], pf())->assertOk();

    $finding = $this->getJson("/api/v1/ins/inspections/{$insp}/checklist", pf())->json('data.findings.0.id');
    $me = collect($this->getJson('/api/v1/records/ACR/corrective_actions/options/responsible_user_id', pf())->json('data'))->first()['id'];

    $this->postJson("/api/v1/ins/findings/{$finding}/action", ['title' => 'Despejar pasillo', 'responsible_user_id' => $me, 'due_on' => '2020-01-01'], pf())
        ->assertStatus(422)->assertJsonPath('errors.due_on.0', 'La fecha meta debe ser hoy o posterior.');
    $action = $this->postJson("/api/v1/ins/findings/{$finding}/action", ['title' => 'Despejar pasillo', 'responsible_user_id' => $me,
        'due_on' => now('America/Lima')->addDays(7)->toDateString()], pf())->assertCreated()->json('data.id');

    // Trazabilidad: la acción viene del hallazgo; el hallazgo viene de la inspección y generó la acción.
    $trace = $this->getJson("/api/v1/records/ACR/corrective_actions/{$action}/trace", pf())->assertOk()->json('data');
    expect(collect($trace['parents'])->pluck('table')->all())->toContain('findings');
    $ftrace = $this->getJson("/api/v1/records/INS/findings/{$finding}/trace", pf())->json('data');
    expect(collect($ftrace['parents'])->pluck('table')->all())->toContain('inspections')
        ->and(collect($ftrace['children'])->pluck('table')->all())->toContain('corrective_actions');
    $itrace = $this->getJson("/api/v1/records/INS/inspections/{$insp}/trace", pf())->json('data');
    expect(collect($itrace['children'])->firstWhere('table', 'findings')['count'])->toBe(1);

    // Ciclo: sin evidencia no pasa a verificación; con evidencia sí; el responsable no verifica su propia acción.
    $this->patchJson("/api/v1/records/ACR/corrective_actions/{$action}", ['status' => 'pending_verification'], pf())
        ->assertStatus(422)->assertJsonPath('errors.status.0', 'Agrega al menos una evidencia de lo realizado antes de enviarla a verificación.');
    $this->postJson('/api/v1/records/ACR/action_evidences', ['corrective_action_id' => $action, 'evidence_type' => 'photo', 'description' => 'Pasillo despejado'], pf())
        ->assertCreated();
    $this->getJson("/api/v1/records/ACR/corrective_actions/{$action}", pf())->assertJsonPath('data.status', 'in_progress');
    $this->patchJson("/api/v1/records/ACR/corrective_actions/{$action}", ['status' => 'pending_verification'], pf())->assertOk();
    $this->patchJson("/api/v1/records/ACR/corrective_actions/{$action}", ['status' => 'verified', 'effectiveness' => 'effective'], pf())
        ->assertStatus(422)->assertJsonPath('errors.status.0', 'El responsable no puede verificar su propia acción: la verifica el área SST u otra persona.');

    // Otra persona como responsable → ahora sí se verifica (eficaz) y el origen se cierra.
    DB::update('update corrective_actions set responsible_user_id = ? where id = ?', ['01900000-0000-7000-8000-000000000301', $action]);
    $this->patchJson("/api/v1/records/ACR/corrective_actions/{$action}", ['status' => 'verified', 'effectiveness' => 'effective'], pf())->assertOk()
        ->assertJsonPath('data.status', 'verified');
    expect(DB::table('findings')->where('id', $finding)->value('status'))->toBe('resolved')
        ->and(DB::table('inspections')->where('id', $insp)->value('status'))->toBe('closed');
    $this->patchJson("/api/v1/records/ACR/corrective_actions/{$action}", ['title' => 'Cambio'], pf())->assertStatus(409);
});

it('una acción no eficaz abre otra con el mismo origen (RN-ACR-05) y anular pide motivo', function () {
    $h = pf();
    $me = collect($this->getJson('/api/v1/records/ACR/corrective_actions/options/responsible_user_id', $h)->json('data'))->first()['id'];
    $this->postJson('/api/v1/records/ACR/corrective_actions', ['source_type' => 'inspection', 'title' => 'X', 'responsible_user_id' => $me,
        'due_on' => now()->addDay()->toDateString()], $h)->assertStatus(422)->assertJsonPath('errors.source_type.0', 'Vincula la acción a su registro de origen, o elige origen «Otro» y descríbelo.');

    $a = $this->postJson('/api/v1/records/ACR/corrective_actions', ['source_type' => 'other', 'description' => 'Pedido de gerencia', 'title' => 'Señalizar escalera',
        'responsible_user_id' => '01900000-0000-7000-8000-000000000301', 'due_on' => now()->addDay()->toDateString()], $h);
    // El responsable debe ser de la empresa: el usuario 301 no lo es → la referencia no existe.
    $a->assertStatus(422);
    $id = $this->postJson('/api/v1/records/ACR/corrective_actions', ['source_type' => 'other', 'description' => 'Pedido de gerencia', 'title' => 'Señalizar escalera',
        'responsible_user_id' => $me, 'due_on' => now()->addDay()->toDateString()], $h)->assertCreated()->assertJsonPath('data.status', 'open')->json('data.id');

    $this->patchJson("/api/v1/records/ACR/corrective_actions/{$id}", ['status' => 'cancelled'], $h)
        ->assertStatus(422)->assertJsonPath('errors.cancel_reason.0', 'Para anular la acción indica el motivo.');

    $this->postJson('/api/v1/records/ACR/action_evidences', ['corrective_action_id' => $id, 'evidence_type' => 'photo', 'description' => 'Cinta colocada'], $h)->assertCreated();
    $this->patchJson("/api/v1/records/ACR/corrective_actions/{$id}", ['status' => 'pending_verification'], $h)->assertOk();
    DB::update('update corrective_actions set responsible_user_id = ? where id = ?', ['01900000-0000-7000-8000-000000000301', $id]);
    $this->patchJson("/api/v1/records/ACR/corrective_actions/{$id}", ['status' => 'verified', 'effectiveness' => 'not_effective', 'verification_notes' => 'Se despegó'], $h)->assertOk();

    expect(DB::table('corrective_actions')->where('code', 'like', '%-R')->where('title', 'Repetir: Señalizar escalera')->count())->toBe(1);
});

it('filtra listas por fechas, varias opciones, vacío y origen (enlaces del tablero)', function () {
    $h = pf();
    $me = collect($this->getJson('/api/v1/records/ACR/corrective_actions/options/responsible_user_id', $h)->json('data'))->first()['id'];
    $id = $this->postJson('/api/v1/records/ACR/corrective_actions', ['source_type' => 'other', 'description' => 'x', 'title' => 'Filtro',
        'responsible_user_id' => $me, 'due_on' => now('America/Lima')->toDateString()], $h)->json('data.id');
    DB::update("update corrective_actions set due_on = (now() at time zone 'America/Lima')::date - 2 where id = ?", [$id]);

    $ids = fn (string $q) => collect($this->getJson("/api/v1/records/ACR/corrective_actions?per_page=100&{$q}", $h)->assertOk()->json('data'))->pluck('id');
    expect($ids('f[status]=open,in_progress,overdue&f[due_on__lt]=today'))->toContain($id)
        ->and($ids('f[due_on__gte]=today'))->not->toContain($id)
        ->and($ids('f[status]=verified'))->not->toContain($id)
        ->and($ids('f[verified_at__null]=1'))->toContain($id);
});
