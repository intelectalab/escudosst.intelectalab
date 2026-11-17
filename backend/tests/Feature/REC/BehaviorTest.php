<?php

// M-OBS SCR-OBS-01/02/03: lista de comportamientos con versiones, captura rápida sin identidad del observado y tendencias.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'OBS' and deleted_at is null", [HUAYLAS_ID]);
    DB::select("select set_config('app.company_id', '', false)");
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

it('versiona la lista de comportamientos, registra la observación y calcula el % seguro', function () {
    $this->postJson('/api/v1/obs/lists', ['items' => [['category' => 'ppe', 'behavior' => 'Usa casco']]], $this->h)->assertOk();
    $v1 = $this->postJson('/api/v1/obs/lists/publish', [], $this->h)->assertOk()->json('data.version_number');
    $this->postJson('/api/v1/obs/lists', ['items' => [['category' => 'ppe', 'behavior' => 'Usa casco'], ['category' => 'housekeeping', 'behavior' => 'Zona despejada']], 'change_summary' => 'Agrega orden'], $this->h)->assertOk();
    $v2 = $this->postJson('/api/v1/obs/lists/publish', [], $this->h)->assertOk()->json('data.version_number');
    expect($v2)->toBe($v1 + 1);
    $l = $this->getJson('/api/v1/obs/lists', $this->h)->assertOk()->json('data');
    expect($l['current']['version_number'])->toBe($v2)->and($l['current']['items'])->toHaveCount(2)->and(collect($l['history'])->pluck('version_number'))->toContain($v1);
    $this->postJson('/api/v1/obs/lists/publish', [], $this->h)->assertStatus(409);

    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'OBS-'.random_int(1000, 99999), 'name' => 'Sede observación'], $this->h)->assertCreated()->json('data.id');
    $items = [['category' => 'ppe', 'behavior' => 'Usa casco', 'result' => 'safe'], ['category' => 'housekeeping', 'behavior' => 'Zona despejada', 'result' => 'at_risk']];
    $this->postJson('/api/v1/obs/observe', ['site_id' => $site, 'feedback_given' => true, 'items' => [['category' => 'ppe', 'behavior' => 'Usa casco', 'result' => 'not_observed']]], $this->h)->assertStatus(422);
    $o = $this->postJson('/api/v1/obs/observe', ['site_id' => $site, 'feedback_given' => true, 'items' => $items], $this->h)->assertCreated()->json('data');
    expect($o['safe'])->toBe(1)->and($o['at_risk'])->toBe(1);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $row = DB::selectOne('select status, observed_worker_id, behavior_list_id from behavior_observations where id = ?', [$o['id']]);
    DB::select("select set_config('app.company_id', '', false)");
    expect($row->status)->toBe('submitted')->and($row->observed_worker_id)->toBeNull()->and($row->behavior_list_id)->toBe($l['current']['id']);

    $t = $this->getJson('/api/v1/obs/trends?year='.date('Y'), $this->h)->assertOk()->json('data');
    expect(collect($t['by_category'])->pluck('key'))->toContain('ppe', 'housekeeping')->and($t['observations'])->toBeGreaterThanOrEqual(1);
});
