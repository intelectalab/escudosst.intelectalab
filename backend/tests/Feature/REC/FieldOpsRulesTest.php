<?php

// Reglas de observaciones de conducta, botiquines, brigadas, simulacros y revisiones de equipos según sus fichas.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $this->site = DB::selectOne('select id from sites where company_id = ? and deleted_at is null order by is_main desc limit 1', [HUAYLAS_ID])->id;
});

it('la observación calcula sus conteos, se envía con un comportamiento y enviada no se edita', function () {
    $o = $this->postJson('/api/v1/records/OBS/behavior_observations', ['observed_on' => now()->toDateString(), 'site_id' => $this->site, 'task_observed' => 'Izaje', 'safe_count' => 99], $this->h)
        ->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/OBS/behavior_observations/{$o}", ['status' => 'submitted'], $this->h)->assertStatus(422);
    foreach ([['ppe', 'Usa casco', 'safe'], ['tools', 'Eslinga revisada', 'safe'], ['body_position', 'Bajo la carga', 'at_risk']] as [$cat, $b, $r]) {
        $this->postJson('/api/v1/records/OBS/behavior_observation_items', ['observation_id' => $o, 'category' => $cat, 'behavior' => $b, 'result' => $r], $this->h)->assertCreated();
    }
    $this->getJson("/api/v1/records/OBS/behavior_observations/{$o}", $this->h)->assertJsonPath('data.safe_count', 2)->assertJsonPath('data.at_risk_count', 1);
    $this->patchJson("/api/v1/records/OBS/behavior_observations/{$o}", ['status' => 'submitted'], $this->h)->assertOk();
    $this->patchJson("/api/v1/records/OBS/behavior_observations/{$o}", ['comments' => 'Cambio'], $this->h)->assertStatus(409);
    $this->postJson('/api/v1/records/OBS/behavior_observation_items', ['observation_id' => $o, 'category' => 'other', 'behavior' => 'Otro', 'result' => 'safe'], $this->h)->assertStatus(409);
    // La revisa otra persona.
    $this->patchJson("/api/v1/records/OBS/behavior_observations/{$o}", ['status' => 'reviewed'], $this->h)->assertStatus(422);
});

it('el botiquín toma su estado de sus ítems', function () {
    $kit = $this->postJson('/api/v1/records/EQE/first_aid_kits', ['code' => 'BOT-'.substr(uniqid(), -5), 'site_id' => $this->site], $this->h)->assertCreated()->json('data.id');
    $i = $this->postJson('/api/v1/records/EQE/first_aid_kit_items', ['kit_id' => $kit, 'item_name' => 'Gasas', 'required_quantity' => 10, 'current_quantity' => 4], $this->h)
        ->assertCreated()->json('data.id');
    expect(DB::selectOne('select status from first_aid_kits where id = ?', [$kit])->status)->toBe('incomplete');
    $this->patchJson("/api/v1/records/EQE/first_aid_kit_items/{$i}", ['current_quantity' => 10], $this->h)->assertOk();
    expect(DB::selectOne('select status from first_aid_kits where id = ?', [$kit])->status)->toBe('complete');
    $this->postJson('/api/v1/records/EQE/first_aid_kit_items', ['kit_id' => $kit, 'item_name' => 'Alcohol', 'required_quantity' => 1, 'current_quantity' => 1,
        'expires_on' => now()->subDay()->toDateString()], $this->h)->assertCreated();
    expect(DB::selectOne('select status from first_aid_kits where id = ?', [$kit])->status)->toBe('expired_items');
});

it('la brigada: un solo líder, sin cesados, mínimo de miembros y el cese la deja', function () {
    $b = $this->postJson('/api/v1/records/SIM/brigades', ['site_id' => $this->site, 'brigade_type' => 'evacuation', 'name' => 'Brigada de evacuación', 'status' => 'inactive'], $this->h)
        ->assertCreated()->json('data.id');
    $w = fn ($dni, $n) => $this->postJson('/api/v1/records/TRB/workers', ['document_number' => $dni, 'first_name' => $n, 'paternal_last_name' => 'Brigada'], $this->h)->json('data.id');
    $lead = $w('63636301', 'Lider');
    $this->postJson('/api/v1/records/SIM/brigade_members', ['brigade_id' => $b, 'worker_id' => $lead, 'role' => 'leader'], $this->h)->assertCreated();
    $this->patchJson("/api/v1/records/SIM/brigades/{$b}", ['status' => 'active'], $this->h)->assertStatus(422)->assertJsonPath('errors.status.0', fn ($m) => str_contains($m, 'al menos'));
    $other = $w('63636302', 'Otro');
    $this->postJson('/api/v1/records/SIM/brigade_members', ['brigade_id' => $b, 'worker_id' => $other, 'role' => 'leader'], $this->h)->assertStatus(422)->assertJsonValidationErrors('role');
    $this->postJson('/api/v1/records/SIM/brigade_members', ['brigade_id' => $b, 'worker_id' => $other, 'role' => 'member'], $this->h)->assertCreated();
    $this->patchJson("/api/v1/records/SIM/brigades/{$b}", ['status' => 'active'], $this->h)->assertOk();
    expect(DB::selectOne('select leader_worker_id from brigades where id = ?', [$b])->leader_worker_id)->toBe($lead);

    $this->patchJson("/api/v1/records/TRB/workers/{$lead}", ['status' => 'terminated', 'termination_on' => now()->toDateString(), 'termination_reason' => 'renuncia'], $this->h);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    if (DB::selectOne('select status from workers where id = ?', [$lead])->status === 'terminated') {
        expect(DB::selectOne('select status from brigade_members where worker_id = ?', [$lead])->status)->toBe('inactive')
            ->and(DB::selectOne('select leader_worker_id from brigades where id = ?', [$b])->leader_worker_id)->toBeNull();
    }
});

it('el simulacro se evalúa con su informe, ya evaluado no cambia y da origen a una acción', function () {
    $d = $this->postJson('/api/v1/records/SIM/drills', ['title' => 'Simulacro de sismo', 'drill_type' => 'earthquake', 'site_id' => $this->site], $this->h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/SIM/drills/{$d}", ['status' => 'executed'], $this->h)->assertOk();
    $this->patchJson("/api/v1/records/SIM/drills/{$d}", ['status' => 'evaluated', 'result' => 'needs_improvement'], $this->h)->assertStatus(422)->assertJsonValidationErrors('report_file_id');
    $report = $this->post('/api/v1/files', ['file' => \Illuminate\Http\UploadedFile::fake()->create('informe.pdf', 20, 'application/pdf'), 'module' => 'SIM'], $this->h + ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/SIM/drills/{$d}", ['status' => 'evaluated', 'result' => 'needs_improvement', 'report_file_id' => $report], $this->h)->assertOk();
    $this->patchJson("/api/v1/records/SIM/drills/{$d}", ['title' => 'Otro'], $this->h)->assertStatus(409);
    $me = DB::connection('platform')->selectOne('select id from users where email = ?', [CORPORATIVO_EMAIL])->id;
    $this->postJson("/api/v1/acr/from/drills/{$d}", ['title' => 'Despejar la salida B', 'responsible_user_id' => $me, 'due_on' => now()->addDays(7)->toDateString()], $this->h)->assertCreated();
    $this->postJson("/api/v1/acr/from/drills/{$d}", ['title' => 'Otra', 'responsible_user_id' => $me, 'due_on' => now()->addDays(7)->toDateString()], $this->h)->assertStatus(409);
});
