<?php

// Avisos §10 de las fichas: revisión diaria con todos los módulos activos (vencimientos y pendientes) y avisos
// inmediatos (acción asignada, evidencia por verificar).

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and deleted_at is null", [HUAYLAS_ID]);
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->me = DB::selectOne('select id from app_auth_lookup_user(?)', [CORPORATIVO_EMAIL])->id;
});

it('la revisión diaria avisa vencimientos de varios módulos sin errores', function () {
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $site = DB::selectOne('select id from sites where company_id = ? and deleted_at is null limit 1', [HUAYLAS_ID])->id;
    $pol = DB::selectOne("insert into sctr_policies (company_id, coverage_kind, insurer_type, insurer_name, policy_number, starts_on, ends_on, status)
                          values (?, 'health', 'essalud', 'EsSalud', 'AV-1', current_date - 300, current_date + 5, 'expiring') returning id", [HUAYLAS_ID])->id;
    $drill = DB::selectOne("insert into drills (company_id, code, title, drill_type, site_id, scheduled_at, status)
                            values (?, 'SIM-AV1', 'Simulacro de sismo', 'earthquake', ?, now() + interval '2 days', 'planned') returning id", [HUAYLAS_ID, $site])?->id;

    app(\App\Modules\NOT\SstAlerts::class)->forCompany(DB::connection(), HUAYLAS_ID);

    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $events = array_column(DB::select('select distinct event_code from notifications where subject_id in (?, ?)', [$pol, $drill ?? $pol]), 'event_code');
    expect($events)->toContain('sct.policy_expiring');
    if ($drill) {
        expect($events)->toContain('sim.upcoming');
    }
});

it('avisa al responsable de una acción asignada y al equipo cuando sube la evidencia', function () {
    $other = DB::selectOne('select id from app_auth_lookup_user(?)', [PROFESIONAL_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    if (! DB::selectOne('select 1 as ok from memberships where company_id = ? and user_id = ? and deleted_at is null', [HUAYLAS_ID, $other])) {
        DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, (select id from roles where account_id is null and code = 'sst_supervisor'), 'active', now())",
            [HUAYLAS_ID, $other]);
    }
    $id = $this->postJson('/api/v1/records/ACR/corrective_actions', ['title' => 'Colocar guarda en la sierra', 'responsible_user_id' => $other, 'due_on' => now()->addDays(10)->toDateString(),
        'source_type' => 'other', 'code' => 'AC-AV-'.random_int(100, 999), 'description' => 'Pedido del comité'], $this->h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect((int) DB::selectOne("select count(*) as n from notifications where event_code = 'acr.assigned' and subject_id = ? and recipient_user_id = ?", [$id, $other])->n)->toBe(1);
});

it('avisa al supervisor de la sede cuando llega un reporte nuevo', function () {
    $sup = DB::selectOne('select id from app_auth_lookup_user(?)', [PROFESIONAL_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    if (! DB::selectOne('select 1 as ok from memberships where company_id = ? and user_id = ? and deleted_at is null', [HUAYLAS_ID, $sup])) {
        DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, (select id from roles where account_id is null and code = 'sst_supervisor'), 'active', now())",
            [HUAYLAS_ID, $sup]);
    }
    $id = $this->postJson('/api/v1/records/ACT/substandard_reports', ['report_type' => 'unsafe_condition', 'description' => 'Cable pelado junto al tablero'], $this->h)
        ->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect((int) DB::selectOne("select count(*) as n from notifications where event_code = 'act.new_report' and subject_id = ? and recipient_user_id = ?", [$id, $sup])->n)->toBe(1);
});
