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

function supervisorInHuaylas(): string
{
    $sup = DB::selectOne('select id from app_auth_lookup_user(?)', [PROFESIONAL_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    if (! DB::selectOne('select 1 as ok from memberships where company_id = ? and user_id = ? and deleted_at is null', [HUAYLAS_ID, $sup])) {
        DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, (select id from roles where account_id is null and code = 'sst_supervisor'), 'active', now())",
            [HUAYLAS_ID, $sup]);
    }

    return $sup;
}

it('la revisión diaria avisa un puesto crítico sin PETS', function () {
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $pos = DB::selectOne("insert into positions (company_id, code, name, is_critical, status) values (?, ?, 'Soldador en altura', true, 'active') returning id",
        [HUAYLAS_ID, 'PCR-'.random_int(1000, 9999)])->id;

    app(\App\Modules\NOT\SstAlerts::class)->forCompany(DB::connection(), HUAYLAS_ID);

    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect((int) DB::selectOne("select count(*) as n from notifications where event_code = 'pet.critical_without_pets' and subject_id = ? and recipient_user_id = ?", [$pos, $this->me])->n)->toBe(1);
});

it('avisa al revisor cuando le asignan la revisión de un cambio', function () {
    $sup = supervisorInHuaylas();
    $c = $this->postJson('/api/v1/records/MOC/change_requests', ['title' => 'Cambio de compresor', 'change_type' => 'equipment', 'description' => 'Compresor nuevo'], $this->h)
        ->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/MOC/change_reviews', ['change_request_id' => $c, 'reviewer_user_id' => $sup, 'review_area' => 'SST', 'decision' => 'request_info'], $this->h)
        ->assertCreated();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect((int) DB::selectOne("select count(*) as n from notifications where event_code = 'moc.review_assigned' and subject_id = ? and recipient_user_id = ?", [$c, $sup])->n)->toBe(1);
});

it('avisa al supervisor cuando entra un trabajador cuyo puesto tiene EPP obligatorio sin entregar', function () {
    $sup = supervisorInHuaylas();
    $pos = DB::selectOne("insert into positions (company_id, code, name, status) values (?, ?, 'Operario de prensa', 'active') returning id", [HUAYLAS_ID, 'PEP-'.random_int(1000, 9999)])->id;
    $item = DB::selectOne("insert into ppe_items (company_id, code, name, category) values (?, ?, 'Protector auditivo', 'hearing') returning id", [HUAYLAS_ID, 'EPP-'.random_int(1000, 9999)])->id;
    DB::insert('insert into ppe_position_requirements (company_id, position_id, ppe_item_id, is_mandatory) values (?, ?, ?, true)', [HUAYLAS_ID, $pos, $item]);

    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(40000000, 49999999), 'first_name' => 'Nuevo', 'paternal_last_name' => 'Ingreso',
        'current_position_id' => $pos], $this->h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $n = DB::selectOne("select count(*) as n, max(body) as body from notifications where event_code = 'epp.new_worker_pending' and subject_id = ? and recipient_user_id = ?", [$w, $sup]);
    expect((int) $n->n)->toBe(1)->and($n->body)->toContain('Protector auditivo');
});
