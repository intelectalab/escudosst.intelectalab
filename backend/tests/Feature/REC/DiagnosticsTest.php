<?php

// Línea base (puntaje, «no aplica» con comentario, brechas al aprobar y cierre con la acción),
// requisitos legales (motivo de no aplicable, próxima evaluación, evaluaciones inmutables) y estadísticas del mes.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function dg(): array
{
    return authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
}

it('calcula la línea base, exige comentario en «no aplica» y genera brechas al aprobar', function () {
    $h = dg();
    $a = $this->postJson('/api/v1/records/LBA/baseline_assessments', ['title' => 'Línea base 2026', 'reference_norm' => 'R.M. 050-2013-TR'], $h)
        ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
    $this->patchJson("/api/v1/records/LBA/baseline_assessments/{$a}", ['status' => 'approved'], $h)->assertStatus(422);

    $this->postJson("/api/v1/lba/assessments/{$a}/load", [], $h)->assertOk()->assertJsonPath('data.added', 9);
    $this->postJson("/api/v1/lba/assessments/{$a}/load", [], $h)->assertOk()->assertJsonPath('data.added', 0);

    $items = $this->getJson("/api/v1/records/LBA/baseline_items?f[assessment_id]={$a}&per_page=100", $h)->json('data');
    $this->patchJson("/api/v1/records/LBA/baseline_items/{$items[0]['id']}", ['result' => 'not_applicable'], $h)
        ->assertStatus(422)->assertJsonPath('errors.evidence_notes.0', 'Si el ítem no aplica, explica por qué en las notas.');
    $results = ['not_applicable', 'compliant', 'compliant', 'compliant', 'compliant', 'partial', 'non_compliant', 'compliant', 'compliant'];
    foreach ($items as $k => $it) {
        $this->patchJson("/api/v1/records/LBA/baseline_items/{$it['id']}", ['result' => $results[$k], 'evidence_notes' => 'Revisado en campo'], $h)->assertOk();
    }
    // 8 aplicables: 6 cumple + 0,5 parcial = 6,5 / 8 = 81,25 %
    $this->getJson("/api/v1/records/LBA/baseline_assessments/{$a}", $h)->assertJsonPath('data.score_pct', 81.25)
        ->assertJsonPath('data.total_items', 8)->assertJsonPath('data.compliant_items', 6)->assertJsonPath('data.status', 'in_progress');

    $this->patchJson("/api/v1/records/LBA/baseline_assessments/{$a}", ['status' => 'approved'], $h)->assertOk();
    $gaps = $this->getJson("/api/v1/records/LBA/baseline_gaps?f[assessment_id]={$a}", $h)->json('data');
    expect($gaps)->toHaveCount(2);
    $this->patchJson("/api/v1/records/LBA/baseline_items/{$items[1]['id']}", ['result' => 'partial'], $h)->assertStatus(409);

    // Acción desde la brecha; al verificarse como eficaz, la brecha se cierra.
    $me = collect($this->getJson('/api/v1/records/ACR/corrective_actions/options/responsible_user_id', $h)->json('data'))->first()['id'];
    $act = $this->postJson("/api/v1/acr/from/baseline_gaps/{$gaps[0]['id']}", ['title' => 'Implementar lo faltante', 'responsible_user_id' => $me,
        'due_on' => now()->addDays(20)->toDateString()], $h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/ACR/action_evidences', ['corrective_action_id' => $act, 'evidence_type' => 'document', 'description' => 'Hecho'], $h);
    $this->patchJson("/api/v1/records/ACR/corrective_actions/{$act}", ['status' => 'pending_verification'], $h)->assertOk();
    DB::update('update corrective_actions set responsible_user_id = ? where id = ?', ['01900000-0000-7000-8000-000000000301', $act]);
    $this->patchJson("/api/v1/records/ACR/corrective_actions/{$act}", ['status' => 'verified', 'effectiveness' => 'effective'], $h)->assertOk();
    $this->getJson("/api/v1/records/LBA/baseline_gaps/{$gaps[0]['id']}", $h)->assertJsonPath('data.status', 'closed');
});

it('lleva la matriz legal: no aplicable con motivo, próxima evaluación y evaluaciones inmutables', function () {
    $h = dg();
    $norm = collect($this->getJson('/api/v1/records/MRL/legal_requirements/options/legal_norm_id?q=29783', $h)->json('data'))->first()['id'];
    $this->postJson('/api/v1/records/MRL/legal_requirements', ['legal_norm_id' => $norm, 'article' => 'Art. 35', 'requirement' => 'Capacitar 4 veces al año',
        'applies' => false], $h)->assertStatus(422)->assertJsonPath('errors.applicability.0', 'Si el requisito no aplica, explica el motivo.');
    $req = $this->postJson('/api/v1/records/MRL/legal_requirements', ['legal_norm_id' => $norm, 'article' => 'Art. 35', 'requirement' => 'Capacitar 4 veces al año',
        'applies' => true, 'status' => 'active'], $h)->assertCreated()->json('data.id');

    $ev = $this->postJson('/api/v1/records/MRL/requirement_evaluations', ['legal_requirement_id' => $req, 'result' => 'partial', 'evidence_notes' => 'Solo 2 de 4'], $h)
        ->assertCreated();
    expect($ev->json('data.next_evaluation_on'))->toBe(now()->addMonthsNoOverflow(12)->toDateString());
    $this->getJson("/api/v1/records/MRL/legal_requirements/{$req}", $h)->assertJsonPath('data.next_evaluation_on', $ev->json('data.next_evaluation_on'));
    $this->patchJson("/api/v1/records/MRL/requirement_evaluations/{$ev->json('data.id')}", ['result' => 'compliant'], $h)->assertStatus(409);
});

it('calcula el mes estadístico con los factores legales y no lo cierra sin horas-hombre de todas las sedes', function () {
    $h = dg();
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'EST-S', 'name' => 'Sede estadística'], $h)->json('data.id');
    $this->postJson('/api/v1/records/EST/man_hours', ['period_year' => 2026, 'period_month' => 10, 'site_id' => $site, 'worker_count' => 50, 'hours_worked' => 10000], $h)->assertCreated();
    $ev = $this->postJson('/api/v1/records/ACC/sst_events', ['event_type' => 'disabling_accident', 'occurred_at' => '2026-10-10T09:00:00-05:00', 'site_id' => $site,
        'description' => 'Golpe'], $h)->json('data.id');
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '80808080', 'first_name' => 'Pedro', 'paternal_last_name' => 'Soto'], $h)->json('data.id');
    $this->postJson('/api/v1/records/ACC/event_people', ['event_id' => $ev, 'worker_id' => $w, 'person_role' => 'injured', 'lost_days' => 4,
        'injury_type' => 'Contusión', 'body_part' => 'Pierna', 'injury_agent' => 'Caída de material'], $h)->assertCreated();

    $rows = collect($this->postJson('/api/v1/est/months', ['year' => 2026, 'month' => 10], $h)->assertOk()->json('data'));
    $s = $rows->firstWhere('site_id', $site);
    // IF = 1 × 1 000 000 / 10 000 = 100; IG = 4 × 1 000 000 / 10 000 = 400; IA = 100 × 400 / 1000 = 40
    expect($s['frequency_index'])->toEqual(100)->and($s['severity_index'])->toEqual(400)->and($s['accident_rate_index'])->toEqual(40);

    $this->postJson('/api/v1/records/EST/monthly_statistics', ['period_year' => 2026, 'period_month' => 10], $h)->assertStatus(409);
    $id = $this->getJson("/api/v1/records/EST/monthly_statistics?f[site_id]={$site}", $h)->json('data.0.id');
    // Otras sedes activas de la empresa sin horas-hombre → no se cierra.
    $this->postJson("/api/v1/est/months/{$id}/close", [], $h)->assertStatus(422);
    $this->postJson('/api/v1/est/months', ['year' => 2099, 'month' => 1], $h)->assertStatus(422);
});
