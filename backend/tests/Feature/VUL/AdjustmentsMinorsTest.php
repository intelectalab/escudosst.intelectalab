<?php

// M-VUL §4: el Jefe SST crea y actualiza adecuaciones; RN-VUL-03: un menor no se asigna a un puesto con peligros
// prohibidos para menores; §10: aviso de adecuación por vencer o vencida (Jefe SST y médico).
// M-DES §10: aviso de retorno de descanso por accidente para el EMO de reintegro.

use App\Core\Auth\Permissions;
use App\Modules\NOT\SstAlerts;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Permissions::flush();
    Mail::fake();
});

/** Puesto con IPERC vigente con un peligro químico (prohibido para menores) y otro puesto sin peligros. */
function vamSetup(object $t, array $h): array
{
    $pos = $t->postJson('/api/v1/records/TEN/positions', ['code' => 'VAM-P1', 'name' => 'Pintor'], $h)->assertCreated()->json('data.id');
    $safe = $t->postJson('/api/v1/records/TEN/positions', ['code' => 'VAM-P2', 'name' => 'Archivo'], $h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $criteria = DB::selectOne('select id from risk_criteria where deleted_at is null order by company_id nulls first limit 1')->id;
    $m = DB::selectOne("insert into iperc_matrices (company_id, code, title, position_id, risk_criteria_id) values (?, 'IPERC-VAM', 'Pintura', ?, ?) returning id", [HUAYLAS_ID, $pos, $criteria])->id;
    $v = DB::selectOne("insert into iperc_versions (company_id, iperc_matrix_id, version_number, status) values (?, ?, 1, 'current') returning id", [HUAYLAS_ID, $m])->id;
    DB::update('update iperc_matrices set current_version_id = ? where id = ?', [$v, $m]);
    DB::insert("insert into iperc_rows (company_id, iperc_version_id, row_number, activity, task, hazard, risk, probability_value, severity_value, risk_level)
                values (?, ?, 1, 'Pintura', 'Pintar', 'Vapores de solventes', 'Intoxicación', 3, 3, 'important')", [HUAYLAS_ID, $v]);
    DB::select("select set_config('app.company_id', '', false)");

    return [$pos, $safe];
}

it('el Jefe SST crea y actualiza una adecuación', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '61616161', 'first_name' => 'Elsa', 'paternal_last_name' => 'Adecuada'], $h)->assertCreated()->json('data.id');
    $id = $this->postJson('/api/v1/vul/adjustments', ['worker_id' => $w, 'adjustment_category' => 'schedule', 'description' => 'Turno diurno sin horas extra',
        'starts_on' => now()->toDateString()], $h)->assertCreated()->json('data.id');
    $this->putJson("/api/v1/vul/adjustments/{$id}", ['adjustment_category' => 'schedule', 'description' => 'Turno diurno sin horas extra ni noche',
        'starts_on' => now()->toDateString(), 'status' => 'active'], $h)->assertOk();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne('select status, approved_by from work_adjustments where id = ?', [$id]))->status->toBe('active');
});

it('bloquea asignar a un menor de edad a un puesto con peligros prohibidos para menores', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    [$pos, $safe] = vamSetup($this, $h);
    $minor = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '62626262', 'first_name' => 'Luis', 'paternal_last_name' => 'Joven',
        'birth_on' => now()->subYears(17)->toDateString()], $h)->assertCreated()->json('data.id');
    $adult = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '63636363', 'first_name' => 'Ana', 'paternal_last_name' => 'Adulta',
        'birth_on' => now()->subYears(30)->toDateString()], $h)->assertCreated()->json('data.id');

    $this->patchJson("/api/v1/records/TRB/workers/{$minor}", ['current_position_id' => $pos], $h)->assertStatus(422)
        ->assertJsonValidationErrors('current_position_id')->assertJsonPath('minor_conflicts.0.family', 'chemical');
    $this->patchJson("/api/v1/records/TRB/workers/{$minor}", ['current_position_id' => $safe], $h)->assertOk();
    $this->patchJson("/api/v1/records/TRB/workers/{$adult}", ['current_position_id' => $pos], $h)->assertOk();

    // Tampoco con una reubicación por adecuación.
    $this->postJson('/api/v1/vul/adjustments', ['worker_id' => $minor, 'adjustment_category' => 'position_change', 'description' => 'Reubicación a pintura',
        'new_position_id' => $pos, 'starts_on' => now()->toDateString()], $h)->assertStatus(422)->assertJsonValidationErrors('new_position_id');
    $c = $this->postJson('/api/v1/vul/compatibility', ['worker_id' => $minor, 'new_position_id' => $pos], $h)->assertOk()->json('data');
    expect($c['minor'])->toBeTrue()->and($c['minor_conflicts'])->not->toBeEmpty();

    // Sin familias prohibidas en el parámetro de la empresa, solo queda la advertencia.
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::insert("insert into legal_parameters (company_id, key, value, value_type, description, valid_from, to_confirm)
                values (?, 'minor_forbidden_hazard_families', '[]', 'json', 'Sin lista', current_date - 1, true)", [HUAYLAS_ID]);
    $this->patchJson("/api/v1/records/TRB/workers/{$minor}", ['current_position_id' => $pos], $h)->assertOk();
});

