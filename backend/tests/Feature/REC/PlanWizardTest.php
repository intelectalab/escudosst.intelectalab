<?php

// M-PLA RN-PLA-01/02/03/04/05: asistente con vínculos, requisitos para enviar el plan, quórum del comité, bloqueo y nuevas versiones.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('PLA', 'POL', 'LBA', 'PAS', 'PCA') and deleted_at is null", [HUAYLAS_ID]);
    DB::select("select set_config('app.company_id', '', false)");
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

function plaAs(callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    try {
        return $fn();
    } finally {
        DB::select("select set_config('app.company_id', '', false)");
    }
}

it('arma el plan con el asistente, exige sus vínculos para enviarlo y crea nuevas versiones', function () {
    $year = 2040 + random_int(0, 50);
    [$base, $pol, $obj, $pas, $meetNoQ, $meetQ] = plaAs(function () use ($year) {
        $base = DB::selectOne("insert into baseline_assessments (company_id, title, assessment_on, status) values (?, 'LB plan', current_date, 'approved') returning id", [HUAYLAS_ID])->id;
        $pol = DB::selectOne("select id from sst_policies where status in ('current', 'review_required') and deleted_at is null limit 1")?->id
            ?? DB::selectOne("insert into sst_policies (company_id, title, version_number, content_html, status, effective_on) values (?, 'Política plan', 900, '<p>x</p>', 'current', current_date) returning id", [HUAYLAS_ID])->id;
        $obj = DB::selectOne("insert into sst_objectives (company_id, policy_id, year, code, title, indicator, target_value, unit, status) values (?, ?, ?, 'OBJ-1', 'Reducir accidentes', 'IF', 10, '%', 'active') returning id", [HUAYLAS_ID, $pol, $year])->id;
        $pas = DB::selectOne("insert into annual_programs (company_id, title, year) values (?, 'PASST plan', ?) returning id", [HUAYLAS_ID, $year])->id;
        $com = DB::selectOne("select id from committees where company_id = ? and deleted_at is null limit 1", [HUAYLAS_ID])?->id
            ?? DB::selectOne("insert into committees (company_id, committee_type, name, status, period_start_on, period_end_on) values (?, 'committee', 'Comité plan', 'active', current_date, current_date + 730) returning id", [HUAYLAS_ID])->id;
        $mk = fn ($q) => DB::selectOne("insert into committee_meetings (company_id, committee_id, meeting_type, number, scheduled_at, status, quorum_reached) values (?, ?, 'ordinary', ?, now(), 'held', ?) returning id",
            [HUAYLAS_ID, $com, random_int(1000, 999999), $q])->id;

        return [$base, $pol, $obj, $pas, $mk(false), $mk(true)];
    });

    $pre = $this->getJson("/api/v1/pla/wizard?year={$year}", $this->h)->assertOk()->json('data');
    expect(collect($pre['objectives'])->pluck('id'))->toContain($obj)->and(collect($pre['programs'])->pluck('id'))->toContain($pas);

    // Sin vínculos no se envía a revisión.
    $bare = $this->postJson('/api/v1/records/PLA/annual_plans', ['title' => 'Plan sin vínculos', 'year' => $year], $this->h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/PLA/annual_plans/{$bare}", ['status' => 'in_review'], $this->h)->assertStatus(422)->assertJsonValidationErrors('baseline_assessment_id');

    $id = $this->postJson('/api/v1/pla/wizard', ['year' => $year, 'title' => "Plan {$year}", 'baseline_assessment_id' => $base, 'policy_id' => $pol, 'annual_program_id' => $pas, 'objective_ids' => [$obj]], $this->h)
        ->assertCreated()->json('data.id');
    expect(plaAs(fn () => DB::selectOne('select annual_plan_id from annual_programs where id = ?', [$pas])->annual_plan_id))->toBe($id);
    expect(plaAs(fn () => (int) DB::selectOne('select count(*) as n from annual_plan_items where annual_plan_id = ? and objective_id = ?', [$id, $obj])->n))->toBe(1);

    $this->patchJson("/api/v1/records/PLA/annual_plans/{$id}", ['status' => 'in_review'], $this->h)->assertOk();
    $model = plaAs(fn () => DB::selectOne('select sst_model from companies where id = ?', [HUAYLAS_ID])->sst_model);
    if ($model === 'committee') {
        $this->patchJson("/api/v1/records/PLA/annual_plans/{$id}", ['status' => 'approved', 'committee_meeting_id' => $meetNoQ], $this->h)->assertStatus(422)->assertJsonValidationErrors('committee_meeting_id');
        $this->patchJson("/api/v1/records/PLA/annual_plans/{$id}", ['status' => 'approved', 'committee_meeting_id' => $meetQ], $this->h)->assertOk();
    } else {
        plaAs(fn () => DB::update('update companies set sst_model = ? where id = ?', ['committee', HUAYLAS_ID]));
        $this->patchJson("/api/v1/records/PLA/annual_plans/{$id}", ['status' => 'approved', 'committee_meeting_id' => $meetQ], $this->h)->assertOk();
    }
    // Aprobado: sus datos no cambian.
    $this->patchJson("/api/v1/records/PLA/annual_plans/{$id}", ['title' => 'Otro título'], $this->h)->assertStatus(409);

    $ind = $this->getJson("/api/v1/pla/plans/{$id}/indicators", $this->h)->assertOk()->json('data');
    expect($ind['items'])->toBe(1)->and($ind['objectives']['total'])->toBe(1);

    $this->postJson("/api/v1/pla/plans/{$id}/new-version", [], $this->h)->assertStatus(409);   // hay otro borrador del año
    $this->deleteJson("/api/v1/records/PLA/annual_plans/{$bare}", [], $this->h)->assertSuccessful();
    $v2 = $this->postJson("/api/v1/pla/plans/{$id}/new-version", [], $this->h)->assertCreated()->json('data.id');
    $row = plaAs(fn () => DB::selectOne('select version_number, previous_plan_id, status from annual_plans where id = ?', [$v2]));
    expect($row->previous_plan_id)->toBe($id)->and($row->status)->toBe('draft')->and((int) $row->version_number)->toBeGreaterThan(1);
    $this->postJson("/api/v1/pla/plans/{$id}/new-version", [], $this->h)->assertStatus(409);
});
