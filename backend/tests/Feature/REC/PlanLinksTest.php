<?php

// M-PLA RN-PLA-04/07/08 y criterio 4: componentes vinculados (mismo año, sin versiones obsoletas), PDF del plan aprobado versionado en
// la gestión documental y cierre del año con su informe de cumplimiento.

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

function plkAs(callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    try {
        return $fn();
    } finally {
        DB::select("select set_config('app.company_id', '', false)");
    }
}

it('vincula solo objetivos y programas del mismo año y en su versión vigente', function () {
    $year = 2140 + random_int(0, 800);
    $r = plkAs(function () use ($year) {
        $pol = DB::selectOne("select id from sst_policies where deleted_at is null limit 1")?->id
            ?? DB::selectOne("insert into sst_policies (company_id, title, version_number, content_html, status, effective_on) values (?, 'Política', 901, '<p>x</p>', 'current', current_date) returning id", [HUAYLAS_ID])->id;
        $obj = fn ($y, $code, $st = 'active') => DB::selectOne("insert into sst_objectives (company_id, policy_id, year, code, title, status) values (?, ?, ?, ?, 'Objetivo', ?) returning id", [HUAYLAS_ID, $pol, $y, $code, $st])->id;
        $pas = fn ($y, $v, $st = 'draft', $prev = null) => DB::selectOne('insert into annual_programs (company_id, title, year, status, previous_program_id, version_number) values (?, ?, ?, ?, ?, ?) returning id',
            [HUAYLAS_ID, 'PASST '.$y, $y, $st, $prev, $v])->id;
        $old = $pas($year, 1);

        return ['ok' => $obj($year, 'O1'), 'otherYear' => $obj($year + 1, 'O2'), 'cancelled' => $obj($year, 'O3', 'cancelled'), 'pasOld' => $old, 'pasNew' => $pas($year, 2, 'draft', $old),
            'pasOther' => $pas($year + 1, 1), 'pasClosed' => $pas($year, 3, 'closed'),
            'tp' => DB::selectOne("insert into training_programs (company_id, title, year) values (?, 'Capacitación', ?) returning id", [HUAYLAS_ID, $year])->id];
    });
    $plan = $this->postJson('/api/v1/records/PLA/annual_plans', ['title' => "Plan {$year}", 'year' => $year], $this->h)->assertCreated()->json('data.id');
    $item = fn (array $b) => $this->postJson('/api/v1/records/PLA/annual_plan_items', ['annual_plan_id' => $plan, 'description' => 'Componente'] + $b, $this->h);

    $item(['item_type' => 'objective', 'objective_id' => $r['otherYear']])->assertStatus(422)->assertJsonValidationErrors('objective_id');
    $item(['item_type' => 'objective', 'objective_id' => $r['cancelled']])->assertStatus(422)->assertJsonValidationErrors('objective_id');
    $o = $item(['item_type' => 'objective', 'objective_id' => $r['ok']])->assertCreated()->json('data.id');
    expect(plkAs(fn () => DB::selectOne('select reference_type, reference_id from annual_plan_items where id = ?', [$o])))
        ->reference_type->toBe('sst_objectives')->reference_id->toBe($r['ok']);

    $item(['item_type' => 'program'])->assertStatus(422)->assertJsonValidationErrors('reference_id');
    $item(['item_type' => 'program', 'reference_type' => 'annual_programs', 'reference_id' => $r['pasOther']])->assertStatus(422)->assertJsonValidationErrors('reference_id');
    $item(['item_type' => 'program', 'reference_type' => 'annual_programs', 'reference_id' => $r['pasClosed']])->assertStatus(422)->assertJsonValidationErrors('reference_id');
    $item(['item_type' => 'program', 'reference_type' => 'annual_programs', 'reference_id' => $r['pasOld']])->assertStatus(422)->assertJsonValidationErrors('reference_id');
    $item(['item_type' => 'program', 'reference_type' => 'annual_programs', 'reference_id' => $r['pasNew']])->assertCreated();
    $item(['item_type' => 'program', 'reference_type' => 'training_programs', 'reference_id' => $r['tp']])->assertCreated();

    // Un recurso no lleva referencia aunque se envíe.
    $res = $item(['item_type' => 'human_resource', 'reference_type' => 'annual_programs', 'reference_id' => $r['pasNew']])->assertCreated()->json('data.id');
    expect(plkAs(fn () => DB::selectOne('select reference_id from annual_plan_items where id = ?', [$res])->reference_id))->toBeNull();

    // Las opciones del selector solo traen la versión vigente del PASST del año.
    $opts = collect($this->getJson("/api/v1/pla/plans/{$plan}/reference-options?type=annual_programs", $this->h)->assertOk()->json('data'))->pluck('id');
    expect($opts)->toContain($r['pasNew'])->not->toContain($r['pasOld'])->not->toContain($r['pasClosed'])->not->toContain($r['pasOther']);
});

