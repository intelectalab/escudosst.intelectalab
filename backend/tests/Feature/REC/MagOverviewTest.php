<?php

// M-MAG: conversión de unidades (RN-MAG-04, criterio 3), mapa de puntos con semáforo (SCR-MAG-01) y calendario (SCR-MAG-02, RN-MAG-07).

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function magH(): array
{
    return authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
}

it('rechaza una medición en otra unidad sin conversión y la convierte guardando el original cuando hay una registrada', function () {
    $h = magH();
    $agent = collect($this->getJson('/api/v1/records/MAG/measurements/options/agent_type_id', $h)->json('data'))->first()['id'] ?? null;
    expect($agent)->not->toBeNull();
    $limit = $this->postJson('/api/v1/records/MAG/exposure_limits', ['agent_type_id' => $agent, 'substance_name' => 'Sustancia prueba '.random_int(1000, 99999), 'limit_type' => 'TWA',
        'limit_value' => 10, 'unit' => 'mg/m³', 'valid_from' => '2020-01-01'], $h);
    if ($limit->status() !== 201) {
        $this->markTestSkipped('La empresa no puede registrar límites propios.');
    }
    $limitId = $limit->json('data.id');
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'MAGC-'.random_int(100, 999), 'name' => 'Sede conversión'], $h)->json('data.id');
    $camp = $this->postJson('/api/v1/records/MAG/monitoring_campaigns', ['title' => 'Monitoreo químico'], $h)->assertCreated()->json('data.id');
    $point = $this->postJson('/api/v1/records/MAG/monitoring_points', ['site_id' => $site, 'agent_type_id' => $agent, 'code' => 'Q-'.random_int(100, 999), 'name' => 'Tanque',
        'latitude' => -12.05, 'longitude' => -77.04], $h)->assertCreated()->json('data.id');
    $body = ['campaign_id' => $camp, 'monitoring_point_id' => $point, 'agent_type_id' => $agent, 'exposure_limit_id' => $limitId,
        'measured_at' => now()->toIso8601String(), 'value' => 3, 'unit' => 'ppm'];

    // Criterio 3: ppm contra un límite en mg/m³ sin conversión registrada → rechazo.
    $this->postJson('/api/v1/records/MAG/measurements', $body, $h)->assertStatus(422)->assertJsonPath('errors.unit.0', fn ($m) => str_contains($m, 'no hay una conversión'));

    // Validaciones de la tabla de conversión.
    $this->postJson('/api/v1/mag/unit-conversions', ['agent_type_id' => $agent, 'from_unit' => 'ppm', 'to_unit' => 'mg/m³', 'factor' => 0, 'source' => 'x'], $h)->assertStatus(422);
    $conv = $this->postJson('/api/v1/mag/unit-conversions', ['agent_type_id' => $agent, 'from_unit' => 'ppm', 'to_unit' => 'mg/m³', 'factor' => 2.5, 'source' => 'Ficha técnica del laboratorio'], $h)
        ->assertCreated()->json('data.id');
    $this->postJson('/api/v1/mag/unit-conversions', ['agent_type_id' => $agent, 'from_unit' => 'PPM', 'to_unit' => 'mg/m³', 'factor' => 3, 'source' => 'otra'], $h)->assertStatus(409);
    expect(collect($this->getJson('/api/v1/mag/unit-conversions', $h)->assertOk()->json('data'))->pluck('id'))->toContain($conv);

    // Con la conversión: 3 ppm × 2,5 = 7,5 mg/m³ (nivel de acción), y se guarda el original.
    $m = $this->postJson('/api/v1/records/MAG/measurements', $body, $h)->assertCreated();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $row = DB::selectOne('select value, unit, original_value, original_unit, unit_conversion_id, result from measurements where id = ?', [$m->json('data.id')]);
    expect((float) $row->value)->toBe(7.5)->and($row->unit)->toBe('mg/m³')->and((float) $row->original_value)->toBe(3.0)
        ->and($row->original_unit)->toBe('ppm')->and($row->unit_conversion_id)->toBe($conv)->and($row->result)->toBe('action_level');

    // Se aprueba para que cuente en el mapa y en el calendario.
    $this->patchJson('/api/v1/records/MAG/measurements/'.$m->json('data.id'), ['status' => 'approved'], $h)->assertOk();

    // SCR-MAG-01: el punto aparece con su color y coordenadas.
    $p = collect($this->getJson('/api/v1/mag/points', $h)->assertOk()->json('data'))->firstWhere('id', $point);
    expect($p['color'])->toBe('amber')->and($p['lat'])->toBe(-12.05)->and($p['measurements'])->toHaveCount(1);

    // SCR-MAG-02: vuelve a vencer a los 12 meses de la medición aprobada.
    $next = now('America/Lima')->addMonths(12);
    $cal = $this->getJson('/api/v1/mag/calendar?year='.$next->year, $h)->assertOk()->json('data');
    expect(collect($cal['months'][$next->month - 1]['due'])->pluck('id'))->toContain($point);
    $this->getJson('/api/v1/mag/calendar?year=1500', $h)->assertStatus(400);

    // Se quita la conversión; la medición conserva el valor original.
    $this->deleteJson('/api/v1/mag/unit-conversions/'.$conv, [], $h)->assertOk();
    expect(collect($this->getJson('/api/v1/mag/unit-conversions', $h)->json('data'))->pluck('id'))->not->toContain($conv);
});
