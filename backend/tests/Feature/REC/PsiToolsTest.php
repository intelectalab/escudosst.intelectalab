<?php

// M-PSI: RULA y lista de la R.M. 375 (SCR-PSI-02), informe ergonómico por puesto (§11), cuestionario propio con versiones y baremo
// (RN-PSI-04) y respuesta desde la app del trabajador sin guardar quién respondió (RN-PSI-01).

use Illuminate\Support\Facades\DB;

function psiAs(callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
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
    psiAs(fn () => DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('PSI', 'TRB') and deleted_at is null", [HUAYLAS_ID]));
    app('auth')->forgetGuards();
    \App\Core\Auth\Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

it('calcula RULA con las tablas del método y registra la lista de la R.M. 375; el informe muestra la última evaluación por puesto', function () {
    $base = ['upper_arm' => 1, 'lower_arm' => 1, 'wrist' => 1, 'wrist_twist' => 1, 'arm_muscle' => 0, 'arm_force' => 0, 'neck' => 1, 'trunk' => 1, 'legs' => 1, 'body_muscle' => 0, 'body_force' => 0];
    $this->postJson('/api/v1/psi/rula', $base, $this->h)->assertOk()->assertJsonPath('data.score', 1)->assertJsonPath('data.risk_level', 'low');
    // Brazo 4, antebrazo 2, muñeca 3, giro 2 → A 5; +1 +2 = C 8. Cuello 3, tronco 4, piernas 2 → B 6; +1 +1 = D 8 → tabla C [8][7] = 7.
    $r = $this->postJson('/api/v1/psi/rula', ['upper_arm' => 4, 'lower_arm' => 2, 'wrist' => 3, 'wrist_twist' => 2, 'arm_muscle' => 1, 'arm_force' => 2,
        'neck' => 3, 'trunk' => 4, 'legs' => 2, 'body_muscle' => 1, 'body_force' => 1], $this->h)->assertOk()->json('data');
    expect($r['table_a'])->toBe(5)->and($r['score_c'])->toBe(8)->and($r['table_b'])->toBe(6)->and($r['score_d'])->toBe(8)->and($r['score'])->toBe(7)->and($r['risk_level'])->toBe('very_high');

    $pos = $this->postJson('/api/v1/records/TEN/positions', ['code' => 'ERG-T', 'name' => 'Digitador ERG'], $this->h)->json('data.id');
    $items = $this->getJson('/api/v1/psi/rm375', $this->h)->assertOk()->json('data.items');
    expect($items)->toContain('Manipulación manual de cargas');
    $answers = array_fill_keys($items, ['present' => false]);
    $this->postJson('/api/v1/psi/rm375', ['answers' => array_slice($answers, 1), 'risk_level' => 'low', 'position_id' => $pos, 'assessed_on' => '2026-11-10'], $this->h)->assertStatus(422);
    $answers['Posicionamiento postural en los puestos de trabajo'] = ['present' => true, 'notes' => 'Pantalla baja'];
    $this->postJson('/api/v1/psi/rm375', ['answers' => $answers, 'risk_level' => 'medium', 'position_id' => $pos, 'assessed_on' => '2026-11-10'], $this->h)
        ->assertCreated()->assertJsonPath('data.present', 1);
    $rep = $this->getJson('/api/v1/psi/ergonomics/report', $this->h)->assertOk()->json('data');
    $row = collect($rep['positions'])->firstWhere('position_id', $pos);
    expect($row['method'])->toBe('checklist_rm375')->and($row['risk_level'])->toBe('medium')->and($rep['indicators']['evaluated'])->toBeGreaterThan(0);
});

it('el cuestionario propio tiene versiones y baremo; la encuesta lo copia y se responde desde la app sin guardar al trabajador', function () {
    $v = $this->postJson('/api/v1/psi/instruments', [], $this->h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/psi/instruments', [], $this->h)->assertStatus(409);
    $this->putJson("/api/v1/psi/instruments/{$v}", ['title' => 'Clima laboral', 'questions' => ['dimensions' => [['name' => 'Carga', 'items' => [['text' => '¿Te sobra trabajo?'], ['text' => '¿Trabajas apurado?']]]]],
        'cutoffs' => ['medium' => 60, 'high' => 40]], $this->h)->assertStatus(422);
    $this->putJson("/api/v1/psi/instruments/{$v}", ['title' => 'Clima laboral', 'questions' => ['dimensions' => [['name' => 'Carga', 'items' => [['text' => '¿Te sobra trabajo?'], ['text' => '¿Trabajas apurado?']]]]],
        'cutoffs' => ['medium' => 40, 'high' => 70]], $this->h)->assertOk();
    $this->postJson("/api/v1/psi/instruments/{$v}/publish", [], $this->h)->assertOk();
    $this->putJson("/api/v1/psi/instruments/{$v}", ['title' => 'X', 'questions' => ['dimensions' => [['name' => 'A', 'items' => [['text' => 'B']]]]], 'cutoffs' => ['medium' => 1, 'high' => 2]], $this->h)->assertStatus(409);

    $s = $this->postJson('/api/v1/records/PSI/psychosocial_surveys', ['title' => 'Encuesta clima', 'instrument' => 'custom', 'ends_on' => now()->addDays(10)->toDateString()], $this->h)
        ->assertCreated()->assertJsonPath('data.instrument_version', 'Clima laboral v1')->json('data.id');
    $this->patchJson("/api/v1/records/PSI/psychosocial_surveys/{$s}", ['status' => 'open'], $this->h)->assertOk();

    // Trabajador con acceso a la app (activación con DNI + código).
    $wid = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '73737373', 'first_name' => 'Ana', 'paternal_last_name' => 'Encuesta'], $this->h)->assertCreated()->json('data.id');
    $code = $this->postJson("/api/v1/trb/workers/{$wid}/app-access", [], $this->h)->assertCreated()->json('data.code');
    app('auth')->forgetGuards();
    $tok = $this->postJson('/api/v1/auth/worker/activate', ['dni' => '73737373', 'code' => $code, 'pin' => '482613', 'pin_confirmation' => '482613', 'device_uid' => 'wdev-psi'])
        ->assertCreated()->json('token');
    \App\Core\Auth\Permissions::flush();
    $wh = authHeaders($tok, HUAYLAS_ID);
    expect(collect($this->getJson('/api/v1/me/worker/pending', $wh)->assertOk()->json('data'))->firstWhere('kind', 'survey')['url'])->toBe("/mi/encuesta/{$s}");
    $q = $this->getJson("/api/v1/me/worker/surveys/{$s}", $wh)->assertOk()->json('data');
    expect($q['answered'])->toBeFalse()->and($q['questions']['dimensions'][0]['items'])->toHaveCount(2)->and($q['questions'])->not->toHaveKey('cutoffs');
    $this->postJson("/api/v1/me/worker/surveys/{$s}", ['answers' => ['d1_1' => 4]], $wh)->assertStatus(422);
    $this->postJson("/api/v1/me/worker/surveys/{$s}", ['answers' => ['d1_1' => 4, 'd1_2' => 3]], $wh)->assertCreated();
    $this->postJson("/api/v1/me/worker/surveys/{$s}", ['answers' => ['d1_1' => 4, 'd1_2' => 3]], $wh)->assertStatus(409);
    expect($this->getJson("/api/v1/me/worker/surveys/{$s}", $wh)->json('data.answered'))->toBeTrue();
    $resp = psiAs(fn () => DB::selectOne('select worker_id, answers from psychosocial_responses where survey_id = ?', [$s]));
    expect($resp->worker_id)->toBeNull();
});
