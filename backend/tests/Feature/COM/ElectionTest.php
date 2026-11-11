<?php

// Elección electrónica del comité (M-COM SCR-COM-02..06): convocatoria, padrón con exclusión justificada, candidaturas
// con requisitos, voto secreto y único, resultados solo tras el cierre, escrutinio con desempate por antigüedad y acta.
// Comité vigente con convocatoria a reunión (aviso y correo) y acta de reunión.

use App\Modules\COM\CommitteeNoticeMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function comH(): array
{
    return authHeaders(tokenFor(ESENCIAL_EMAIL), AGRO_ID);
}

function comWorker(array $h, string $doc, string $name, string $hire, string $birth = '1990-01-01'): string
{
    $id = test()->postJson('/api/v1/records/TRB/workers', ['document_number' => $doc, 'first_name' => $name, 'paternal_last_name' => 'Prueba'], $h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [AGRO_ID]);
    DB::update('update workers set hire_on = ?, birth_on = ? where id = ?', [$hire, $birth, $id]);

    return $id;
}

/** El usuario de prueba (Jefe SST) queda vinculado al trabajador indicado: vota como ese elector. */
function comLink(string $worker): void
{
    DB::select("select set_config('app.company_id', ?, false)", [AGRO_ID]);
    $uid = DB::selectOne('select id from app_auth_lookup_user(?)', [ESENCIAL_EMAIL])->id;
    DB::update('update workers set user_id = null where user_id = ?', [$uid]);
    DB::update('update workers set user_id = ? where id = ?', [$uid, $worker]);
}

