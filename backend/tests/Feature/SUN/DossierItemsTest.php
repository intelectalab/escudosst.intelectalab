<?php

// M-SUN SCR-SUN-04: excluir ítems con motivo y reordenar (se conserva al rearmar); SCR-SUN-07: marca de agua en la vista del inspector.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $this->d = $this->postJson('/api/v1/records/SUN/inspection_dossiers', ['title' => 'Expediente con exclusiones', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31'], $this->h)
        ->assertCreated()->json('data.id');
    sunBuild($this->d, [], $this->h);
});

it('excluye un ítem con motivo y conserva la exclusión y el orden manual al rearmar', function () {
    $items = $this->getJson("/api/v1/sun/dossiers/{$this->d}/items", $this->h)->assertOk()->json('data');
    expect(count($items))->toBeGreaterThan(2);
    $first = $items[0];

    $this->patchJson("/api/v1/sun/dossiers/{$this->d}/items/{$first['id']}", ['excluded' => true], $this->h)->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->patchJson("/api/v1/sun/dossiers/{$this->d}/items/{$first['id']}", ['excluded' => true, 'reason' => 'No aplica a esta inspección'], $this->h)
        ->assertOk()->assertJsonPath('data.status', 'excluded');

    $ids = array_column($items, 'id');
    $this->putJson("/api/v1/sun/dossiers/{$this->d}/order", ['item_ids' => array_slice($ids, 1)], $this->h)->assertStatus(422);
    $reversed = array_reverse($ids);
    $this->putJson("/api/v1/sun/dossiers/{$this->d}/order", ['item_ids' => $reversed], $this->h)->assertOk();

    sunBuild($this->d, [], $this->h);
    $after = $this->getJson("/api/v1/sun/dossiers/{$this->d}/items", $this->h)->assertOk()->json('data');
    $byCode = collect($after)->keyBy('code');
    expect($byCode[$first['code']]['status'])->toBe('excluded')
        ->and($byCode[$first['code']]['exclusion_reason'])->toBe('No aplica a esta inspección')
        ->and($after[0]['code'])->toBe(collect($items)->firstWhere('id', $reversed[0])['code']);

    // Volver a incluirlo.
    $again = $byCode[$first['code']]['id'];
    $this->patchJson("/api/v1/sun/dossiers/{$this->d}/items/{$again}", ['excluded' => false], $this->h)->assertOk()->assertJsonPath('data.exclusion_reason', null);
});

it('la vista del inspector trae la marca de agua con empresa, expediente y fecha', function () {
    $s = $this->postJson("/api/v1/sun/dossiers/{$this->d}/share", ['days' => 2, 'max_views' => 3, 'recipient_name' => 'Inspector'], $this->h)->assertCreated()->json('data');
    approveShareLink($s['id'], ANDINAS_ID);
    [$company, $token] = array_slice(explode('/', $s['url']), -2);
    $w = $this->getJson("/api/v1/public/sun/{$company}/{$token}")->assertOk()->json('data.watermark');
    expect($w)->toContain('Expediente con exclusiones')->and($w)->toMatch('/\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}$/');
});

it('agrega una evidencia manual con su archivo y la conserva al volver a armar', function () {
    $this->postJson("/api/v1/sun/dossiers/{$this->d}/evidence", ['title' => 'Constancia'], $this->h)->assertStatus(422)->assertJsonValidationErrors('file_id');
    $file = evidenceFile($this->h, 'SUN');
    $e = $this->postJson("/api/v1/sun/dossiers/{$this->d}/evidence", ['title' => 'Constancia de entrega en papel', 'file_id' => $file], $this->h)->assertCreated()->json('data');
    expect($e['code'])->toBe('M1');
    sunBuild($this->d, [], $this->h);
    $item = collect($this->getJson("/api/v1/sun/dossiers/{$this->d}/items", $this->h)->json('data'))->firstWhere('id', $e['id']);
    expect($item)->not->toBeNull()->and($item['status'])->toBe('included')->and($item['title'])->toBe('Constancia de entrega en papel');
    $this->get("/api/v1/sun/dossiers/{$this->d}/zip", $this->h)->assertOk();
});

it('el autodiagnóstico lista por nombre a quienes faltan en la obligación', function () {
    $areas = collect($this->getJson('/api/v1/sun/diagnosis', $this->h)->assertOk()->json('data.areas'));
    expect($areas->every(fn ($a) => array_key_exists('workers', $a) && array_key_exists('workers_total', $a)))->toBeTrue();
    $ppe = $areas->firstWhere('code', 'ppe');
    if (($ppe['pct'] ?? 100) < 100 && $ppe['pct'] !== null && str_contains($ppe['detail'], 'sin todo')) {
        expect($ppe['workers'])->not->toBeEmpty();
    }
});

it('incluye los aportes de política, ATS, permisos, monitoreo, mapa, matriz legal, reportes, auditoría y revisión por la dirección', function () {
    $codes = collect($this->getJson("/api/v1/sun/dossiers/{$this->d}/items", $this->h)->assertOk()->json('data'))->pluck('code')->all();
    expect($codes)->toContain('19', '20', '21', '22', '23', '24', '25', '26', '27', '28');
});
