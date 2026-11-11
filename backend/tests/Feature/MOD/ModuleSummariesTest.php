<?php

// Resúmenes de las pantallas de módulo: autodiagnóstico SUNAFIL, árbol de causas, matriz por cargo, requisitos del
// fotocheck, vista previa de registros obligatorios y tabla mensual de estadísticas, todos con datos reales.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function mods(): array
{
    return authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
}

/** Evento SST en una sede nueva (el registro de eventos pide sede). */
function modEvent($test, string $description, string $at): string
{
    $site = $test->postJson('/api/v1/records/TEN/sites', ['code' => 'MOD-'.substr(md5($description), 0, 6), 'name' => 'Sede '.$description], mods())->json('data.id');
    $res = $test->postJson('/api/v1/records/ACC/sst_events', ['event_type' => 'minor_accident', 'occurred_at' => $at, 'site_id' => $site, 'description' => $description], mods());
    expect($res->status())->toBe(201, (string) $res->getContent());

    return $res->json('data.id');
}

it('calcula el autodiagnóstico SUNAFIL por áreas con semáforo y enlace al módulo', function () {
    $d = $this->getJson('/api/v1/sun/diagnosis', mods())->assertOk()->json('data');
    expect($d['areas'])->toHaveCount(7)
        ->and(collect($d['areas'])->pluck('name')->all())->toContain('Comité SST', 'IPERC', 'Registros obligatorios', 'Capacitación', 'EMO', 'Política y RISST', 'EPP');
    foreach ($d['areas'] as $a) {
        expect($a)->toHaveKeys(['pct', 'tone', 'detail', 'module', 'enabled']);
        if ($a['pct'] !== null) {
            expect($a['pct'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100);
        }
    }
    // Generar un registro obligatorio sube (o mantiene) el área «Registros obligatorios».
    $before = collect($d['areas'])->firstWhere('name', 'Registros obligatorios')['pct'];
    $this->get('/api/v1/reg/audits.xlsx?from=2026-01-01&to=2026-12-31', mods())->assertOk();
    $after = collect($this->getJson('/api/v1/sun/diagnosis', mods())->json('data.areas'))->firstWhere('name', 'Registros obligatorios')['pct'];
    expect($after)->toBeGreaterThanOrEqual($before)->toBeGreaterThan(0);
});

it('arma el árbol de causas con causa padre y el equipo investigador', function () {
    $h = mods();
    $ev = modEvent($this, 'Corte en la mano', '2026-05-10T10:00:00-05:00');
    $inv = $this->postJson('/api/v1/records/INV/investigations', ['event_id' => $ev, 'method' => 'cause_tree'], $h)->assertCreated()->json('data.id');
    $p = $this->postJson('/api/v1/records/INV/investigation_causes', ['investigation_id' => $inv, 'cause_level' => 'immediate', 'description' => 'No había guarda'], $h)
        ->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/INV/investigation_causes', ['investigation_id' => $inv, 'parent_cause_id' => $p, 'cause_level' => 'basic',
        'description' => 'El PETS no exige verificar guardas'], $h)->assertCreated();
    $this->postJson('/api/v1/records/INV/investigation_team', ['investigation_id' => $inv, 'external_name' => 'Ana Líder', 'role' => 'leader'], $h)->assertCreated();

    $t = $this->getJson("/api/v1/inv/investigations/{$inv}/tree", $h)->assertOk()->json('data');
    expect($t['investigation']['event_description'])->toBe('Corte en la mano')
        ->and($t['causes'])->toHaveCount(2)
        ->and(collect($t['causes'])->firstWhere('cause_level', 'basic')['parent_cause_id'])->toBe($p)
        ->and($t['team'][0]['name'])->toBe('Ana Líder');
    $this->getJson('/api/v1/inv/investigations/01900000-0000-7000-8000-00000000ffff/tree', $h)->assertNotFound();
});

it('devuelve la matriz por cargo y sus indicadores', function () {
    $m = $this->getJson('/api/v1/cap/matrix', mods())->assertOk()->json('data');
    expect($m)->toHaveKeys(['positions', 'trainings', 'cells', 'kpis'])
        ->and($m['kpis'])->toHaveKeys(['hours_per_worker', 'matrix_pct', 'certificates_pending', 'certificates_expiring']);
    foreach ($m['cells'] as $c) {
        expect($c['done'])->toBeLessThanOrEqual($c['total']);
    }
});

it('evalúa los requisitos del fotocheck de un trabajador sin datos médicos', function () {
    $h = mods();
    $this->getJson('/api/v1/fot/summary', $h)->assertOk()->assertJsonStructure(['data' => ['kpis' => ['active', 'expiring', 'not_enabled', 'verifications_month'], 'types']]);
    $type = $this->postJson('/api/v1/records/FOT/credential_types', ['code' => 'FOT-T1', 'name' => 'Fotocheck de prueba', 'applies_to' => 'workers'], $h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/FOT/credential_requirements', ['credential_type_id' => $type, 'requirement_type' => 'emo_fitness',
        'description' => 'Aptitud médica', 'is_blocking' => true], $h)->assertCreated();
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '47474747', 'first_name' => 'Luis', 'paternal_last_name' => 'Fotocheck'], $h)->json('data.id');

    $e = $this->getJson("/api/v1/fot/evaluate?worker_id={$w}&credential_type_id={$type}", $h)->assertOk()->json('data');
    expect($e['overall'])->toBe('bad')->and($e['requirements'][0]['status'])->toBe('bad')
        ->and($e['requirements'][0]['detail'])->toBe('Examen médico pendiente')->and($e['worker']['name'])->toContain('Luis');
    $this->getJson('/api/v1/fot/evaluate?worker_id=x', $h)->assertNotFound();
});

it('muestra la vista previa de un registro obligatorio sin dejarla en el historial', function () {
    $h = mods();
    modEvent($this, 'Golpe en vista previa', '2026-03-10T10:00:00-05:00');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $before = DB::table('register_exports')->count();

    $p = $this->getJson('/api/v1/reg/accidents/preview?from=2026-01-01&to=2026-12-31&limit=500', $h)->assertOk()->json('data');
    expect($p['header'])->toContain('Descripción')->and($p['total'])->toBeGreaterThanOrEqual(1)
        ->and(collect($p['rows'])->flatten()->implode(' '))->toContain('Golpe en vista previa')
        ->and($p['company']['ruc'])->not->toBe('');
    expect(DB::table('register_exports')->count())->toBe($before);
    $this->getJson('/api/v1/reg/inventado/preview', $h)->assertNotFound();
    $this->getJson('/api/v1/reg/accidents/preview?from=2026-12-31&to=2026-01-01', $h)->assertStatus(400);
});

it('arma la tabla mensual de estadísticas con los índices de cada mes', function () {
    $h = mods();
    $this->postJson('/api/v1/records/EST/man_hours', ['period_year' => 2025, 'period_month' => 2, 'worker_count' => 50, 'hours_worked' => 10000], $h)->assertCreated();
    $s = $this->getJson('/api/v1/est/summary?year=2025', $h)->assertOk()->json('data');
    expect($s['months'])->toHaveCount(12);
    $feb = $s['months'][1];
    expect($feb['hours'])->toEqual(10000)->and($feb['workers'])->toBe(50)->and($feb['fi'])->not->toBeNull();
    $this->getJson('/api/v1/est/summary?year=1990', $h)->assertStatus(400);
});
