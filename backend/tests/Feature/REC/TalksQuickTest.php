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
    // RN-CH5-01: con la lista queda programada; pasa a realizada con la primera firma.
    expect(DB::selectOne('select status from safety_talks where id = ?', [$r['id']])->status)->toBe('scheduled');
    DB::select("select set_config('app.company_id', '', false)");

    $cov = collect($this->getJson('/api/v1/ch5/coverage?date=2031-05-05', $this->h)->assertOk()->json('data.areas'))->firstWhere('id', $area);
    expect($cov['workers'])->toBe(3)->and($cov['attended'])->toBe(2)->and($cov['pct'])->toBe(67)->and($cov['talks'])->toBe(1);
});

it('sugiere temas del banco según los peligros significativos del IPERC del área y cuenta la charla como capacitación si se activa', function () {
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'TB-'.random_int(1000, 99999), 'name' => 'Sede temas'], $this->h)->assertCreated()->json('data.id');
    $area = $this->postJson('/api/v1/records/TEN/areas', ['site_id' => $site, 'code' => 'T'.random_int(1000, 99999), 'name' => 'Área temas'], $this->h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $crit = DB::selectOne('select id from risk_criteria where company_id = ? or company_id is null order by company_id nulls last limit 1', [HUAYLAS_ID])->id;
    $m = DB::selectOne("insert into iperc_matrices (company_id, code, title, risk_criteria_id, area_id) values (?, ?, 'IPERC temas', ?, ?) returning id", [HUAYLAS_ID, 'IPC-T-'.random_int(1000, 999999), $crit, $area])->id;
    $v = DB::selectOne("insert into iperc_versions (company_id, iperc_matrix_id, version_number, status, approved_at) values (?, ?, 1, 'current', now()) returning id", [HUAYLAS_ID, $m])->id;
    DB::update('update iperc_matrices set current_version_id = ? where id = ?', [$v, $m]);
    DB::insert("insert into iperc_rows (company_id, iperc_version_id, row_number, activity, task, hazard, risk, probability_value, severity_value, risk_level, is_significant)
        values (?, ?, 1, 'Montaje', 'Trabajo sobre andamio', 'Trabajo en altura sobre andamio', 'Caída', 3, 3, 'important', true)", [HUAYLAS_ID, $v]);
    DB::select("select set_config('app.company_id', '', false)");

    $t = $this->postJson('/api/v1/ch5/topics', ['title' => 'Uso del arnés', 'content' => 'Revisa el arnés antes de subir.', 'hazard_keywords' => ['altura', 'andamio'], 'duration_minutes' => 5], $this->h)
        ->assertCreated()->json('data.id');
    $this->postJson('/api/v1/ch5/topics', ['title' => 'Orden y limpieza', 'hazard_keywords' => ['tropiezo']], $this->h)->assertCreated();
    $b = $this->getJson("/api/v1/ch5/topics?area_id={$area}", $this->h)->assertOk()->json('data');
    expect(collect($b['suggested'])->pluck('id')->all())->toContain($t)->and(collect($b['suggested'])->pluck('title')->all())->not->toContain('Orden y limpieza');

    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(73000000, 73999999), 'first_name' => 'Eva', 'paternal_last_name' => 'Temas'], $this->h)->json('data.id');
    $q = $this->postJson('/api/v1/ch5/quick', ['topic' => 'Uso del arnés', 'topic_bank_id' => $t, 'talk_on' => '2031-06-02', 'site_id' => $site, 'area_id' => $area, 'duration_minutes' => 6, 'worker_ids' => [$w]], $this->h)
        ->assertCreated()->json('data.id');
    // El trabajador firmó desde su app: la charla quedó realizada.
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update safety_talks set status = 'held' where id = ?", [$q]);
    DB::select("select set_config('app.company_id', '', false)");
    $reg = fn () => collect($this->getJson('/api/v1/reg/training/preview?from=2031-06-01&to=2031-06-30&limit=500', $this->h)->assertOk()->json('data.rows'))->flatten()->implode(' ');
    expect($reg())->not->toContain('charla de 5 minutos');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::insert("insert into legal_parameters (company_id, key, value, value_type, description, valid_from, to_confirm) values (?, 'talks_count_as_training', 'true', 'boolean', 'Charlas como capacitación', '2012-04-25', false)", [HUAYLAS_ID]);
    DB::select("select set_config('app.company_id', '', false)");
    try {
        expect($reg())->toContain('Sensibilización (charla de 5 minutos)')->toContain('Uso del arnés');
    } finally {
        DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
        DB::update("update legal_parameters set deleted_at = now() where company_id = ? and key = 'talks_count_as_training' and deleted_at is null", [HUAYLAS_ID]);
        DB::select("select set_config('app.company_id', '', false)");
    }
});