it('lleva la elección de la convocatoria al acta con voto secreto, único y desempate por antigüedad', function () {
    $h = comH();
    $a = comWorker($h, '71000001', 'Ana', '2015-03-01');
    $b = comWorker($h, '71000002', 'Bruno', '2018-06-01');
    $c = comWorker($h, '71000003', 'Carla', '2020-01-15');
    $d = comWorker($h, '71000004', 'Diego', '2010-01-01');
    $young = comWorker($h, '71000005', 'Eva', '2024-01-01', date('Y-m-d', strtotime('-16 years')));

    $e = $this->postJson('/api/v1/com/elections', ['title' => 'Elección 2027–2029', 'seats_titular' => 1, 'seats_alternate' => 1, 'tie_break_rule' => 'seniority',
        'electoral_board' => [['name' => 'Percy Rojas', 'role' => 'Presidente']]], $h)->assertCreated()->assertJsonPath('data.step', 0)->json('data.id');
    $this->postJson('/api/v1/com/elections', ['title' => 'Otra', 'seats_titular' => 1], $h)->assertStatus(409);

    // Inscripción: el padrón se arma con los trabajadores activos.
    $this->postJson("/api/v1/com/elections/{$e}/open-nominations", [], $h)->assertOk()->assertJsonPath('data.step', 1);
    $roll = $this->getJson("/api/v1/com/elections/{$e}/roll", $h)->assertOk();
    $dRoll = collect($roll->json('data'))->firstWhere('worker_id', $d)['id'];
    $this->patchJson("/api/v1/com/elections/{$e}/roll/{$dRoll}", ['excluded' => true], $h)->assertStatus(422);
    $this->patchJson("/api/v1/com/elections/{$e}/roll/{$dRoll}", ['excluded' => true, 'reason' => 'Gerente de planta (personal de dirección)'], $h)->assertOk();
    $this->postJson("/api/v1/com/elections/{$e}/candidates", ['worker_id' => $d], $h)->assertStatus(422);   // excluido no postula

    foreach ([$a, $b, $c, $young] as $w) {
        $this->postJson("/api/v1/com/elections/{$e}/candidates", ['worker_id' => $w], $h)->assertCreated();
    }
    $this->postJson("/api/v1/com/elections/{$e}/candidates", ['worker_id' => $a], $h)->assertStatus(409);
    $cands = collect($this->getJson("/api/v1/com/elections/{$e}", $h)->json('data.candidates'))->keyBy('worker_id');
    // Requisito de edad mínima (parámetro): no se puede habilitar; se observa con motivo.
    $this->postJson("/api/v1/com/elections/{$e}/candidates/{$cands[$young]['id']}/review", ['decision' => 'accept'], $h)->assertStatus(422);
    $this->postJson("/api/v1/com/elections/{$e}/candidates/{$cands[$young]['id']}/review", ['decision' => 'reject'], $h)->assertStatus(422);
    $this->postJson("/api/v1/com/elections/{$e}/candidates/{$cands[$young]['id']}/review", ['decision' => 'reject', 'reason' => 'Menor de edad'], $h)->assertOk();
    $this->postJson("/api/v1/com/elections/{$e}/open-voting", ['voting_closes_at' => now()->addDay()->toIso8601String()], $h)->assertStatus(422); // sin revisar
    foreach ([$a, $b, $c] as $w) {
        $this->postJson("/api/v1/com/elections/{$e}/candidates/{$cands[$w]['id']}/review", ['decision' => 'accept'], $h)->assertOk();
    }

    $this->postJson("/api/v1/com/elections/{$e}/open-voting", [], $h)->assertStatus(422);   // falta el cierre
    $open = $this->postJson("/api/v1/com/elections/{$e}/open-voting", ['voting_closes_at' => now()->addDay()->toIso8601String()], $h)->assertOk()
        ->assertJsonPath('data.step', 2)->assertJsonPath('data.eligible_voters', 4);   // 5 trabajadores, 1 excluido
    expect($open->json('data.roll_snapshot_at'))->not->toBeNull();
    $this->patchJson("/api/v1/com/elections/{$e}/roll/{$dRoll}", ['excluded' => false], $h)->assertStatus(409);   // padrón congelado

    // El excluido no vota.
    comLink($d);
    $this->postJson("/api/v1/com/elections/{$e}/vote", ['candidate_id' => $cands[$b]['id']], $h)->assertStatus(403);

    // Tres electores: Bruno 1, Carla 1, un voto en blanco.
    comLink($a);
    $this->getJson("/api/v1/com/elections/{$e}/ballot", $h)->assertOk()->assertJsonPath('data.on_roll', true)->assertJsonPath('data.has_voted', false)
        ->assertJsonCount(3, 'data.candidates');
    $r = $this->postJson("/api/v1/com/elections/{$e}/vote", ['candidate_id' => $cands[$b]['id']], $h)->assertCreated();
    expect($r->json('data.receipt'))->toMatch('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/');
    $this->postJson("/api/v1/com/elections/{$e}/vote", ['candidate_id' => $cands[$c]['id']], $h)->assertStatus(409)->assertJsonPath('type', 'already_voted');
    comLink($b);
    $this->postJson("/api/v1/com/elections/{$e}/vote", ['candidate_id' => $cands[$c]['id']], $h)->assertCreated();
    comLink($c);
    $this->postJson("/api/v1/com/elections/{$e}/vote", ['blank' => true], $h)->assertCreated();

    // Durante la votación: participación sí, resultados no (RN-COM-04).
    $live = $this->getJson("/api/v1/com/elections/{$e}", $h)->assertOk()->assertJsonPath('data.votes_cast', 3)->assertJsonPath('data.results', null);
    expect(collect($live->json('data.candidates'))->pluck('votes_count')->filter()->all())->toBe([]);
    $this->getJson("/api/v1/com/elections/{$e}/minutes", $h)->assertStatus(409);

    // El voto no guarda quién votó (criterio 2).
    $cols = array_column(DB::select("select column_name from information_schema.columns where table_name = 'election_ballots'"), 'column_name');
    expect($cols)->not->toContain('worker_id')->not->toContain('user_id');

    // Escrutinio: empate 1-1 en el límite de titular; gana Bruno por mayor antigüedad (criterio 6).
    $closed = $this->postJson("/api/v1/com/elections/{$e}/close", [], $h)->assertOk()->assertJsonPath('data.step', 3);
    $res = $closed->json('data.results');
    expect($res['total'])->toBe(3)->and($res['blank'])->toBe(1)->and($res['valid'])->toBe(2)
        ->and($res['ranking'][0]['worker_id'])->toBe($b)->and($res['ranking'][0]['result'])->toBe('elected_titular')
        ->and($res['ranking'][1]['worker_id'])->toBe($c)->and($res['ranking'][1]['result'])->toBe('elected_alternate')
        ->and($res['ranking'][2]['result'])->toBe('not_elected')
        ->and($res['ties'][0]['note'])->toContain('mayor antigüedad');
    comLink($a);
    $this->postJson("/api/v1/com/elections/{$e}/vote", ['candidate_id' => $cands[$b]['id']], $h)->assertStatus(409);   // ya cerrada

    // Acta: resultados publicados y elegidos en el comité.
    $pub = $this->postJson("/api/v1/com/elections/{$e}/publish", [], $h)->assertOk()->assertJsonPath('data.step', 4);
    $committee = $pub->json('data.committee_id');
    expect($committee)->not->toBeNull();
    DB::select("select set_config('app.company_id', ?, false)", [AGRO_ID]);
    $members = DB::select('select worker_id, member_type, representation from committee_members where committee_id = ? and election_id = ? order by member_type desc', [$committee, $e]);
    expect(count($members))->toBe(2)->and($members[0]->worker_id)->toBe($b)->and($members[0]->member_type)->toBe('titular')->and($members[1]->member_type)->toBe('alternate');
    $this->getJson("/api/v1/com/elections/{$e}/minutes", $h)->assertOk()->assertJsonPath('data.election.results.ranking.0.name', 'Bruno Prueba')
        ->assertJsonPath('data.company.ruc', fn ($v) => strlen($v) === 11);
});

