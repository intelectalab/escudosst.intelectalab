<?php

// Expediente con varios trabajadores inspeccionados (M-SUN SCR-SUN-03/04): una sección por trabajador después de los
// puntos de la empresa, en el índice y en el PDF único foliado.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function sdwH(): array
{
    return authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
}

it('arma el expediente con varios trabajadores y una sección por cada uno', function () {
    $h = sdwH();
    $a = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '94949401', 'first_name' => 'Ana', 'paternal_last_name' => 'Inspeccionada'], $h)->assertCreated()->json('data.id');
    $b = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '94949402', 'first_name' => 'Beto', 'paternal_last_name' => 'Inspeccionado'], $h)->assertCreated()->json('data.id');
    $d = $this->postJson('/api/v1/records/SUN/inspection_dossiers', ['title' => 'Expediente de dos trabajadores', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31'], $h)
        ->assertCreated()->json('data.id');

    $this->postJson("/api/v1/sun/dossiers/{$d}/build", ['worker_ids' => [$a, 'x']], $h)->assertStatus(422)->assertJsonValidationErrors('worker_ids');
    $r = $this->postJson("/api/v1/sun/dossiers/{$d}/build", ['worker_ids' => [$a, $b]], $h)->assertOk();
    expect(collect($r->json('data.workers'))->pluck('id')->all())->toBe([$a, $b]);
    $items = collect($r->json('data.items'));
    expect($items->where('worker_id', $a)->count())->toBeGreaterThanOrEqual(6)
        ->and($items->where('worker_id', $b)->count())->toBe($items->where('worker_id', $a)->count());

    // Guardado en el alcance: «Volver a armar» sin trabajadores enviados conserva los dos.
    $again = $this->postJson("/api/v1/sun/dossiers/{$d}/build", [], $h)->assertOk();
    expect($again->json('data.workers'))->toHaveCount(2);
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    expect(json_decode(DB::selectOne('select scope from inspection_dossiers where id = ?', [$d])->scope, true)['worker_ids'])->toBe([$a, $b]);

    // PDF único: secciones Empresa → Ana → Beto, con su primer folio, y los folios siguen correlativos.
    $p = $this->getJson("/api/v1/sun/dossiers/{$d}/print", $h)->assertOk()->json('data');
    $sections = collect($p['sections']);
    expect($sections->pluck('title')->all())->toBe(['Empresa', 'Trabajador: Ana Inspeccionada', 'Trabajador: Beto Inspeccionado']);
    $first = collect($p['items'])->firstWhere('section', $b);
    expect($sections[2]['first_folio'])->toBe($first['folio'])
        ->and($sections->sum('count'))->toBe(count($p['items']))
        ->and(collect($p['items'])->pluck('folio')->unique()->count())->toBe(count($p['items']));

    // Lista vacía: vuelve al expediente de la empresa.
    $this->postJson("/api/v1/sun/dossiers/{$d}/build", ['worker_ids' => []], $h)->assertOk()->assertJsonCount(0, 'data.workers');
    expect(collect($this->getJson("/api/v1/sun/dossiers/{$d}/print", $h)->json('data.sections'))->pluck('title')->all())->toBe(['Empresa']);
});

it('sigue aceptando un solo trabajador con worker_id', function () {
    $h = sdwH();
    $a = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '94949403', 'first_name' => 'Ciro', 'paternal_last_name' => 'Solo'], $h)->assertCreated()->json('data.id');
    $d = $this->postJson('/api/v1/records/SUN/inspection_dossiers', ['title' => 'Un trabajador', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31'], $h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/sun/dossiers/{$d}/build", ['worker_id' => $a], $h)->assertOk()->assertJsonPath('data.worker.id', $a)->assertJsonCount(1, 'data.workers');
    $this->postJson("/api/v1/sun/dossiers/{$d}/build", ['worker_id' => null], $h)->assertOk()->assertJsonPath('data.worker', null);
});
