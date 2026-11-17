<?php

// M-DSH: historia de indicadores guardada por mes y sede, consulta y series en Excel.

use App\Core\Auth\Permissions;
use App\Modules\DSH\Services\KpiSnapshots;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'DSH' and deleted_at is null", [HUAYLAS_ID]);
    DB::select("select set_config('app.company_id', '', false)");
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

it('guarda los indicadores del año por mes y los devuelve como series y en Excel', function () {
    $n = app(KpiSnapshots::class)->forCompany(HUAYLAS_ID);
    expect($n)->toBeGreaterThan(0);
    // Correr de nuevo no duplica: actualiza el mes en curso.
    $again = app(KpiSnapshots::class)->forCompany(HUAYLAS_ID);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $dups = DB::selectOne("select count(*) as n from (select kpi_definition_id, period_start, site_id, count(*) from kpi_snapshots where deleted_at is null
                           group by 1, 2, 3 having count(*) > 1) x")->n;
    DB::select("select set_config('app.company_id', '', false)");
    expect((int) $dups)->toBe(0)->and($again)->toBeGreaterThan(0);

    $h = $this->getJson('/api/v1/dsh/history?year='.date('Y'), $this->h)->assertOk()->json('data');
    expect($h['series'])->not->toBeEmpty()->and($h['series'][0]['points'][0]['month'])->toBe(1);
    $x = $this->get('/api/v1/dsh/history.xlsx?year='.date('Y'), $this->h);
    expect($x->status())->toBe(200)->and(substr($x->getContent(), 0, 2))->toBe('PK');
});