it('al aprobar guarda el PDF del plan como versión en la gestión documental, con política y programas', function () {
    $year = 2041 + random_int(0, 58);   // el asistente acepta años hasta 2100
    [$base, $pol, $obj, $pas, $meet] = plkAs(function () use ($year) {
        DB::update("update companies set sst_model = 'committee' where id = ?", [HUAYLAS_ID]);
        $base = DB::selectOne("insert into baseline_assessments (company_id, title, assessment_on, status) values (?, 'LB plan', current_date, 'approved') returning id", [HUAYLAS_ID])->id;
        $pol = DB::selectOne("select id from sst_policies where status in ('current', 'review_required') and deleted_at is null limit 1")?->id
            ?? DB::selectOne("insert into sst_policies (company_id, title, version_number, content_html, status, effective_on) values (?, 'Política plan', 902, '<p>Compromiso</p>', 'current', current_date) returning id", [HUAYLAS_ID])->id;
        $obj = DB::selectOne("insert into sst_objectives (company_id, policy_id, year, code, title, status) values (?, ?, ?, ?, 'Reducir accidentes', 'active') returning id", [HUAYLAS_ID, $pol, $year, 'OBJ-'.random_int(1000, 999999)])->id;
        $pas = DB::selectOne("insert into annual_programs (company_id, title, year, version_number) values (?, 'PASST del plan', ?, ?) returning id", [HUAYLAS_ID, $year, random_int(100, 999999)])->id;
        $com = DB::selectOne('select id from committees where company_id = ? and deleted_at is null limit 1', [HUAYLAS_ID])?->id
            ?? DB::selectOne("insert into committees (company_id, committee_type, name, status, period_start_on, period_end_on) values (?, 'committee', 'Comité', 'active', current_date, current_date + 730) returning id", [HUAYLAS_ID])->id;
        $meet = DB::selectOne("insert into committee_meetings (company_id, committee_id, meeting_type, number, scheduled_at, status, quorum_reached) values (?, ?, 'ordinary', ?, now(), 'held', true) returning id",
            [HUAYLAS_ID, $com, random_int(1000, 999999)])->id;

        return [$base, $pol, $obj, $pas, $meet];
    });
    $w = $this->postJson('/api/v1/pla/wizard', ['year' => $year, 'title' => "Plan {$year}", 'baseline_assessment_id' => $base, 'policy_id' => $pol, 'annual_program_id' => $pas, 'objective_ids' => [$obj]], $this->h);
    expect($w->status())->toBe(201, (string) $w->getContent());
    $id = $w->json('data.id');
    // El asistente deja el PASST como componente de tipo programa.
    expect(plkAs(fn () => DB::selectOne("select reference_id from annual_plan_items where annual_plan_id = ? and item_type = 'program'", [$id])?->reference_id))->toBe($pas);

    $this->patchJson("/api/v1/records/PLA/annual_plans/{$id}", ['status' => 'approved', 'committee_meeting_id' => $meet], $this->h)->assertOk();
    $p = plkAs(fn () => DB::selectOne('select file_id, document_version_id from annual_plans where id = ?', [$id]));
    expect($p->file_id)->not->toBeNull()->and($p->document_version_id)->not->toBeNull();
    $dv = plkAs(fn () => DB::selectOne("select v.status, v.file_id, d.code from document_versions v join documents d on d.id = v.document_id where v.id = ?", [$p->document_version_id]));
    expect($dv->status)->toBe('current')->and($dv->file_id)->toBe($p->file_id)->and($dv->code)->toBe("PLAN-ANUAL-SST-{$year}");

    $pdf = $this->get("/api/v1/pla/plans/{$id}/pdf", $this->h)->assertOk();
    expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');
    $data = $this->getJson("/api/v1/reports/annual_plans/{$id}", $this->h)->assertOk()->json('data');
    expect($data['policy'])->not->toBeNull()->and(collect($data['programs'])->pluck('id'))->toContain($pas);
});

it('cierra el plan solo al terminar su año y genera el informe de cumplimiento', function () {
    $past = (int) now('America/Lima')->format('Y') - 1 - random_int(0, 30);
    $thisYear = (int) now('America/Lima')->format('Y');
    [$old, $now] = plkAs(fn () => [
        DB::selectOne("insert into annual_plans (company_id, year, title, status, version_number, approved_at) values (?, ?, 'Plan pasado', 'current', 1, now()) returning id", [HUAYLAS_ID, $past])->id,
        DB::selectOne("insert into annual_plans (company_id, year, title, status, version_number, approved_at) values (?, ?, 'Plan del año', 'current', 900 + ?, now()) returning id",
            [HUAYLAS_ID, $thisYear, random_int(1, 9000)])->id,
    ]);
    $this->patchJson("/api/v1/records/PLA/annual_plans/{$now}", ['status' => 'closed'], $this->h)->assertStatus(422)->assertJsonValidationErrors('status');
    $this->patchJson("/api/v1/records/PLA/annual_plans/{$old}", ['status' => 'closed'], $this->h)->assertOk();
    $row = plkAs(fn () => DB::selectOne('select status, closed_at, closed_by, closure_report_file_id from annual_plans where id = ?', [$old]));
    expect($row->status)->toBe('closed')->and($row->closed_at)->not->toBeNull()->and($row->closure_report_file_id)->not->toBeNull();
    // Cerrado, nada cambia.
    $this->patchJson("/api/v1/records/PLA/annual_plans/{$old}", ['title' => 'Otro'], $this->h)->assertStatus(409);
});
