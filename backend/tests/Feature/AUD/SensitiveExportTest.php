<?php

// AUD: «Exportar» (CSV) de los accesos a datos sensibles con los filtros; sin contenido médico y la exportación queda en la bitácora.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

it('exporta los accesos a datos sensibles en CSV a quien tiene permiso y lo registra', function () {
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $worker = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '70881133', 'first_name' => 'Luz', 'paternal_last_name' => 'Exportada'], $h)->json('data.id');
    $this->get('/api/v1/company/audit/sensitive/export', $h)->assertForbidden();

    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $uid = DB::selectOne('select id from users where email = ?', [CORPORATIVO_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $role = DB::selectOne("select id from roles where account_id is null and code = 'external_auditor'")->id;
    DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, ?, 'active', now())", [ANDINAS_ID, $uid, $role]);
    DB::insert(
        "insert into sensitive_access_logs (company_id, user_id, role_code, worker_id, resource, action, purpose, result, ip, occurred_at)
         values (?, ?, 'occupational_doctor', ?, 'medical_exam', 'read', 'Control periódico', 'granted', '190.236.10.20', now()),
                (?, ?, 'sst_manager', ?, 'medical_exam', 'read', null, 'denied', null, now() - interval '1 minute')",
        [ANDINAS_ID, $uid, $worker, ANDINAS_ID, $uid, $worker]
    );
    DB::select("select set_config('app.company_id', '', false)");

    app('auth')->forgetGuards();
    $h2 = authHeaders(tokenFor(CORPORATIVO_EMAIL), ANDINAS_ID);
    $res = $this->get('/api/v1/company/audit/sensitive/export?result=granted', $h2)->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/csv');
    $csv = $res->streamedContent();
    expect($csv)->toContain('Dato consultado')->toContain('EMO de Luz Exportada')->toContain('Control periódico')->toContain('190.236.xx.xx')
        ->not->toContain('190.236.10.20')->not->toContain('Rechazado');

    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    expect(DB::selectOne("select count(*) as n from audit_logs where entity_type = 'sensitive_access_logs' and action = 'export' and actor_user_id = ?", [$uid])->n)->toBe(1);
});
