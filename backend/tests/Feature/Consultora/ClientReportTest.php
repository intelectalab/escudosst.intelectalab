<?php

// Exportación por cliente para el informe mensual de la consultora (M-CON §11): cumplimiento por obligación, indicadores,
// actividad del mes, alertas y vencimientos de una empresa cliente con asignación vigente; en Excel queda en la bitácora de
// exportaciones. Solo conteos e indicadores, sin datos personales (RN-CON-07).

use Illuminate\Support\Facades\DB;

const CRP_ACCOUNT_ID = '01900000-0000-7000-8000-0000000c0a02';

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $o = DB::connection('platform');
    $o->insert("insert into accounts (id, name, account_type) values (?, 'Consultores Andes', 'consultancy') on conflict (id) do update set name = excluded.name, account_type = 'consultancy'", [CRP_ACCOUNT_ID]);
    $o->update('update companies set managed_by_account_id = ? where id = ?', [CRP_ACCOUNT_ID, ANDINAS_ID]);
});

afterEach(function () {
    DB::connection('platform')->update('update companies set managed_by_account_id = null where managed_by_account_id = ?', [CRP_ACCOUNT_ID]);
});

function crpConsultant(bool $assigned = true): void
{
    $user = DB::selectOne('select id from app_auth_lookup_user(?)', [CORPORATIVO_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $m = DB::selectOne("insert into memberships (company_id, user_id, role_id, status, accepted_at)
                         select ?, ?, r.id, 'active', now() from roles r where r.account_id is null and r.code = 'consultant' returning id", [ANDINAS_ID, $user])->id;
    if ($assigned) {
        DB::insert('insert into consultant_assignments (company_id, consultancy_account_id, consultant_user_id, membership_id, is_lead) values (?, ?, ?, ?, true)',
            [ANDINAS_ID, CRP_ACCOUNT_ID, $user, $m]);
    }
    $site = DB::selectOne('select id from sites where deleted_at is null limit 1')?->id;
    if ($site) {
        DB::insert("insert into inspections (company_id, code, title, inspection_type, site_id, status, scheduled_on, completed_at)
                    values (?, 'INS-CRP1', 'Inspección del mes', 'planned', ?, 'completed', '2026-10-05', '2026-10-05 15:00:00+00')", [ANDINAS_ID, $site]);
    }
    DB::select("select set_config('app.company_id', '', false)");
}

it('arma el informe mensual de una empresa cliente con su actividad del mes y lo exporta a Excel con bitácora', function () {
    crpConsultant();
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), ANDINAS_ID);
    $r = $this->getJson('/api/v1/con/clients/'.ANDINAS_ID.'/report?month=2026-10', $h);
    expect($r->status())->toBe(200, $r->getContent());
    $d = $r->json('data');
    expect($d['consultancy'])->toBe('Consultores Andes')->and($d['company']['ruc'])->toBe('20518472632')
        ->and($d['month_label'])->toBe('octubre de 2026')->and($d['from'])->toBe('2026-10-01')->and($d['to'])->toBe('2026-10-31')
        ->and($d)->toHaveKeys(['compliance', 'indicators', 'activity', 'alerts', 'agenda'])
        ->and($d['compliance']['areas'])->not->toBeEmpty();
    $act = collect($d['activity'])->keyBy('code');
    expect($act['inspections_done']['value'])->toBe(1)->and($act)->toHaveKeys(['accidents', 'trainings_held', 'committee_meetings', 'actions_overdue']);
    // RN-CON-07: sin datos de personas.
    expect(json_encode($d))->not->toContain('full_name')->not->toContain('document_number');

    $x = $this->get('/api/v1/con/clients/'.ANDINAS_ID.'/report.xlsx?month=2026-10', $h)->assertOk();
    expect($x->headers->get('content-type'))->toContain('spreadsheetml')->and($x->headers->get('content-disposition'))->toContain('2026-10.xlsx');
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    expect(DB::selectOne("select count(*) as n from audit_logs where action = 'export' and changes->>'route' like 'con/clients/%/report.xlsx'")->n)->toBeGreaterThanOrEqual(1);
    DB::select("select set_config('app.company_id', '', false)");

    // Mes futuro o mal escrito: 422. Empresa que no es cliente: 404.
    $this->getJson('/api/v1/con/clients/'.ANDINAS_ID.'/report?month=2099-01', $h)->assertStatus(422);
    $this->getJson('/api/v1/con/clients/'.ANDINAS_ID.'/report?month=10-2026', $h)->assertStatus(422);
    $this->getJson('/api/v1/con/clients/'.HUAYLAS_ID.'/report', $h)->assertStatus(404);
});

it('un consultor sin asignación vigente no exporta el informe de esa empresa', function () {
    crpConsultant(assigned: false);
    $this->getJson('/api/v1/con/clients/'.ANDINAS_ID.'/report', authHeaders(tokenFor(CORPORATIVO_EMAIL), ANDINAS_ID))->assertForbidden();
});
