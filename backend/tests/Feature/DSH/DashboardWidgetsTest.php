<?php

// M-DSH: tipos de widget del tablero (§5, SCR-DSH-03), historia por área (§5 kpi_snapshots.area_id) y KPI de salud por área con
// grupo pequeño agregado al nivel superior (RN-DSH-05, criterio 5).

use App\Modules\DSH\Services\KpiService;
use App\Modules\DSH\Services\KpiSnapshots;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

function dwAs(callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    try {
        return $fn();
    } finally {
        DB::select("select set_config('app.company_id', '', false)");
    }
}

it('guarda cómo se muestra cada indicador del tablero', function () {
    $id = $this->postJson('/api/v1/dsh/boards', ['name' => 'Gráficos '.random_int(1, 99999), 'kpi_codes' => ['inspection_compliance', 'training_compliance', 'emo_valid'],
        'widget_types' => ['inspection_compliance' => 'line', 'training_compliance' => 'traffic_light']], $this->h)->assertCreated()->json('data.id');
    $b = collect($this->getJson('/api/v1/dsh/boards', $this->h)->assertOk()->json('data.boards'))->firstWhere('id', $id);
    expect($b['widgets'])->toBe([['code' => 'inspection_compliance', 'type' => 'line'], ['code' => 'training_compliance', 'type' => 'traffic_light'], ['code' => 'emo_valid', 'type' => 'card']]);
    $this->postJson('/api/v1/dsh/boards', ['name' => 'X', 'kpi_codes' => ['emo_valid'], 'widget_types' => ['emo_valid' => 'pastel']], $this->h)->assertStatus(422);
    $this->deleteJson("/api/v1/dsh/boards/{$id}", [], $this->h)->assertOk();
});

it('guarda la historia de los indicadores por área y la entrega al pedirla', function () {
    $area = dwAs(fn () => DB::selectOne("select id from areas where deleted_at is null order by created_at limit 1")->id);
    app(KpiSnapshots::class)->forCompany(HUAYLAS_ID);
    $n = dwAs(fn () => (int) DB::selectOne('select count(*) as n from kpi_snapshots where area_id = ? and deleted_at is null', [$area])->n);
    expect($n)->toBeGreaterThan(0);
    $r = $this->getJson("/api/v1/dsh/history?area_id={$area}", $this->h)->assertOk()->json('data');
    expect($r['area_id'])->toBe($area)->and($r['series'])->not->toBeEmpty()->and(collect($r['areas'])->pluck('id'))->toContain($area);
    // Sin área: la historia de toda la empresa no mezcla filas de áreas.
    $all = $this->getJson('/api/v1/dsh/history', $this->h)->assertOk()->json('data');
    expect($all['area_id'])->toBeNull();
});

it('en un área con pocas personas, la aptitud médica se suma a la sede (criterio 5)', function () {
    dwAs(function () {
        $site = DB::selectOne("select id from sites where deleted_at is null and status = 'active' order by created_at limit 1")->id;
        $area = DB::selectOne("insert into areas (company_id, site_id, code, name) values (?, ?, ?, 'Área chica') returning id", [HUAYLAS_ID, $site, 'CH'.random_int(1000, 99999)])->id;
        for ($i = 0; $i < 3; $i++) {
            DB::insert("insert into workers (company_id, document_number, document_number_hash, first_name, paternal_last_name, status, current_site_id, current_area_id)
                        values (?, ?, decode(md5(random()::text), 'hex'), 'Trabajador', 'Chico', 'active', ?, ?)", [HUAYLAS_ID, (string) random_int(10000000, 99999999), $site, $area]);
        }
        $min = (int) \App\Core\Support\LegalParams::number('min_group_size', 5);
        // La sede del área tiene al menos min_group_size personas (las del área chica y otras sin área).
        for ($i = 0; $i < $min; $i++) {
            DB::insert("insert into workers (company_id, document_number, document_number_hash, first_name, paternal_last_name, status, current_site_id)
                        values (?, ?, decode(md5(random()::text), 'hex'), 'Trabajador', 'Sede', 'active', ?)", [HUAYLAS_ID, (string) random_int(10000000, 99999999), $site]);
        }
        $siteCount = (int) DB::selectOne("select count(*) as n from workers where deleted_at is null and status = 'active' and current_site_id = ?", [$site])->n;
        $kpi = collect((new KpiService)->forAreas([$area])->overview((int) date('Y'), null))->firstWhere('code', 'emo_valid');
        expect($kpi['value'])->not->toBeNull()->and($kpi['aggregated'] ?? false)->toBeTrue()->and($kpi['denominator'])->toBe($siteCount)
            ->and($kpi['basis'])->toContain('se suma al nivel superior');
    });
});
