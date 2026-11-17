<?php

// M-EST: horas sugeridas por turno, importación, validación obligatoria para cerrar, parámetros de la empresa, conciliación y ajustes.

use App\Core\Auth\Permissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'EST' and deleted_at is null", [HUAYLAS_ID]);
    DB::select("select set_config('app.company_id', '', false)");
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

it('sugiere, importa y valida horas-hombre, cambia parámetros y ajusta un mes cerrado con motivo', function () {
    $code = 'ES'.random_int(1000, 99999);
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => $code, 'name' => 'Sede estadística 2'], $this->h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::insert("insert into work_shifts (company_id, site_id, code, name, start_time, end_time, hours_per_day, days_pattern, status) values (?, ?, ?, 'Diurno', '08:00', '17:00', 8, 'Lun-Vie', 'active')", [HUAYLAS_ID, $site, 'T'.random_int(1000, 99999)]);
    DB::select("select set_config('app.company_id', '', false)");

    // Junio de 2031: 21 días de lunes a viernes.
    $s = collect($this->getJson('/api/v1/est/suggest?year=2031&month=6', $this->h)->assertOk()->json('data.sites'))->firstWhere('site_id', $site);
    expect($s['days'])->toBe(21)->and($s['hours_per_day'])->toEqual(8);

    $csv = "Código de sede,Año,Mes,Trabajadores,Horas trabajadas\n{$code},2031,6,10,1680\nNOEXISTE,2031,6,1,8\n";
    $f = fn () => UploadedFile::fake()->createWithContent('horas.csv', $csv);
    $p = $this->post('/api/v1/est/man-hours/import', ['file' => $f()], $this->h + ['Accept' => 'application/json'])->assertOk()->json('data');
    expect($p['valid'])->toBe(1)->and($p['total'])->toBe(2);
    $this->post('/api/v1/est/man-hours/import', ['file' => $f(), 'apply' => 1], $this->h + ['Accept' => 'application/json'])->assertOk();
    $rec = $this->getJson('/api/v1/est/months/2031/6/reconcile', $this->h)->assertOk()->json('data');
    $mh = collect($rec['man_hours'])->firstWhere('site', 'Sede estadística 2');
    expect($mh['validated_at'])->toBeNull();
    $this->postJson("/api/v1/est/man-hours/{$mh['id']}/validate", [], $this->h)->assertOk();

    $par = collect($this->getJson('/api/v1/est/params', $this->h)->assertOk()->json('data'))->keyBy('key');
    expect($par)->toHaveKeys(['frequency_index_factor', 'include_contractors_in_stats', 'lost_days_allocation']);
    $this->postJson('/api/v1/est/params', ['key' => 'lost_days_allocation', 'value' => 'otra'], $this->h)->assertStatus(422);
    $this->postJson('/api/v1/est/params', ['key' => 'frequency_index_factor', 'value' => '200000'], $this->h)->assertOk();
    expect(collect($this->getJson('/api/v1/est/params', $this->h)->json('data'))->firstWhere('key', 'frequency_index_factor')['own'])->toBeTrue();

    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $ms = DB::selectOne("insert into monthly_statistics (company_id, period_year, period_month, site_id, worker_count, hours_worked, accidents_fatal, accidents_disabling, lost_days,
        frequency_index, severity_index, accident_rate_index, frequency_factor, severity_factor, accident_rate_divisor, is_closed, closed_at)
        values (?, 2031, 6, ?, 10, 1680, 0, 1, 2, 595.24, 1190.48, 708.62, 1000000, 1000000, 1000, true, now()) returning id", [HUAYLAS_ID, $site])->id;
    DB::select("select set_config('app.company_id', '', false)");
    $this->postJson("/api/v1/est/months/{$ms}/adjust", ['lost_days' => 5], $this->h)->assertStatus(422);
    $a = $this->postJson("/api/v1/est/months/{$ms}/adjust", ['lost_days' => 5, 'reason' => 'El descanso médico se amplió'], $this->h)->assertOk()->json('data');
    expect($a['lost_days'])->toBe(5)->and($a['severity_index'])->toEqual(round(5 * 1000000 / 1680, 2))->and($a['adjustments'][0]['reason'])->toBe('El descanso médico se amplió');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update legal_parameters set deleted_at = now() where company_id = ? and key = 'frequency_index_factor' and deleted_at is null", [HUAYLAS_ID]);
    DB::select("select set_config('app.company_id', '', false)");
});