it('avisa al Jefe SST y al médico de adecuaciones por vencer o vencidas y del retorno de un descanso por accidente', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '64646464', 'first_name' => 'Rita', 'paternal_last_name' => 'Retorno'], $h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $me = DB::selectOne('select id from users where email = ?', [CORPORATIVO_EMAIL])->id;
    $doctor = DB::selectOne('select id from app_auth_lookup_user(?)', [ESENCIAL_EMAIL])->id;
    $role = DB::selectOne("select id from roles where account_id is null and code = 'occupational_doctor'")->id;
    DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, ?, 'active', now())", [HUAYLAS_ID, $doctor, $role]);
    foreach (['VUL', 'DES'] as $mod) {
        DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = ?", [HUAYLAS_ID, $mod]);
    }
    $soon = DB::selectOne("insert into work_adjustments (company_id, worker_id, adjustment_category, description, starts_on, ends_on, status)
                           values (?, ?, 'schedule', 'Turno diurno', current_date - 30, current_date + 5, 'active') returning id", [HUAYLAS_ID, $w])->id;
    $late = DB::selectOne("insert into work_adjustments (company_id, worker_id, adjustment_category, description, starts_on, ends_on, status)
                           values (?, ?, 'workload', 'Sin cargas', current_date - 60, current_date - 2, 'active') returning id", [HUAYLAS_ID, $w])->id;
    $far = DB::selectOne("insert into work_adjustments (company_id, worker_id, adjustment_category, description, starts_on, ends_on, status)
                          values (?, ?, 'other', 'Pausas', current_date, current_date + 90, 'active') returning id", [HUAYLAS_ID, $w])->id;
    $abs = DB::selectOne("insert into absences (company_id, worker_id, absence_type, starts_on, ends_on, is_work_related)
                          values (?, ?, 'occupational_accident', current_date - 10, current_date - 1, true) returning id", [HUAYLAS_ID, $w])->id;
    $other = DB::selectOne("insert into absences (company_id, worker_id, absence_type, starts_on, ends_on)
                            values (?, ?, 'permission', current_date - 40, current_date - 39) returning id", [HUAYLAS_ID, $w])->id;

    $alerts = app(SstAlerts::class);
    app(AccountsService::class)->withCompany(HUAYLAS_ID, fn ($db) => $alerts->forCompany($db, HUAYLAS_ID));
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $got = fn (string $event, string $subject) => array_column(DB::select('select recipient_user_id from notifications where event_code = ? and subject_id = ?', [$event, $subject]), 'recipient_user_id');
    expect($got('vul.adjustment_expiring', $soon))->toContain($me)->toContain($doctor)
        ->and($got('vul.adjustment_expired', $late))->toContain($me)->toContain($doctor)
        ->and($got('vul.adjustment_expiring', $far))->toBeEmpty()
        ->and($got('des.return_to_work', $abs))->toContain($me)->toContain($doctor)
        ->and($got('des.return_to_work', $other))->toBeEmpty();
    $n = DB::selectOne("select body from notifications where event_code = 'des.return_to_work' and subject_id = ? limit 1", [$abs]);
    expect($n->body)->toContain('EMO de reintegro');

    // No se repite al día siguiente de correr otra vez.
    $again = app(AccountsService::class)->withCompany(HUAYLAS_ID, fn ($db) => $alerts->forCompany($db, HUAYLAS_ID));
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(count($got('des.return_to_work', $abs)))->toBe(2);
});
