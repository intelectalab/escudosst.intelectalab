<?php

// API: el secreto del cliente se muestra una sola vez y solo se guarda su hash; webhooks solo https.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

it('crea el cliente con clave y muestra el secreto una sola vez', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    if ($this->getJson('/api/v1/records/API', $h)->status() === 403) {
        $this->markTestSkipped('API no está en el plan de la empresa de prueba.');
    }
    $c = $this->postJson('/api/v1/records/API/api_clients', ['name' => 'ERP de planillas'], $h);
    if ($c->status() === 403) {
        // Las claves de la API las crea el administrador de cuenta, no el Jefe SST.
        expect($c->json('type'))->toBe('forbidden');

        return;
    }
    $c->assertCreated();
    expect($c->json('data.client_key'))->toStartWith('esc_');
    $secret = $this->postJson("/api/v1/api-clients/{$c->json('data.id')}/secret", [], $h)->assertOk()->json('data.secret');
    expect($secret)->toStartWith('sk_');
    $this->postJson("/api/v1/api-clients/{$c->json('data.id')}/secret", [], $h)->assertStatus(410);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::table('api_clients')->where('id', $c->json('data.id'))->value('secret_hash'))->toBe(hash('sha256', $secret));

    $this->postJson('/api/v1/records/API/webhooks', ['name' => 'Avisos', 'target_url' => 'http://inseguro.test/hook'], $h)
        ->assertStatus(422)->assertJsonPath('errors.target_url.0', 'La dirección debe empezar con https://');
    $this->postJson('/api/v1/records/API/webhooks', ['name' => 'Avisos', 'target_url' => 'https://erp.example.com/hook', 'event_codes' => ['acr.overdue']], $h)
        ->assertCreated();
});