it('deja postular al trabajador vinculado y subsanar una observación', function () {
    $h = comH();
    $a = comWorker($h, '72000001', 'Rosa', '2016-01-01');
    $e = $this->postJson('/api/v1/com/elections', ['title' => 'Elección supervisor', 'seats_titular' => 1], $h)->assertCreated()->json('data.id');
    comLink($a);
    $this->postJson("/api/v1/com/elections/{$e}/apply", [], $h)->assertStatus(409);   // inscripción aún no abierta
    $this->postJson("/api/v1/com/elections/{$e}/open-nominations", [], $h)->assertOk();
    $this->postJson("/api/v1/com/elections/{$e}/apply", ['list_name' => 'Lista 1'], $h)->assertCreated()->assertJsonPath('data.me.candidacy.status', 'nominated');
    $cid = collect($this->getJson("/api/v1/com/elections/{$e}", $h)->json('data.candidates'))->firstWhere('worker_id', $a)['id'];
    $this->postJson("/api/v1/com/elections/{$e}/candidates/{$cid}/review", ['decision' => 'reject', 'reason' => 'Falta constancia'], $h)->assertOk();
    $this->postJson("/api/v1/com/elections/{$e}/apply", [], $h)->assertCreated()->assertJsonPath('data.me.candidacy.status', 'nominated');
    $this->postJson("/api/v1/com/elections/{$e}/cancel", [], $h)->assertStatus(422);
    $this->postJson("/api/v1/com/elections/{$e}/cancel", ['reason' => 'Se reprograma'], $h)->assertOk()->assertJsonPath('data.status', 'cancelled');
});

