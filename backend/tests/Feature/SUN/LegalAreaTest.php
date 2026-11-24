<?php

// M-MRL §12 → M-SUN: la matriz de requisitos legales alimenta el autodiagnóstico. Área «Requisitos legales» con RN-MRL-06
// (cumplen en su última evaluación / aplicables evaluados); «requiere revisión» no cuenta como cumplido (RN-MRL-05).

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    DB::update('update legal_requirements set deleted_at = now() where deleted_at is null');
});

afterEach(function () {
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
});

function sunReq(?string $result, bool $review = false, ?string $nextOn = null, string $evaluatedOn = '2026-06-01'): string
{
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $norm = DB::selectOne('select id from legal_norms order by created_at limit 1')->id;
    $r = DB::selectOne("insert into legal_requirements (company_id, legal_norm_id, article, requirement, applies, status, needs_review, next_evaluation_on)
                        values (?, ?, 'Art. 1', 'Requisito de prueba', true, 'active', ?, ?) returning id", [ANDINAS_ID, $norm, $review, $nextOn])->id;
    if ($result) {
        DB::insert('insert into requirement_evaluations (company_id, legal_requirement_id, evaluated_on, result) values (?, ?, ?, ?)', [ANDINAS_ID, $r, $evaluatedOn, $result]);
    }

    return $r;
}

it('sin requisitos aplicables el área queda en 0 y lo explica', function () {
    $a = collect($this->getJson('/api/v1/sun/diagnosis', $this->h)->assertOk()->json('data.areas'))->firstWhere('code', 'legal');
    expect($a)->toMatchArray(['module' => 'MRL', 'pct' => 0, 'result' => 'fails'])->and($a['detail'])->toContain('No hay requisitos legales');
});

it('calcula el cumplimiento legal con la última evaluación y nombra las brechas', function () {
    $ok = sunReq('compliant');
    DB::insert("insert into requirement_evaluations (company_id, legal_requirement_id, evaluated_on, result) values (?, ?, '2026-01-01', 'non_compliant')", [ANDINAS_ID, $ok]);
    sunReq('non_compliant');
    sunReq('compliant', true);           // requiere revisión: no cuenta como cumplido
    sunReq(null, false, '2020-01-01');   // sin evaluar y con evaluación vencida
    sunReq('not_applicable');            // no aplica: fuera del cálculo

    $a = collect($this->getJson('/api/v1/sun/diagnosis', $this->h)->assertOk()->json('data.areas'))->firstWhere('code', 'legal');
    expect($a['name'])->toBe('Requisitos legales')->and($a['module'])->toBe('MRL')->and($a['pct'])->toBe(33)
        ->and($a['detail'])->toContain('1 de 3 requisitos evaluados cumplen')->toContain('1 con resultado parcial o no cumple')
        ->toContain('1 requieren revisión')->toContain('1 sin evaluar')->toContain('1 con evaluación vencida');
    expect($a)->toHaveKeys(['severity', 'severity_label', 'tone', 'enabled']);

    // El autodiagnóstico guardado incluye el área.
    $s = $this->postJson('/api/v1/sun/self-diagnostics', [], $this->h)->assertCreated()->json('data');
    $this->getJson("/api/v1/sun/self-diagnostics/{$s['id']}", $this->h)->assertOk();
    expect(collect($this->getJson("/api/v1/sun/self-diagnostics/{$s['id']}", $this->h)->json('data.areas'))->pluck('code')->all())->toContain('legal');
});
