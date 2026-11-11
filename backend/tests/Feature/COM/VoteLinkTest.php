<?php

// Voto por enlace para trabajadores sin usuario de la app (M-COM RN-COM-03): credencial de un solo uso, voto secreto
// (el voto no guarda al elector ni el token), lista imprimible de credenciales y tarjeta «Elección del comité» del inicio.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function vlH(): array
{
    return authHeaders(tokenFor(ESENCIAL_EMAIL), AGRO_ID);
}

function vlWorker(array $h, string $doc, string $name): string
{
    $id = test()->postJson('/api/v1/records/TRB/workers', ['document_number' => $doc, 'first_name' => $name, 'paternal_last_name' => 'Enlace'], $h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [AGRO_ID]);
    DB::update("update workers set hire_on = '2015-01-01', birth_on = '1990-01-01' where id = ?", [$id]);

    return $id;
}

/** Elección en inscripción con los candidatos indicados ya habilitados. */
function vlElection(array $h, array $candidates): string
{
    $e = test()->postJson('/api/v1/com/elections', ['title' => 'Elección por enlace', 'seats_titular' => 1, 'seats_alternate' => 0], $h)->assertCreated()->json('data.id');
    test()->postJson("/api/v1/com/elections/{$e}/open-nominations", [], $h)->assertOk();
    foreach ($candidates as $w) {
        test()->postJson("/api/v1/com/elections/{$e}/candidates", ['worker_id' => $w], $h)->assertCreated();
    }
    foreach (test()->getJson("/api/v1/com/elections/{$e}", $h)->json('data.candidates') as $c) {
        test()->postJson("/api/v1/com/elections/{$e}/candidates/{$c['id']}/review", ['decision' => 'accept'], $h)->assertOk();
    }

    return $e;
}

function vlReset(): void
{
    DB::select("select set_config('app.company_id', ?, false)", [AGRO_ID]);
    DB::update("update elections set deleted_at = now() where deleted_at is null and status not in ('results_published', 'cancelled', 'annulled')");
}

