<?php

// M-CH5 SCR-CH5-01/02: registro rápido de la charla con los trabajadores del área y cobertura del día por área (RN-CH5-05).

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'CH5' and deleted_at is null", [HUAYLAS_ID]);
    DB::select("select set_config('app.company_id', '', false)");
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

it('registra la charla con los trabajadores del área y calcula la cobertura del día', function () {
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'CH5-'.random_int(1000, 99999), 'name' => 'Sede charlas'], $this->h)->assertCreated()->json('data.id');
    $area = $this->postJson('/api/v1/records/TEN/areas', ['site_id' => $site, 'code' => 'A'.random_int(1000, 99999), 'name' => 'Área charlas'], $this->h)->assertCreated()->json('data.id');
    $ids = [];
    foreach (['Ana', 'Beto', 'Ciro'] as $n) {
        $ids[] = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(76000000, 76999999), 'first_name' => $n, 'paternal_last_name' => 'Charla',
            'current_site_id' => $site, 'current_area_id' => $area], $this->h)->assertCreated()->json('data.id');
    }
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update('update workers set current_site_id = ?, current_area_id = ? where id = any(?::uuid[])', [$site, $area, '{'.implode(',', $ids).'}']);
    DB::select("select set_config('app.company_id', '', false)");

    $list = $this->getJson("/api/v1/ch5/area-workers?area_id={$area}", $this->h)->assertOk()->json('data');
    expect($list)->toHaveCount(3);

    $this->postJson('/api/v1/ch5/quick', ['topic' => 'Orden y limpieza', 'talk_on' => '2031-05-05', 'site_id' => $site, 'area_id' => $area, 'worker_ids' => []], $this->h)->assertStatus(422);
    $r = $this->postJson('/api/v1/ch5/quick', ['topic' => 'Orden y limpieza', 'talk_on' => '2031-05-05', 'site_id' => $site, 'area_id' => $area, 'duration_minutes' => 5,
        'worker_ids' => [$ids[0], $ids[1]]], $this->h)->assertCreated()->json('data');
    expect($r['attendees'])->toBe(2);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne('select status from safety_talks where id = ?', [$r['id']])->status)->toBe('held');
    DB::select("select set_config('app.company_id', '', false)");

    $cov = collect($this->getJson('/api/v1/ch5/coverage?date=2031-05-05', $this->h)->assertOk()->json('data.areas'))->firstWhere('id', $area);
    expect($cov['workers'])->toBe(3)->and($cov['attended'])->toBe(2)->and($cov['pct'])->toBe(67)->and($cov['talks'])->toBe(1);
});
