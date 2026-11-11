<?php

// Pestaña «Historial» de la ficha (docs/07 §3.2): la línea de tiempo del registro sale de la bitácora.

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

it('muestra quién creó y cambió el registro, con los estados en español', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $id = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'HIS-1', 'name' => 'Sede historial'], $h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/TEN/sites/{$id}", ['name' => 'Sede historial 2', 'status' => 'inactive'], $h)->assertOk();

    $rows = $this->getJson("/api/v1/records/TEN/sites/{$id}/history", $h)->assertOk()->json('data');
    expect(collect($rows)->pluck('action')->all())->toContain('create', 'update');
    $update = collect($rows)->firstWhere('action', 'update');
    $name = collect($update['changes'])->firstWhere('to', 'Sede historial 2');
    expect($name['from'])->toBe('Sede historial')
        ->and($update['actor'])->toBe('Carlos Corporativo Huamán');

    // Otra empresa no ve el historial (el registro no existe para ella).
    app('auth')->forgetGuards();
    $this->getJson("/api/v1/records/TEN/sites/{$id}/history", authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID))->assertNotFound();
});
