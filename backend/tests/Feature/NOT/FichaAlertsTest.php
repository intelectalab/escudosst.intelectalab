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
    // M-SIM §10: el aviso del simulacro va a los brigadistas de la sede.
    $brig = DB::selectOne("insert into brigades (company_id, site_id, brigade_type, name, status) values (?, ?, 'evacuation', 'Brigada de evacuación AV', 'active') returning id", [HUAYLAS_ID, $site])->id;
    $wk = DB::selectOne("insert into workers (company_id, document_number, document_number_hash, first_name, paternal_last_name, status) values (?, ?, decode(md5(random()::text), 'hex'), 'Brigadista', 'Prueba', 'active') returning id",
        [HUAYLAS_ID, (string) random_int(50000000, 59999999)])->id;
    DB::insert("insert into brigade_members (company_id, brigade_id, worker_id, role, status) values (?, ?, ?, 'member', 'active')", [HUAYLAS_ID, $brig, $wk]);

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

it('al publicar la política pide el cargo a todos los trabajadores activos (RN-POL-04)', function () {
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $pol = $this->postJson('/api/v1/records/POL/sst_policies', ['title' => 'Política SST con cargo', 'content_html' => '<p>Cuidamos la vida.</p>'], $h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    DB::update('update sst_policies set prepared_by = ? where id = ?', ['01900000-0000-7000-8000-000000000301', $pol]);
    $this->patchJson("/api/v1/records/POL/sst_policies/{$pol}", ['status' => 'approved'], $h)->assertOk();
    $this->patchJson("/api/v1/records/POL/sst_policies/{$pol}", ['status' => 'current'], $h)->assertOk();

    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $v = DB::selectOne('select document_version_id from sst_policies where id = ?', [$pol])->document_version_id;
    expect($v)->not->toBeNull();
    $active = (int) DB::selectOne("select count(*) as n from workers where deleted_at is null and status in ('active', 'on_leave')")->n;
    $asked = (int) DB::selectOne("select count(distinct recipient_worker_id) as n from notifications where event_code = 'doc.ack_requested' and subject_id = ?", [$v])->n;
    expect($asked)->toBe($active);
});

it('al publicar el RISST deja una entrega pendiente para cada trabajador activo (RN-RIS-04)', function () {
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $r = DB::selectOne("insert into risst_versions (company_id, version_number, title, content_html, status, approved_at) values (?, (select coalesce(max(version_number), 0) + 1 from risst_versions), 'RISST de prueba', '<p>Reglamento</p>', 'approved', now()) returning id", [ANDINAS_ID])->id;
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $res = $this->patchJson("/api/v1/records/RIS/risst_versions/{$r}", ['status' => 'current'], $h);
    if ($res->status() === 403) {
        $this->markTestSkipped('RIS no está en el plan de la empresa de prueba.');
    }
    expect($res->status())->toBe(200, json_encode($res->json()));
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $active = (int) DB::selectOne("select count(*) as n from workers where deleted_at is null and status in ('active', 'on_leave')")->n;
    expect((int) DB::selectOne("select count(*) as n from risst_deliveries where risst_version_id = ? and status = 'pending'", [$r])->n)->toBe($active);
});

it('una medición sobre el límite avisa al Jefe SST y al médico ocupacional', function () {
    $sup = supervisorInHuaylas();
    DB::update("update memberships set role_id = (select id from roles where account_id is null and code = 'occupational_doctor') where company_id = ? and user_id = ?", [HUAYLAS_ID, $sup]);
    Permissions::flush();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $agent = DB::selectOne('select id from agent_types limit 1')->id;
    $site = DB::selectOne('select id from sites where company_id = ? and deleted_at is null limit 1', [HUAYLAS_ID])->id;
    $camp = DB::selectOne("insert into monitoring_campaigns (company_id, title, status) values (?, 'Ruido AV', 'planned') returning id", [HUAYLAS_ID])->id;
    $point = DB::selectOne("insert into monitoring_points (company_id, site_id, agent_type_id, code, name, status) values (?, ?, ?, ?, 'Prensa AV', 'active') returning id",
        [HUAYLAS_ID, $site, $agent, 'PAV-'.random_int(100, 999)])->id;
    $m = DB::selectOne("insert into measurements (company_id, campaign_id, monitoring_point_id, agent_type_id, measured_at, value, unit, result) values (?, ?, ?, ?, now(), 95, 'dB(A)', 'exceeds_limit') returning id",
        [HUAYLAS_ID, $camp, $point, $agent])->id;
    app()->instance(\App\Core\Tenancy\CompanyContext::class, new \App\Core\Tenancy\CompanyContext(HUAYLAS_ID, '', 'sst_manager', 'Huaylas', '20000000000'));
    \App\Modules\NOT\FichaEvents::after('create', 'measurements', $m, ['result' => 'exceeds_limit'], null);
    DB::update("update memberships set role_id = (select id from roles where account_id is null and code = 'sst_supervisor') where company_id = ? and user_id = ?", [HUAYLAS_ID, $sup]);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect((int) DB::selectOne("select count(*) as n from notifications where event_code = 'mag.exceeds_limit' and subject_id = ? and recipient_user_id = ?", [$m, $sup])->n)->toBe(1);
});
