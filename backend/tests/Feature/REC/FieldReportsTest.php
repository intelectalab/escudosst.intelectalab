<?php

// Informes imprimibles de campo (§11 de las fichas): inspección, PETAR y ATS con sus datos, pasos y firmas.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('INS', 'PTR', 'ATS') and deleted_at is null", [HUAYLAS_ID]);
    $this->site = DB::selectOne('select id from sites where company_id = ? and deleted_at is null limit 1', [HUAYLAS_ID])->id;
    DB::select("select set_config('app.company_id', '', false)");
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

function frAs(callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    try {
        return $fn();
    } finally {
        DB::select("select set_config('app.company_id', '', false)");
    }
}

it('arma el informe del ATS con sus pasos y participantes', function () {
    $ats = frAs(fn () => DB::selectOne("insert into ats_forms (company_id, code, task, site_id, performed_on, status) values (?, 'ATS-FR1', 'Cambio de luminarias', ?, current_date, 'draft') returning id",
        [HUAYLAS_ID, $this->site])->id);
    frAs(fn () => DB::insert("insert into ats_steps (company_id, ats_form_id, step_number, description, hazards, controls) values (?, ?, 1, 'Colocar la escalera', 'Caída a distinto nivel', 'Arnés y vigía')",
        [HUAYLAS_ID, $ats]));
    $r = $this->getJson("/api/v1/reports/ats_forms/{$ats}", $this->h)->assertOk();
    expect($r->json('data.ats.task'))->toBe('Cambio de luminarias')->and($r->json('data.steps.0.controls'))->toBe('Arnés y vigía')
        ->and($r->json('data.company.ruc'))->not->toBeEmpty();
});

it('arma el informe de la inspección y el del PETAR', function () {
    $ins = frAs(fn () => DB::selectOne("insert into inspections (company_id, code, title, inspection_type, site_id, status, score_pct) values (?, 'INS-FR1', 'Orden y limpieza', 'planned', ?, 'completed', 85) returning id",
        [HUAYLAS_ID, $this->site])->id);
    $this->getJson("/api/v1/reports/inspections/{$ins}", $this->h)->assertOk()->assertJsonPath('data.inspection.score_pct', 85)->assertJsonPath('data.inspection.title', 'Orden y limpieza');

    $type = frAs(fn () => DB::selectOne("insert into permit_types (company_id, code, name, category, checklist, approval_chain) values (?, ?, 'Trabajo en altura', 'work_at_height', '[]', '[]') returning id",
        [HUAYLAS_ID, 'PT-'.random_int(1000, 9999)])->id);
    $ptr = frAs(fn () => DB::selectOne("insert into work_permits (company_id, code, permit_type_id, site_id, work_description, valid_from, valid_until, status)
                                         values (?, 'PTR-FR1', ?, ?, 'Montaje de techo', now(), now() + interval '8 hours', 'draft') returning id", [HUAYLAS_ID, $type, $this->site])->id);
    $this->getJson("/api/v1/reports/work_permits/{$ptr}", $this->h)->assertOk()->assertJsonPath('data.permit.work_description', 'Montaje de techo')
        ->assertJsonPath('data.permit.type.name', 'Trabajo en altura');
    $this->getJson('/api/v1/reports/work_permits/00000000-0000-7000-8000-000000000999', $this->h)->assertNotFound();
});

it('arma el legajo SST del trabajador sin datos clínicos', function () {
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(66000000, 66999999), 'first_name' => 'Rosa', 'paternal_last_name' => 'Legajo'], $this->h)
        ->assertCreated()->json('data.id');
    frAs(fn () => DB::insert("insert into fitness_status (company_id, worker_id, status, valid_from, valid_until, work_restrictions, is_current) values (?, ?, 'fit_with_restrictions', current_date, current_date + 365, 'No levantar más de 15 kg', true)",
        [HUAYLAS_ID, $w]));
    $r = $this->getJson("/api/v1/trb/workers/{$w}/legajo", $this->h)->assertOk();
    expect($r->json('data.worker.full_name'))->toContain('Rosa')->and($r->json('data.worker.document'))->toContain('****')
        ->and($r->json('data.fitness'))->toHaveKeys(['status', 'valid_until', 'work_restrictions'])->and(array_keys($r->json('data.fitness')))->toHaveCount(4);
});
