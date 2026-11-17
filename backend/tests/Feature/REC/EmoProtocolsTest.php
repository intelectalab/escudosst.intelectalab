<?php

// M-EMO SCR-EMO-06: protocolos con sus exámenes y asignación al puesto con su periodicidad; estado de aptitud por puesto.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'EMO' and deleted_at is null", [HUAYLAS_ID]);
    DB::select("select set_config('app.company_id', '', false)");
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    // El usuario Esencial atiende también como médico ocupacional de Huaylas (define los protocolos, SCR-EMO-06).
    DB::select("select set_config('app.company_id', ?, false)", [AGRO_ID]);
    $uid = DB::selectOne('select id from users where email = ?', [ESENCIAL_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    if (! DB::selectOne('select 1 as ok from memberships where company_id = ? and user_id = ? and deleted_at is null', [HUAYLAS_ID, $uid])) {
        $role = DB::selectOne("select id from roles where account_id is null and code = 'occupational_doctor'")->id;
        DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, ?, 'active', now())", [HUAYLAS_ID, $uid, $role]);
    }
    DB::select("select set_config('app.company_id', '', false)");
    app('auth')->forgetGuards();
    $this->doc = authHeaders(tokenFor(ESENCIAL_EMAIL), HUAYLAS_ID);
    app('auth')->forgetGuards();
});

it('crea un protocolo, lo asigna al puesto y muestra el estado de aptitud de sus trabajadores', function () {
    $code = 'PRT-'.random_int(1000, 999999);
    $this->postJson('/api/v1/emo/protocols', ['code' => $code, 'name' => 'Trabajo en altura', 'exams' => []], $this->doc)->assertStatus(422);
    $id = $this->postJson('/api/v1/emo/protocols', ['code' => $code, 'name' => 'Trabajo en altura', 'exams' => ['Examen clínico', 'Audiometría', ' '], 'exposures' => 'Caída a distinto nivel'], $this->doc)
        ->assertCreated()->json('data.id');
    $this->postJson('/api/v1/emo/protocols', ['code' => $code, 'name' => 'Otro', 'exams' => ['X']], $this->doc)->assertStatus(422);

    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $pos = DB::selectOne("insert into positions (company_id, code, name) values (?, ?, 'Montajista EMO') returning id", [HUAYLAS_ID, 'MON-'.random_int(1000, 999999)])->id;
    DB::select("select set_config('app.company_id', '', false)");
    app('auth')->forgetGuards();
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(75000000, 75999999), 'first_name' => 'Mario', 'paternal_last_name' => 'Emo'], $this->h)
        ->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update('update workers set current_position_id = ? where id = ?', [$pos, $w]);
    DB::select("select set_config('app.company_id', '', false)");

    app('auth')->forgetGuards();
    $this->patchJson("/api/v1/emo/positions/{$pos}", ['requires_emo' => true], $this->h)->assertForbidden();
    app('auth')->forgetGuards();
    $this->patchJson("/api/v1/emo/positions/{$pos}", ['requires_emo' => true], $this->doc)->assertStatus(422);
    $this->patchJson("/api/v1/emo/positions/{$pos}", ['requires_emo' => true, 'emo_protocol_code' => $code, 'emo_periodicity_param' => 'emo_periodic_months_high_risk'], $this->doc)->assertOk();

    app('auth')->forgetGuards();
    $d = $this->getJson('/api/v1/emo/protocols', $this->doc)->assertOk()->json('data');
    expect(collect($d['protocols'])->firstWhere('id', $id)['exams'])->toBe(['Examen clínico', 'Audiometría']);
    $p = collect($d['positions'])->firstWhere('id', $pos);
    expect($p['requires_emo'])->toBeTrue()->and($p['emo_protocol_code'])->toBe($code)->and($p['workers'])->toBe(1)->and($p['missing'])->toBe(1)
        ->and($p['periodicity_months'])->toBe($d['periodicity']['emo_periodic_months_high_risk']);
});
