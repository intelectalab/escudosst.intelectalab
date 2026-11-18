<?php

// M-PSI SCR-PSI-02: cálculo REBA (tablas A, B y C) y registro de la evaluación; un nivel alto crea la acción correctiva (criterio 3).

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'PSI' and deleted_at is null", [HUAYLAS_ID]);
    DB::select("select set_config('app.company_id', '', false)");
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

it('calcula el puntaje REBA según sus tablas y guarda la evaluación con acción si el nivel es alto', function () {
    $base = ['trunk' => 1, 'neck' => 1, 'legs' => 1, 'load' => 0, 'upper_arm' => 1, 'lower_arm' => 1, 'wrist' => 1, 'coupling' => 0, 'activity' => 0];
    $low = $this->postJson('/api/v1/psi/reba', $base, $this->h)->assertOk()->json('data');
    expect($low['score'])->toBe(1)->and($low['risk_level'])->toBe('low');

    // Tabla A [cuello 2][tronco 4][piernas 2] = 6 (+2 carga = 8); tabla B [antebrazo 2][brazo 4][muñeca 2] = 6 (+1 agarre = 7); C[8][7] = 10; +1 actividad = 11.
    $hi = ['trunk' => 4, 'neck' => 2, 'legs' => 2, 'load' => 2, 'upper_arm' => 4, 'lower_arm' => 2, 'wrist' => 2, 'coupling' => 1, 'activity' => 1];
    $r = $this->postJson('/api/v1/psi/reba', $hi, $this->h)->assertOk()->json('data');
    expect($r['table_a'])->toBe(6)->and($r['score_a'])->toBe(8)->and($r['table_b'])->toBe(6)->and($r['score_b'])->toBe(7)
        ->and($r['table_c'])->toBe(10)->and($r['score'])->toBe(11)->and($r['risk_level'])->toBe('very_high');

    $this->postJson('/api/v1/psi/reba', $hi + ['save' => true], $this->h)->assertStatus(422);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $pos = DB::selectOne("insert into positions (company_id, code, name) values (?, ?, 'Estibador REBA') returning id", [HUAYLAS_ID, 'EST-'.random_int(1000, 999999)])->id;
    $crit = DB::selectOne('select id from risk_criteria where company_id = ? or company_id is null order by company_id nulls last limit 1', [HUAYLAS_ID])->id;
    $mx = DB::selectOne("insert into iperc_matrices (company_id, code, title, risk_criteria_id, position_id, status) values (?, ?, 'IPERC estibador', ?, ?, 'active') returning id",
        [HUAYLAS_ID, 'IPC-PSI-'.random_int(1000, 99999), $crit, $pos])->id;
    DB::select("select set_config('app.company_id', '', false)");
    $id = $this->postJson('/api/v1/psi/reba', $hi + ['save' => true, 'position_id' => $pos, 'assessed_on' => '2031-04-01'], $this->h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $e = DB::selectOne('select method, score, risk_level, corrective_action_id from ergonomic_assessments where id = ?', [$id]);
    DB::select("select set_config('app.company_id', '', false)");
    expect($e->method)->toBe('REBA')->and((float) $e->score)->toEqual(11)->and($e->risk_level)->toBe('very_high')->and($e->corrective_action_id)->not->toBeNull();
    // RN-PSI-03: el IPERC del puesto queda «por revisar».
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne('select status from iperc_matrices where id = ?', [$mx])->status)->toBe('review_required');
});
