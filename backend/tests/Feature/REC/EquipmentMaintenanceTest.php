<?php

// M-EQE RN-EQE-01/02/07/08: próximas fechas de recarga, prueba e inspección; vida útil; calendario y mínimo de extintores por sede.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'EQE' and deleted_at is null", [HUAYLAS_ID]);
    DB::select("select set_config('app.company_id', '', false)");
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

it('calcula las próximas fechas del extintor, lo deja fuera de servicio pasada su vida útil y muestra el calendario', function () {
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'EQ'.random_int(1000, 99999), 'name' => 'Sede extintores', 'min_extinguishers' => 2], $this->h)->assertCreated()->json('data.id');
    $e = $this->postJson('/api/v1/records/EQE/emergency_equipment', ['code' => 'EXT-'.random_int(1000, 99999), 'equipment_type' => 'fire_extinguisher', 'site_id' => $site,
        'last_recharge_on' => '2031-03-10', 'last_hydrostatic_test_on' => '2030-01-15'], $this->h)->assertCreated()->json('data');
    expect($e['next_recharge_on'])->toStartWith('2032-03-10')->and($e['next_hydrostatic_test_on'])->toStartWith('2035-01-15');

    $this->postJson('/api/v1/records/EQE/equipment_checks', ['equipment_id' => $e['id'], 'check_type' => 'monthly_inspection', 'checked_at' => '2031-04-01T10:00:00-05:00', 'result' => 'ok',
        'checklist' => [['item' => 'Presión', 'answer' => 'compliant']]], $this->h)->assertCreated();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $row = DB::selectOne('select next_inspection_on from emergency_equipment where id = ?', [$e['id']]);
    $chk = DB::selectOne('select checklist from equipment_checks where equipment_id = ? order by created_at desc limit 1', [$e['id']]);
    DB::select("select set_config('app.company_id', '', false)");
    expect($row->next_inspection_on)->toBe('2031-05-01')->and($chk->checklist)->toContain('Presión');

    $old = $this->postJson('/api/v1/records/EQE/emergency_equipment', ['code' => 'EXT-'.random_int(1000, 99999), 'equipment_type' => 'fire_extinguisher', 'site_id' => $site,
        'manufacture_date' => '1990-01-01'], $this->h)->assertCreated()->json('data');
    expect($old['status'])->toBe('out_of_service');

    $cal = $this->getJson('/api/v1/eqe/calendar?year=2032', $this->h)->assertOk()->json('data');
    expect(collect($cal['items'])->where('id', $e['id'])->pluck('kind'))->toContain('recharge');
    $s = collect($cal['sites'])->firstWhere('id', $site);
    expect($s['min_extinguishers'])->toBe(2)->and($s['gap'])->toBeTrue();
});
