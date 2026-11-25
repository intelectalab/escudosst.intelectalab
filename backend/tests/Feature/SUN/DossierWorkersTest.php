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
    $r = sunBuild($d, ['worker_ids' => [$a, $b]], $h);
    expect(collect($r->json('data.workers'))->pluck('id')->all())->toBe([$a, $b]);
    $items = collect($r->json('data.items'));
    expect($items->where('worker_id', $a)->count())->toBeGreaterThanOrEqual(6)
        ->and($items->where('worker_id', $b)->count())->toBe($items->where('worker_id', $a)->count());

    // Guardado en el alcance: «Volver a armar» sin trabajadores enviados conserva los dos.
    $again = sunBuild($d, [], $h);
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
    sunBuild($d, ['worker_ids' => []], $h)->assertJsonCount(0, 'data.workers');
    expect(collect($this->getJson("/api/v1/sun/dossiers/{$d}/print", $h)->json('data.sections'))->pluck('title')->all())->toBe(['Empresa']);
});

it('sigue aceptando un solo trabajador con worker_id', function () {
    $h = sdwH();
    $a = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '94949403', 'first_name' => 'Ciro', 'paternal_last_name' => 'Solo'], $h)->assertCreated()->json('data.id');
    $d = $this->postJson('/api/v1/records/SUN/inspection_dossiers', ['title' => 'Un trabajador', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31'], $h)->assertCreated()->json('data.id');
    sunBuild($d, ['worker_id' => $a], $h)->assertJsonPath('data.worker.id', $a)->assertJsonCount(1, 'data.workers');
    sunBuild($d, ['worker_id' => null], $h)->assertJsonPath('data.worker', null);
});

it('usa el IPERC del puesto que tuvo el trabajador en cada tramo del periodo', function () {
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $area = DB::selectOne('select id from areas where company_id = ? and deleted_at is null limit 1', [ANDINAS_ID])->id;
    $site = DB::selectOne('select site_id from areas where id = ?', [$area])->site_id;
    $tag = substr(md5(uniqid()), 0, 4);
    $p1 = $this->postJson('/api/v1/records/TEN/positions', ['code' => 'PA'.$tag, 'name' => 'Ayudante '.$tag, 'area_id' => $area], $h)->assertCreated()->json('data.id');
    $p2 = $this->postJson('/api/v1/records/TEN/positions', ['code' => 'PB'.$tag, 'name' => 'Operario '.$tag, 'area_id' => $area], $h)->assertCreated()->json('data.id');
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(70000000, 79999999), 'first_name' => 'Cambio', 'paternal_last_name' => 'Puesto',
        'hire_on' => '2026-01-10', 'current_site_id' => $site, 'current_area_id' => $area, 'current_position_id' => $p1], $h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/TRB/workers/{$w}", ['current_position_id' => $p2], $h)->assertOk();

    $d = $this->postJson('/api/v1/records/SUN/inspection_dossiers', ['title' => 'Por puesto', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31'], $h)->assertCreated()->json('data.id');
    sunBuild($d, ['worker_id' => $w], $h);
    $w6 = collect($this->getJson("/api/v1/sun/dossiers/{$d}/items", $h)->json('data'))->where('code', 'W6')->pluck('title')->values()->all();
    expect($w6)->toHaveCount(2)->and($w6[0])->toContain('Ayudante '.$tag)->and($w6[1])->toContain('Operario '.$tag);
});
