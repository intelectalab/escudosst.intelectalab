<?php

// M-COM §11: informe anual del comité para la revisión por la dirección (sesiones programadas y realizadas, asistencia por
// integrante, acuerdos y su estado, inspecciones con el comité), su Excel, y las sesiones y acuerdos en las entradas de RVD.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

function carAs(callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    try {
        return $fn();
    } finally {
        DB::select("select set_config('app.company_id', '', false)");
    }
}

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    carAs(fn () => DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('COM', 'RVD', 'INS') and deleted_at is null", [HUAYLAS_ID]));
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->year = 2031;
    $this->committee = carAs(function () {
        $c = DB::selectOne("insert into committees (company_id, committee_type, name, period_start_on, period_end_on, installation_on, status)
                            values (?, 'committee', 'Comité SST 2031', '2031-01-01', '2032-12-31', '2031-01-10', 'active') returning id", [HUAYLAS_ID])->id;
        $m = [];
        foreach ([['employer', 'president', 'Presidenta'], ['workers', 'secretary', 'Secretario'], ['workers', 'member', 'Miembro']] as [$rep, $role, $name]) {
            $w = DB::selectOne("insert into workers (company_id, document_number, document_number_hash, first_name, paternal_last_name, status)
                                values (?, ?, decode(md5(random()::text), 'hex'), ?, 'Comité', 'active') returning id", [HUAYLAS_ID, (string) random_int(10000000, 99999999), $name])->id;
            $m[] = DB::selectOne("insert into committee_members (company_id, committee_id, worker_id, representation, member_type, role_in_committee, starts_on, status)
                                  values (?, ?, ?, ?, 'titular', ?, '2031-01-01', 'active') returning id", [HUAYLAS_ID, $c, $w, $rep, $role])->id;
        }
        $held = DB::selectOne("insert into committee_meetings (company_id, committee_id, meeting_type, number, scheduled_at, held_at, quorum_reached, attendance, status)
                               values (?, ?, 'ordinary', 1, '2031-02-10 15:00:00+00', '2031-02-10 15:00:00+00', true, ?::jsonb, 'minutes_signed') returning id",
            [HUAYLAS_ID, $c, json_encode([$m[0], $m[1]])])->id;
        DB::insert("insert into committee_meetings (company_id, committee_id, meeting_type, number, scheduled_at, status) values (?, ?, 'extraordinary', 2, '2031-03-05 15:00:00+00', 'scheduled')", [HUAYLAS_ID, $c]);
        DB::insert("insert into committee_meetings (company_id, committee_id, meeting_type, number, scheduled_at, status) values (?, ?, 'ordinary', 3, '2031-04-10 15:00:00+00', 'cancelled')", [HUAYLAS_ID, $c]);
        DB::insert("insert into meeting_agreements (company_id, meeting_id, number, description, due_on, status) values (?, ?, 1, 'Señalizar el almacén', '2031-03-01', 'fulfilled')", [HUAYLAS_ID, $held]);
        DB::insert("insert into meeting_agreements (company_id, meeting_id, number, description, due_on, status) values (?, ?, 2, 'Comprar extintores', '2031-03-15', 'overdue')", [HUAYLAS_ID, $held]);
        $site = DB::selectOne('select id from sites where deleted_at is null limit 1')->id;
        DB::insert("insert into inspections (company_id, code, title, inspection_type, site_id, status, committee_participation, scheduled_on, completed_at)
                    values (?, 'INS-COM31', 'Inspección del comité', 'planned', ?, 'completed', true, '2031-05-02', '2031-05-02 16:00:00+00')", [HUAYLAS_ID, $site]);

        return $c;
    });
});

afterEach(function () {
    app('auth')->forgetGuards();
    Permissions::flush();
    $GLOBALS['sus_tokens'] = [];
});

it('arma el informe anual del comité con sesiones, asistencia, acuerdos e inspecciones del año', function () {
    $d = $this->getJson("/api/v1/reports/committees/{$this->committee}/annual?year={$this->year}", $this->h)->assertOk()->json('data');
    expect($d['year'])->toBe(2031)->and($d['company']['ruc'])->not->toBeEmpty();
    expect($d['summary'])->toMatchArray([
        'meetings_planned' => 3, 'meetings_held' => 1, 'meetings_cancelled' => 1, 'meetings_pending' => 1, 'ordinary_held' => 1, 'extraordinary_held' => 0,
        'months_with_ordinary' => 1, 'with_quorum' => 1, 'minutes_signed' => 1, 'agreements_total' => 2, 'inspections_with_committee' => 1, 'inspections_with_committee_done' => 1,
    ]);
    expect((float) $d['summary']['average_attendance_pct'])->toBe(66.7)
        ->and($d['summary']['agreements_by_status']['fulfilled'])->toBe(1)->and($d['summary']['agreements_by_status']['overdue'])->toBe(1);
    $president = collect($d['members'])->firstWhere('role_in_committee', 'president');
    expect($president['attended'])->toBe(1)->and((float) $president['attendance_pct'])->toBe(100.0);
    expect(collect($d['members'])->firstWhere('role_in_committee', 'member')['attended'])->toBe(0);
    expect(collect($d['agreements'])->pluck('status_label')->all())->toBe(['Cumplido', 'Vencido']);

    // Otro año: sin sesiones.
    $this->getJson("/api/v1/reports/committees/{$this->committee}/annual?year=2030", $this->h)->assertOk()->assertJsonPath('data.summary.meetings_held', 0);
    $this->getJson("/api/v1/reports/committees/{$this->committee}/annual?year=99", $this->h)->assertStatus(422);
});

it('descarga el informe anual en Excel', function () {
    $r = $this->get("/api/v1/reports/committees/{$this->committee}/annual.xlsx?year={$this->year}", $this->h)->assertOk();
    expect($r->headers->get('Content-Type'))->toContain('spreadsheetml')
        ->and($r->headers->get('Content-Disposition'))->toContain('informe-anual-comite-2031.xlsx');
});

it('las entradas de la revisión por la dirección incluyen las sesiones y acuerdos del comité', function () {
    $in = carAs(fn () => \App\Modules\COM\CommitteeAnnualReport::reviewInputs('2031-01-01', '2031-12-31'));
    expect((int) $in['sesiones']['programadas'])->toBe(3)->and((int) $in['sesiones']['realizadas'])->toBe(1)->and((int) $in['sesiones']['actas_firmadas'])->toBe(1)
        ->and((int) $in['acuerdos']['total'])->toBe(2)->and((int) $in['acuerdos']['cumplidos'])->toBe(1)->and((int) $in['acuerdos']['vencidos'])->toBe(1)
        ->and($in['inspecciones_con_comite'])->toBe(1);

    $id = $this->postJson('/api/v1/records/RVD/management_reviews', ['year' => 2031, 'title' => 'Revisión 2031'], $this->h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/RVD/management_reviews/{$id}", ['status' => 'in_progress'], $this->h)->assertOk();
    $inputs = $this->getJson("/api/v1/records/RVD/management_reviews/{$id}", $this->h)->json('data.inputs');
    $inputs = is_string($inputs) ? json_decode($inputs, true) : $inputs;
    expect((int) $inputs['comite']['sesiones']['realizadas'])->toBe(1)->and((int) $inputs['comite']['acuerdos']['total'])->toBe(2);
});
