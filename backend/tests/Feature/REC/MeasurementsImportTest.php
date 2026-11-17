<?php

// M-MAG SCR-MAG-03/05: carga masiva de mediciones desde Excel (revisar y registrar las válidas) y tendencia por punto.

use App\Core\Auth\Permissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'MAG' and deleted_at is null", [HUAYLAS_ID]);
    DB::select("select set_config('app.company_id', '', false)");
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

it('revisa y registra las mediciones válidas del Excel y muestra la tendencia del punto', function () {
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $agent = DB::selectOne("select id, code from agent_types where deleted_at is null and code is not null limit 1");
    $site = DB::selectOne('select id from sites where company_id = ? order by is_main desc limit 1', [HUAYLAS_ID])->id;
    $code = 'PM-'.random_int(1000, 999999);
    $point = DB::selectOne('insert into monitoring_points (company_id, site_id, agent_type_id, code, name) values (?, ?, ?, ?, ?) returning id', [HUAYLAS_ID, $site, $agent->id, $code, 'Punto prueba'])->id;
    DB::insert("insert into exposure_limits (company_id, agent_type_id, limit_type, limit_value, unit, valid_from) values (?, ?, 'TWA', 85, 'dB', '2030-12-31')", [HUAYLAS_ID, $agent->id]);
    $camp = DB::selectOne("insert into monitoring_campaigns (company_id, title, year, status) values (?, 'Campaña RR', 2031, 'planned') returning id", [HUAYLAS_ID])->id;
    DB::select("select set_config('app.company_id', '', false)");

    $csv = "Código del punto,Código del agente,Fecha y hora,Valor,Unidad\n{$code},{$agent->code},2031-02-01 09:00,60,dB\n{$code},{$agent->code},2031-03-01 09:00,90,dB\nNOEXISTE,,2031-03-01 09:00,abc,dB\n";
    $file = fn () => UploadedFile::fake()->createWithContent('mediciones.csv', $csv);

    $prev = $this->post('/api/v1/mag/import', ['campaign_id' => $camp, 'file' => $file()], $this->h + ['Accept' => 'application/json'])->assertOk()->json('data');
    expect($prev['total'])->toBe(3)->and($prev['valid'])->toBe(2)->and($prev['applied'])->toBeFalse();

    $done = $this->post('/api/v1/mag/import', ['campaign_id' => $camp, 'file' => $file(), 'apply' => 1], $this->h + ['Accept' => 'application/json'])->assertOk()->json('data');
    expect(collect($done['rows'])->pluck('result')->filter()->values()->all())->toBe(['action_level', 'exceeds_limit']);

    $t = $this->getJson("/api/v1/mag/trend?point_id={$point}", $this->h)->assertOk()->json('data');
    expect($t['points'])->toHaveCount(2)->and($t['points'][1]['limit'])->toEqual(85)->and($t['points'][1]['result'])->toBe('exceeds_limit');

    $this->get('/api/v1/mag/import/template', $this->h)->assertOk();
});