it('emite credenciales a quienes no tienen usuario y vota una sola vez por enlace sin ligar el voto al elector', function () {
    $h = vlH();
    vlReset();
    $a = vlWorker($h, '72000001', 'Alicia');
    $b = vlWorker($h, '72000002', 'Benito');
    $c = vlWorker($h, '72000003', 'Celia');
    $e = vlElection($h, [$a, $b]);

    // Antes de abrir la votación ya se pueden emitir; la lista se puede imprimir varias veces.
    $this->postJson("/api/v1/com/elections/{$e}/credentials", [], $h)->assertCreated();
    $list = $this->getJson("/api/v1/com/elections/{$e}/credentials", $h)->assertOk();
    $row = collect($list->json('data'))->firstWhere('worker_id', $c);
    expect($row['token'])->toMatch('/^[A-Z2-9]{24}$/')->and($row['path'])->toBe('/votar/'.AGRO_ID.'/'.$row['token'])
        ->and($list->json('meta.election.title'))->toBe('Elección por enlace');
    $token = $row['token'];
    // Se guarda solo el hash y el token cifrado.
    $stored = DB::selectOne("select encode(vote_token_hash, 'hex') as h, vote_token_enc from election_roll where id = ?", [$row['roll_id']]);
    expect($stored->h)->toBe(hash('sha256', $token))->and($stored->vote_token_enc)->not->toContain($token);

    // Votación aún no abierta: el enlace muestra la elección sin candidatos.
    $this->getJson('/api/v1/public/com/vote/'.AGRO_ID."/{$token}")->assertOk()->assertJsonPath('data.open', false)->assertJsonPath('data.voter.first_name', 'Celia')
        ->assertJsonCount(0, 'data.candidates');
    $this->postJson('/api/v1/public/com/vote/'.AGRO_ID."/{$token}", ['blank' => true])->assertStatus(409);

    $this->postJson("/api/v1/com/elections/{$e}/open-voting", ['voting_closes_at' => now()->addDay()->toIso8601String()], $h)->assertOk();
    $show = $this->getJson('/api/v1/public/com/vote/'.AGRO_ID."/{$token}")->assertOk()->assertJsonPath('data.open', true)->assertJsonPath('data.has_voted', false);
    expect($show->json('data.candidates'))->toHaveCount(2);
    $cand = $show->json('data.candidates.0.id');

    $this->postJson('/api/v1/public/com/vote/'.AGRO_ID."/{$token}", ['candidate_id' => 'x'])->assertStatus(422);
    $r = $this->postJson('/api/v1/public/com/vote/'.AGRO_ID."/{$token}", ['candidate_id' => $cand])->assertCreated();
    expect($r->json('data.receipt'))->toMatch('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/');

    // Token usado: se rechaza (criterio 1); el voto no guarda el token ni al elector (criterio 2).
    $this->postJson('/api/v1/public/com/vote/'.AGRO_ID."/{$token}", ['candidate_id' => $cand])->assertStatus(409)->assertJsonPath('type', 'already_voted');
    $ballots = DB::select("select encode(voter_token_hash, 'hex') as h, channel from election_ballots where election_id = ?", [$e]);
    expect($ballots)->toHaveCount(1)->and($ballots[0]->h)->not->toBe(hash('sha256', $token))->and($ballots[0]->channel)->toBe('web');
    expect(DB::selectOne('select has_voted from election_roll where id = ?', [$row['roll_id']])->has_voted)->toBeTrue();
    expect(collect($this->getJson("/api/v1/com/elections/{$e}/credentials", $h)->json('data'))->firstWhere('worker_id', $c)['token'])->toBeNull();

    // Reemitir anula el enlace anterior.
    $rowB = collect($this->getJson("/api/v1/com/elections/{$e}/credentials", $h)->json('data'))->firstWhere('worker_id', $b);
    $this->postJson("/api/v1/com/elections/{$e}/credentials", ['roll_ids' => [$rowB['roll_id']]], $h)->assertCreated()->assertJsonPath('data.issued', 1);
    $this->getJson('/api/v1/public/com/vote/'.AGRO_ID."/{$rowB['token']}")->assertNotFound();

    // Enlaces inválidos u otra empresa.
    $this->getJson('/api/v1/public/com/vote/'.AGRO_ID.'/ABC')->assertNotFound();
    $this->getJson('/api/v1/public/com/vote/'.ANDINAS_ID."/{$token}")->assertNotFound();
});

it('la tarjeta del inicio muestra la elección con votación abierta y si ya votó', function () {
    $h = vlH();
    vlReset();
    $this->getJson('/api/v1/com/my-election', $h)->assertOk()->assertJsonPath('data', null);
    $a = vlWorker($h, '72000011', 'Dora');
    $e = vlElection($h, [$a]);
    $this->getJson('/api/v1/com/my-election', $h)->assertOk()->assertJsonPath('data', null);   // aún sin votación
    $this->postJson("/api/v1/com/elections/{$e}/open-voting", ['voting_closes_at' => now()->addDay()->toIso8601String()], $h)->assertOk();

    DB::select("select set_config('app.company_id', ?, false)", [AGRO_ID]);
    $uid = DB::selectOne('select id from app_auth_lookup_user(?)', [ESENCIAL_EMAIL])->id;
    DB::update('update workers set user_id = null where user_id = ?', [$uid]);
    DB::update('update workers set user_id = ? where id = ?', [$uid, $a]);
    $this->getJson('/api/v1/com/my-election', $h)->assertOk()->assertJsonPath('data.id', $e)->assertJsonPath('data.on_roll', true)->assertJsonPath('data.has_voted', false);
    $cand = $this->getJson("/api/v1/com/elections/{$e}/ballot", $h)->json('data.candidates.0.id');
    $this->postJson("/api/v1/com/elections/{$e}/vote", ['candidate_id' => $cand], $h)->assertCreated();
    $this->getJson('/api/v1/com/my-election', $h)->assertOk()->assertJsonPath('data.has_voted', true);
});
