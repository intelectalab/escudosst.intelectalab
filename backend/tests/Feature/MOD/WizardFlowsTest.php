<?php

use Illuminate\Support\Facades\DB;

// Los asistentes de la app (docs/07 §3.3) usan los endpoints existentes con estas secuencias y datos.
// Línea base, registrar evento, solicitar PETAR y expediente SUNAFIL.

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

it('asistente de línea base: crea, carga preguntas, responde y aprueba con brechas', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $a = $this->postJson('/api/v1/records/LBA/baseline_assessments', ['title' => 'Línea base asistente', 'assessment_on' => date('Y-m-d'), 'site_id' => null, 'is_initial' => true], $h)
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/lba/assessments/{$a}/load", [], $h)->assertOk();
    $items = $this->getJson("/api/v1/records/LBA/baseline_items?f[assessment_id]={$a}&per_page=100", $h)->json('data');
    expect($items)->not->toBeEmpty();
    foreach ($items as $k => $i) {
        $this->patchJson("/api/v1/records/LBA/baseline_items/{$i['id']}", ['result' => $k === 0 ? 'non_compliant' : 'compliant', 'evidence_notes' => null], $h)->assertOk();
    }
    $this->patchJson("/api/v1/records/LBA/baseline_assessments/{$a}", ['status' => 'approved'], $h)->assertOk();
    expect($this->getJson("/api/v1/records/LBA/baseline_gaps?f[assessment_id]={$a}", $h)->json('meta.total'))->toBe(1);
});

it('asistente de evento: evento, afectado, testigo y notificación con constancia', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'WZ-ACC', 'name' => 'Sede asistente'], $h)->json('data.id');
    $w1 = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '70001111', 'first_name' => 'Ana', 'paternal_last_name' => 'Afectada'], $h)->json('data.id');
    $w2 = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '70002222', 'first_name' => 'Tito', 'paternal_last_name' => 'Testigo'], $h)->json('data.id');
    $ev = $this->postJson('/api/v1/records/ACC/sst_events', ['event_type' => 'minor_accident', 'occurred_at' => now()->subHours(2)->toIso8601String(), 'site_id' => $site,
        'exact_location' => 'Línea 3', 'description' => 'Corte en la mano al retirar una pieza', 'activity_at_time' => null, 'immediate_actions' => 'Se detuvo la máquina',
        'origin' => 'web', 'witnesses' => ['Tito Testigo']], $h)->assertCreated();
    $id = $ev->json('data.id');
    expect($ev->json('data.code'))->toStartWith('ACC-');
    $this->postJson('/api/v1/records/ACC/event_people', ['event_id' => $id, 'person_role' => 'injured', 'worker_id' => $w1, 'external_person_name' => 'Ana Afectada',
        'injury_type' => 'Corte', 'body_part' => 'Mano izquierda', 'lost_days' => 2, 'medical_attention_place' => 'Tópico de la sede'], $h)->assertCreated();
    $this->postJson('/api/v1/records/ACC/event_people', ['event_id' => $id, 'person_role' => 'witness', 'worker_id' => $w2, 'external_person_name' => 'Tito Testigo'], $h)->assertCreated();
    $n = $this->getJson("/api/v1/records/ACC/event_notifications?f[event_id]={$id}", $h)->json('data.0');
    expect($n['status'])->toBe('pending');
    $this->patchJson("/api/v1/records/ACC/event_notifications/{$n['id']}", ['status' => 'sent', 'channel' => 'sat_online', 'notification_number' => 'SAT-123',
        'notified_at' => now()->toIso8601String()], $h)->assertOk();
    $e = $this->getJson("/api/v1/records/ACC/sst_events/{$id}", $h)->json('data');
    expect($e['status'])->toBe('notified')->and((int) $e['lost_days'])->toBe(2)->and((int) $e['injured_count'])->toBe(1);
});

it('asistente de PETAR: permiso con cuadrilla y verificaciones enviado a aprobación', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'WZ-PTR', 'name' => 'Sede PETAR'], $h)->json('data.id');
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '70003333', 'first_name' => 'Eli', 'paternal_last_name' => 'Altura'], $h)->json('data.id');
    // Los tipos con su lista y cadena se configuran fuera del motor común (checklist y approval_chain son de solo lectura allí).
    $type = DB::selectOne(
        "insert into permit_types (company_id, code, name, category, validity_hours, requires_ats, checklist, approval_chain)
         values (?, 'WZ-ALT', 'Altura asistente', 'work_at_height', 8, false, ?::jsonb, ?::jsonb) returning id",
        [HUAYLAS_ID, json_encode([['code' => 'C1', 'question' => '¿Arnés inspeccionado?', 'phase' => 'pre_start']]), json_encode([['step' => 1, 'role' => 'sst_supervisor']])]
    )->id;
    $p = $this->postJson('/api/v1/records/PTR/work_permits', ['permit_type_id' => $type, 'site_id' => $site, 'location_text' => 'Techo', 'work_description' => 'Cambio de luminarias',
        'executor_leader_worker_id' => $w, 'crew' => [['worker_id' => $w, 'name' => 'Eli Altura', 'role' => 'leader']], 'valid_from' => now()->addHour()->toIso8601String()], $h)
        ->assertCreated();
    $id = $p->json('data.id');
    $this->postJson('/api/v1/records/PTR/permit_checks', ['work_permit_id' => $id, 'check_phase' => 'pre_start', 'item_code' => 'C1', 'question' => '¿Arnés inspeccionado?', 'answer' => 'yes', 'comment' => null], $h)
        ->assertCreated();
    $this->patchJson("/api/v1/records/PTR/work_permits/{$id}", ['status' => 'pending_approval'], $h)->assertOk()->assertJsonPath('data.status', 'pending_approval');
    expect($this->getJson("/api/v1/records/PTR/permit_approvals?f[work_permit_id]={$id}", $h)->json('meta.total'))->toBe(1);
});

it('asistente de expediente SUNAFIL: crea con orden, inspector y obligaciones y lo arma', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $d = $this->postJson('/api/v1/records/SUN/inspection_dossiers', ['title' => 'Inspección SUNAFIL · Orden 123-2026', 'request_type' => 'sunafil_inspection',
        'inspection_order_number' => '123-2026', 'inspector_name' => 'Inspectora Prueba', 'appearance_at' => now()->addDays(3)->toIso8601String(),
        'period_from' => date('Y').'-01-01', 'period_to' => date('Y-m-d'), 'site_id' => null, 'scope_modules' => ['CAP', 'EMO'],
        'scope' => ['obligations' => ['CAP', 'EMO'], 'worker_id' => null]], $h)->assertCreated()->json('data.id');
    $b = $this->postJson("/api/v1/sun/dossiers/{$d}/build", ['worker_id' => null], $h)->assertOk();
    expect($b->json('data.total'))->toBeGreaterThan(0);
    expect($this->getJson("/api/v1/records/SUN/inspection_dossiers/{$d}", $h)->json('data.inspection_order_number'))->toBe('123-2026');
});
