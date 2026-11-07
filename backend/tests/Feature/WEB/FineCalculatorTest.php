<?php

// Calculadora de multas (SCR-WEB-28/41): sin tablas cargadas no calcula; con tablas, da el rango referencial en UIT y
// soles, guarda la foto de los parámetros y, si la persona deja su correo, entra a «Interesados».
// Los montos de esta prueba son inventados: solo prueban la mecánica, no son la escala legal.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

function fineTable(): array
{
    $sev = fn ($a, $b) => ['min' => $a, 'max' => $b];

    return [
        ['workers_from' => 1, 'workers_to' => 10, 'minor' => $sev(0.1, 0.2), 'serious' => $sev(0.3, 0.5), 'very_serious' => $sev(0.6, 1)],
        ['workers_from' => 11, 'workers_to' => null, 'minor' => $sev(0.2, 0.4), 'serious' => $sev(0.5, 0.8), 'very_serious' => $sev(1, 2)],
    ];
}

it('sin tablas no calcula y lo dice', function () {
    expect($this->getJson('/api/v1/public/tools/fine-calculator')->assertOk()->json('data.available'))->toBeFalse();
    $this->postJson('/api/v1/public/tools/fine-calculator', ['company_size' => 'micro', 'affected_workers' => 5, 'severity' => 'serious'])->assertStatus(409);
});

it('con la tabla cargada calcula el rango, guarda el cálculo y registra al interesado', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $tomorrow = now()->addDay()->toDateString();
    $bad = fineTable();
    $bad[1]['workers_from'] = 5;   // se cruza con el primer tramo
    $this->postJson('/api/v1/internal/site/fine-calculator/micro', ['value' => ['rows' => $bad], 'valid_from' => $tomorrow, 'reason' => 'Prueba de tabla'], $h)->assertStatus(422);
    $this->postJson('/api/v1/internal/site/fine-calculator/micro', ['value' => ['rows' => fineTable()], 'valid_from' => $tomorrow, 'reason' => 'Prueba de tabla'], $h)->assertCreated();
    // Para la prueba la nueva versión rige desde hoy.
    DB::connection('platform')->update("update legal_parameters set valid_from = current_date where key = 'web.fine_table_micro' and valid_from = ?", [$tomorrow]);
    DB::connection('platform')->update("update legal_parameters set valid_to = current_date - 1 where key = 'web.fine_table_micro' and valid_from < current_date");

    expect($this->getJson('/api/v1/public/tools/fine-calculator')->json('data'))->toMatchArray(['available' => true, 'to_confirm' => true]);
    $r = $this->postJson('/api/v1/public/tools/fine-calculator', ['company_size' => 'micro', 'affected_workers' => 25, 'severity' => 'serious'])->assertOk()->json('data');
    $uit = (float) $r['uit_value'];
    expect((float) $r['result_min_uit'])->toBe(0.5)->and((float) $r['result_max_pen'])->toBe(round(0.8 * $uit, 2));

    $this->postJson('/api/v1/public/tools/fine-calculator', ['company_size' => 'micro', 'affected_workers' => 3, 'severity' => 'minor', 'email' => 'luz@ejemplo.test', 'full_name' => 'Luz Prueba'])
        ->assertStatus(422)->assertJsonValidationErrors('privacy_consent');
    $this->postJson('/api/v1/public/tools/fine-calculator', ['company_size' => 'micro', 'affected_workers' => 3, 'severity' => 'minor', 'email' => 'luz@ejemplo.test', 'full_name' => 'Luz Prueba', 'privacy_consent' => true])
        ->assertOk()->assertJsonPath('data.lead_registered', true);
    expect(DB::connection('platform')->selectOne("select source from marketing_leads where email = 'luz@ejemplo.test'")->source)->toBe('fine_calculator');

    $panel = $this->getJson('/api/v1/internal/site/fine-calculator', $h)->assertOk()->json('data');
    expect($panel['stats']['total'])->toBeGreaterThanOrEqual(2)->and($panel['params']['micro']['current']['to_confirm'])->toBeTrue();
});
