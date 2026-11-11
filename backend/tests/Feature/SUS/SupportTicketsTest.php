<?php

// Consultas al equipo de EscudoSST: la empresa escribe desde Ayuda; el equipo responde desde el panel.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

afterEach(function () {
    DB::connection('pgsql_owner')->delete("delete from support_tickets where subject like 'PRUEBA %'");
});

it('la empresa escribe al equipo, el equipo responde y la empresa ve la respuesta', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->postJson('/api/v1/me/support-tickets', ['subject' => 'PR', 'message' => 'x'], $h)
        ->assertStatus(422)->assertJsonPath('errors.subject.0', 'El asunto debe tener al menos 3 caracteres.');
    $t = $this->postJson('/api/v1/me/support-tickets', ['topic' => 'question', 'subject' => 'PRUEBA ¿Cómo cargo trabajadores?', 'message' => 'Quiero subir un Excel con mi personal.', 'page' => '/m/trb'], $h)
        ->assertCreated()->json('data');
    expect($t['code'])->toStartWith('CON-')->and($t['status'])->toBe('open');

    // Otra empresa no la ve.
    app('auth')->forgetGuards();
    expect(collect($this->getJson('/api/v1/me/support-tickets', authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID))->assertOk()->json('data'))->pluck('id'))->not->toContain($t['id']);

    // El equipo la ve en su bandeja y responde.
    app('auth')->forgetGuards();
    $admin = authHeaders(tokenFor(ADMIN_EMAIL));
    $inbox = $this->getJson('/api/v1/internal/support-tickets?status=open', $admin)->assertOk()->json('data');
    expect(collect($inbox)->firstWhere('id', $t['id'])['company'])->toBe('Constructora Huaylas');
    $this->postJson("/api/v1/internal/support-tickets/{$t['id']}/reply", ['reply' => 'Ve a Trabajadores › Cargar desde Excel.'], $admin)
        ->assertOk()->assertJsonPath('data.status', 'answered');

    app('auth')->forgetGuards();
    $mine = collect($this->getJson('/api/v1/me/support-tickets', $h)->assertOk()->json('data'))->firstWhere('id', $t['id']);
    expect($mine['reply'])->toBe('Ve a Trabajadores › Cargar desde Excel.')->and($mine['status'])->toBe('answered');

    // Una empresa no puede usar la bandeja del equipo.
    $this->getJson('/api/v1/internal/support-tickets', $h)->assertStatus(403);
});
