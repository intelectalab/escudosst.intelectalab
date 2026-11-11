<?php

// Grupos vulnerables (M-VUL SCR-VUL-01/02/03): la condición vive en la base médica y solo la ve el médico con clave
// médica; los demás roles ven «Reservado». La adecuación se cruza con los peligros del IPERC del puesto (RN-VUL-02).

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::connection('medical')->beginTransaction();
    // Esencial atiende como médico ocupacional y Profesional como RR. HH. de Huaylas (solo durante la prueba).
    foreach ([[ESENCIAL_EMAIL, 'occupational_doctor'], [PROFESIONAL_EMAIL, 'hr']] as [$email, $code]) {
        $uid = DB::selectOne('select id from app_auth_lookup_user(?)', [$email])->id;
        DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
        $role = DB::selectOne('select id from roles where account_id is null and code = ?', [$code])->id;
        DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, ?, 'active', now())", [HUAYLAS_ID, $uid, $role]);
    }
    DB::select("select set_config('app.company_id', '', false)");
});

afterEach(function () {
    DB::connection('medical')->rollBack();
});

function vulAs(string $email): array
{
    app('auth')->forgetGuards();
    Permissions::flush();

    return authHeaders(tokenFor($email), HUAYLAS_ID);
}

/** Puesto con una matriz IPERC vigente que tiene un peligro químico y otro mecánico; otro puesto sin peligros. */
function vulSetup(): array
{
    $sst = vulAs(CORPORATIVO_EMAIL);
    $pos = test()->postJson('/api/v1/records/TEN/positions', ['code' => 'VUL-P1', 'name' => 'Barnizador'], $sst)->assertCreated()->json('data.id');
    $safe = test()->postJson('/api/v1/records/TEN/positions', ['code' => 'VUL-P2', 'name' => 'Control de calidad'], $sst)->assertCreated()->json('data.id');
    $w = test()->postJson('/api/v1/records/TRB/workers', ['document_number' => '65656565', 'first_name' => 'Mariela', 'paternal_last_name' => 'Rivas'], $sst)
        ->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update('update workers set current_position_id = ? where id = ?', [$pos, $w]);
    $criteria = DB::selectOne('select id from risk_criteria where deleted_at is null order by company_id nulls first limit 1')->id;
    $m = DB::selectOne("insert into iperc_matrices (company_id, code, title, position_id, risk_criteria_id) values (?, 'IPERC-VUL', 'Barnizado', ?, ?) returning id", [HUAYLAS_ID, $pos, $criteria])->id;
    $v = DB::selectOne("insert into iperc_versions (company_id, iperc_matrix_id, version_number, status) values (?, ?, 1, 'current') returning id", [HUAYLAS_ID, $m])->id;
    DB::update('update iperc_matrices set current_version_id = ? where id = ?', [$v, $m]);
    DB::insert("insert into iperc_rows (company_id, iperc_version_id, row_number, activity, task, hazard, risk, probability_value, severity_value, risk_level)
                values (?, ?, 1, 'Barnizado', 'Aplicar barniz', 'Vapores de solventes orgánicos', 'Intoxicación', 3, 3, 'important'),
                       (?, ?, 2, 'Barnizado', 'Lijado', 'Partes móviles de la lijadora', 'Atrapamiento', 2, 2, 'moderate')", [HUAYLAS_ID, $v, HUAYLAS_ID, $v]);
    DB::select("select set_config('app.company_id', '', false)");

    return [$w, $pos, $safe];
}

it('el médico registra la condición en la base médica, propone la adecuación y los demás ven «Reservado»', function () {
    [$w] = vulSetup();

    $doc = vulAs(ESENCIAL_EMAIL);
    $this->getJson('/api/v1/vul/records', $doc)->assertStatus(403)->assertJsonPath('type', 'reauth_required');
    $this->postJson('/api/v1/vul/reauth', ['password' => 'otra'], $doc)->assertStatus(422);
    $r = $this->postJson('/api/v1/vul/reauth', ['password' => DEMO_PASSWORD], $doc)->assertOk()->json('data.reauth_id');
    $m = $doc + ['X-Medical-Reauth' => $r];

    $this->postJson('/api/v1/vul/records', ['worker_id' => $w, 'vulnerability_type' => 'nope', 'starts_on' => now()->toDateString()], $m)->assertStatus(422);
    $rec = $this->postJson('/api/v1/vul/records', ['worker_id' => $w, 'vulnerability_type' => 'pregnancy', 'starts_on' => now()->toDateString(),
        'expected_end_on' => now()->addMonths(6)->toDateString(), 'details' => 'Gestación de 14 semanas', 'consent_on' => now()->toDateString(),
        'recommended_measures' => 'Sin exposición a solventes ni cargas mayores a 10 kg'], $m)->assertCreated();
    $adj = $rec->json('data.work_adjustment_id');
    expect($adj)->not->toBeNull();

    $list = $this->getJson('/api/v1/vul/records', $m)->assertOk();
    expect($list->json('data.0.type_label'))->toBe('Gestante')->and($list->json('data.0.details'))->toBe('Gestación de 14 semanas')
        ->and($list->json('data.0.consent_on'))->toBe(now()->toDateString());

    // La condición está cifrada en la base médica; la adecuación de la base principal no la menciona.
    $med = DB::connection('medical');
    $med->select("select set_config('app.company_id', ?, false), set_config('app.reauth_session_id', ?, false)", [HUAYLAS_ID, $r]);
    expect($med->selectOne('select details from vulnerability_records where id = ?', [$rec->json('data.id')])->details)->not->toContain('14 semanas');
    expect($med->table('medical_access_logs')->where('reauth_session_id', $r)->count())->toBeGreaterThanOrEqual(2);
    $med->select("select set_config('app.company_id', '', false), set_config('app.reauth_session_id', '', false)");

    // Con la clave, el médico ve la condición real en la lista; sin ella, «Reservado».
    $row = collect($this->getJson('/api/v1/vul/adjustments', $m)->assertOk()->json('data'))->firstWhere('id', $adj);
    expect($row['condition']['label'])->toBe('Gestante')->and($row['condition_reserved'])->toBeFalse();
    $row = collect($this->getJson('/api/v1/vul/adjustments', $doc)->json('data'))->firstWhere('id', $adj);
    expect($row['condition'])->toBeNull()->and($row['condition_reserved'])->toBeTrue();

    // RR. HH. y el Jefe SST ven la adecuación sin condición, ni con la clave del médico.
    foreach ([PROFESIONAL_EMAIL, CORPORATIVO_EMAIL] as $email) {
        $h = vulAs($email);
        $res = $this->getJson('/api/v1/vul/adjustments', $h + ['X-Medical-Reauth' => $r])->assertOk();
        $row = collect($res->json('data'))->firstWhere('id', $adj);
        expect($row['condition'])->toBeNull()->and($row['condition_reserved'])->toBeTrue()->and($row['description'])->toContain('solventes')
            ->and($res->json('meta.condition_visible'))->toBeFalse();
        $this->getJson('/api/v1/vul/records', $h + ['X-Medical-Reauth' => $r])->assertStatus(403);
        $this->postJson('/api/v1/vul/reauth', ['password' => DEMO_PASSWORD], $h)->assertStatus(403);
    }
});

it('alerta la adecuación incompatible con el IPERC del puesto y exige reubicación o justificación', function () {
    [$w, , $safe] = vulSetup();
    $doc = vulAs(ESENCIAL_EMAIL);

    $c = $this->postJson('/api/v1/vul/compatibility', ['worker_id' => $w, 'families' => ['chemical']], $doc)->assertOk();
    expect($c->json('data.compatible'))->toBeFalse()->and($c->json('data.conflicts'))->toHaveCount(1)
        ->and($c->json('data.conflicts.0.hazard'))->toContain('solventes')->and($c->json('data.position.name'))->toBe('Barnizador');
    expect($this->postJson('/api/v1/vul/compatibility', ['worker_id' => $w, 'families' => ['biological']], $doc)->json('data.compatible'))->toBeTrue();

    $body = ['worker_id' => $w, 'adjustment_category' => 'environment', 'description' => 'Sin exposición a solventes', 'restricted_hazard_families' => ['chemical'],
        'starts_on' => now()->toDateString(), 'status' => 'active'];
    $this->postJson('/api/v1/vul/adjustments', $body, $doc)->assertStatus(422)->assertJsonValidationErrors('compatibility_justification');
    // Con justificación se guarda y se avisa al Jefe SST.
    $id = $this->postJson('/api/v1/vul/adjustments', $body + ['compatibility_justification' => 'Trabaja en cabina con extracción y no aplica barniz'], $doc)->assertCreated()->json('data.id');
    // Reubicada a un puesto sin esos peligros: compatible sin justificación.
    $this->putJson("/api/v1/vul/adjustments/{$id}", array_merge($body, ['adjustment_category' => 'position_change', 'new_position_id' => $safe]), $doc)->assertOk()
        ->assertJsonPath('data.compatibility.compatible', true);
    $row = collect($this->getJson('/api/v1/vul/adjustments', $doc)->json('data'))->firstWhere('id', $id);
    expect($row['compatible'])->toBeTrue()->and($row['new_position'])->toBe('Control de calidad')->and($row['restricted_hazard_families'])->toBe(['chemical']);

    $sstId = DB::selectOne('select id from app_auth_lookup_user(?)', [CORPORATIVO_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne("select count(*) as n from notifications where recipient_user_id = ? and event_code = 'vul.incompatible' and subject_id = ?", [$sstId, $id])->n)->toBe(1);
    DB::select("select set_config('app.company_id', '', false)");

    // RR. HH. solo consulta.
    $this->postJson('/api/v1/vul/adjustments', $body, vulAs(PROFESIONAL_EMAIL))->assertStatus(403);
});