it('muestra el comité vigente, envía la convocatoria a sus integrantes y arma el acta con firmas', function () {
    Mail::fake();
    $h = comH();
    $a = comWorker($h, '73000001', 'Luis', '2012-01-01');
    $b = comWorker($h, '73000002', 'Marta', '2013-01-01');
    comLink($a);
    $cm = $this->postJson('/api/v1/records/COM/committees', ['committee_type' => 'committee', 'name' => 'Comité SST 2026–2028',
        'period_start_on' => date('Y-m-d'), 'period_end_on' => date('Y-m-d', strtotime('+2 years'))], $h)->assertCreated()->json('data.id');
    $m1 = $this->postJson('/api/v1/records/COM/committee_members', ['committee_id' => $cm, 'worker_id' => $a, 'representation' => 'employer', 'role_in_committee' => 'president',
        'starts_on' => date('Y-m-d')], $h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/COM/committee_members', ['committee_id' => $cm, 'worker_id' => $b, 'representation' => 'workers', 'starts_on' => date('Y-m-d')], $h)->assertCreated();
    $meet = $this->postJson('/api/v1/records/COM/committee_meetings', ['committee_id' => $cm, 'meeting_type' => 'ordinary', 'scheduled_at' => now()->addDays(3)->toIso8601String(),
        'location' => 'Sala de directorio', 'agenda' => 'Revisión de accidentes'], $h)->assertCreated()->json('data.id');

    $cur = $this->getJson('/api/v1/com/committee', $h)->assertOk()->assertJsonPath('data.committee.id', $cm)->assertJsonPath('data.next_meeting.id', $meet)
        ->assertJsonPath('data.counts.employer', 1)->assertJsonPath('data.counts.workers', 1);
    expect($cur->json('data.committee.term_tone'))->toBe('ok')->and($cur->json('data.next_meeting.notified'))->toBeFalse();

    $this->postJson("/api/v1/com/meetings/{$meet}/notify", [], $h)->assertOk()->assertJsonPath('data.notified', 1)->assertJsonPath('data.without_user', 1);
    Mail::assertSent(CommitteeNoticeMail::class, 1);
    $this->getJson('/api/v1/com/committee', $h)->assertJsonPath('data.next_meeting.notified', true);

    // Reunión realizada con asistencia y acuerdo; acta con firmas pedidas.
    // Quórum: con 2 titulares hacen falta 2 (mitad más uno); con 1 presente no hay quórum (RN-COM-08).
    $this->postJson("/api/v1/com/meetings/{$meet}/attendance", ['member_ids' => [$m1]], $h)->assertOk()->assertJsonPath('data.quorum_reached', false)->assertJsonPath('data.needed', 2);
    $this->postJson('/api/v1/records/COM/meeting_agreements', ['meeting_id' => $meet, 'description' => 'Sin quórum'], $h)->assertStatus(422);
    $this->postJson("/api/v1/com/meetings/{$meet}/attendance", ['member_ids' => ['01900000-0000-7000-8000-000000000999']], $h)->assertStatus(422);
    $all = array_column($this->getJson('/api/v1/com/committee', $h)->json('data.members'), 'id');
    $this->postJson("/api/v1/com/meetings/{$meet}/attendance", ['member_ids' => [$m1]], $h)->assertOk();
    DB::select("select set_config('app.company_id', ?, false)", [AGRO_ID]);
    DB::update('update committee_meetings set quorum_reached = true where id = ?', [$meet]);   // (el quórum completo se prueba abajo)
    $this->patchJson("/api/v1/records/COM/committee_meetings/{$meet}", ['minutes_html' => '<p>Se revisaron los accidentes.</p><p>Se acordó instalar guardas.</p>'], $h)->assertOk();
    expect($all)->toHaveCount(2);
    $this->postJson('/api/v1/records/COM/meeting_agreements', ['meeting_id' => $meet, 'description' => 'Instalar guarda fija en prensa 3', 'responsible_worker_id' => $b,
        'due_on' => date('Y-m-d', strtotime('+15 days'))], $h)->assertCreated();
    $this->postJson("/api/v1/com/meetings/{$meet}/notify", [], $h)->assertStatus(409);
    $this->postJson("/api/v1/com/meetings/{$meet}/request-signatures", [], $h)->assertCreated()->assertJsonPath('data.requested', 1);   // solo el asistente
    $this->postJson("/api/v1/com/meetings/{$meet}/request-signatures", [], $h)->assertCreated()->assertJsonPath('data.requested', 0);
    $min = $this->getJson("/api/v1/com/meetings/{$meet}/minutes", $h)->assertOk()
        ->assertJsonPath('data.meeting.minutes_text', fn ($t) => str_contains($t, 'Se acordó instalar guardas.') && ! str_contains($t, '<p>'))
        ->assertJsonCount(1, 'data.agreements')->assertJsonCount(1, 'data.pending_signatures');
    expect(collect($min->json('data.members'))->firstWhere('id', $m1)['present'])->toBeTrue();
    $this->getJson('/api/v1/com/committee', $h)->assertJsonCount(1, 'data.agreements');
});
