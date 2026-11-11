<?php

// Búsqueda global de la cabecera (docs/07-pantallas-y-ux.md §2): módulos, trabajadores y acciones correctivas.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

it('encuentra módulos, trabajadores por nombre o DNI exacto y acciones correctivas', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '71717172', 'first_name' => 'Zenobia', 'paternal_last_name' => 'Buscable'], $h)
        ->assertCreated()->json('data.id');
    $me = DB::selectOne('select id from app_auth_lookup_user(?)', [CORPORATIVO_EMAIL])->id;
    $a = $this->postJson('/api/v1/records/ACR/corrective_actions', ['source_type' => 'other', 'description' => 'x', 'title' => 'Señalizar zona buscable',
        'responsible_user_id' => $me, 'due_on' => now('America/Lima')->addDays(5)->toDateString()], $h)
        ->assertCreated()->json('data.id');

    $byName = $this->getJson('/api/v1/search?q=buscable', $h)->assertOk();
    expect(collect($byName->json('data.workers'))->pluck('id'))->toContain($w)
        ->and(collect($byName->json('data.corrective_actions'))->pluck('id'))->toContain($a)
        ->and($byName->json('data.workers.0'))->not->toHaveKey('document_number');

    expect(collect($this->getJson('/api/v1/search?q=71717172', $h)->assertOk()->json('data.workers'))->pluck('id')->all())->toBe([$w])
        ->and($this->getJson('/api/v1/search?q=7171', $h)->json('data.workers'))->toBe([]);

    expect(collect($this->getJson('/api/v1/search?q=trabajadores', $h)->assertOk()->json('data.modules'))->pluck('code'))->toContain('TRB');
});

it('no busca con menos de 2 letras y exige sesión y empresa', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->getJson('/api/v1/search?q=a', $h)->assertOk()->assertExactJson(['data' => ['modules' => [], 'workers' => [], 'corrective_actions' => []]]);
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/search?q=trabajadores')->assertStatus(401);
});
