<?php

// M-REG: versiones generadas de los registros obligatorios. Bloqueo por campos obligatorios (RN-REG-02, criterio 1),
// datos congelados con huella y reemplazo de la versión anterior (RN-REG-03, criterios 3 y 4), empleador al corte
// (RN-REG-08) y firma del responsable con FIR (RN-REG-05) que deja la versión «firmada».

use Illuminate\Support\Facades\DB;

const REG_SIGNER = '01900000-0000-7000-8000-000000000303';

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

it('bloquea el registro de accidentes sin parte del cuerpo y genera, firma y reemplaza versiones', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'REG-V', 'name' => 'Sede versiones'], $h)->json('data.id');
    $event = $this->postJson('/api/v1/records/ACC/sst_events', ['event_type' => 'minor_accident', 'occurred_at' => '2025-10-08T09:00:00-05:00', 'site_id' => $site,
        'description' => 'Corte leve con cúter'], $h)->assertCreated()->json('data.id');
    // Lesionado cargado sin los datos de la lesión (p. ej. importado antes de RN-ACC-06).
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $person = DB::selectOne("insert into event_people (company_id, event_id, person_role, external_person_name) values (?, ?, 'injured', 'Persona REG') returning id",
        [HUAYLAS_ID, $event])->id;
    $q = ['from' => '2025-10-01', 'to' => '2025-10-31', 'signer_user_id' => REG_SIGNER];

    // Criterio 1: sin parte del cuerpo se bloquea y lista el evento.
    $missing = $this->getJson('/api/v1/reg/accidents/check?from=2025-10-01&to=2025-10-31', $h)->assertOk()->json('data.missing');
    expect(collect($missing)->pluck('id'))->toContain($event);
    $this->postJson('/api/v1/reg/accidents/generate', $q, $h)->assertStatus(422)->assertJsonPath('type', 'register_incomplete')
        ->assertJsonFragment(['id' => $event]);

    $this->patchJson("/api/v1/records/ACC/event_people/{$person}", ['injury_type' => 'Corte', 'body_part' => 'Mano derecha', 'injury_agent' => 'Cúter'], $h)->assertOk();
    $v1 = $this->postJson('/api/v1/reg/accidents/generate', $q, $h)->assertCreated()->json('data');
    expect($v1['status'])->toBe('pending_signature')
        ->and($v1['data_hash'])->toHaveLength(64)
        ->and($v1['format_version'])->toBe('rm050-v1')
        ->and($v1['snapshot']['employer']['ruc'])->not->toBe('')
        ->and($v1['snapshot']['employer']['as_of'])->toBe('2025-10-31')
        ->and(collect($v1['snapshot']['rows'])->flatten()->implode(' '))->toContain('Corte leve con cúter')
        ->and($v1['signatures'])->toHaveCount(1);

    // Excel desde los datos congelados.
    $x = $this->get("/api/v1/reg/exports/{$v1['id']}.xlsx", $h);
    expect($x->status())->toBe(200)->and(substr($x->getContent(), 0, 2))->toBe('PK');

    // Firma del responsable con FIR: la versión queda «firmada».
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $req = DB::table('signature_requests')->where('subject_type', 'register_exports')->where('subject_id', $v1['id'])->value('id');
    $this->postJson("/api/v1/fir/requests/{$req}/sign", ['password' => DEMO_PASSWORD], $h)->assertOk();
    expect($this->getJson("/api/v1/reg/exports/{$v1['id']}", $h)->json('data.status'))->toBe('signed');

    // Criterios 3 y 4: al regenerar con un dato de origen cambiado, la anterior queda «reemplazada» y la huella difiere.
    $this->patchJson("/api/v1/records/ACC/sst_events/{$event}", ['description' => 'Corte leve con cúter en el almacén'], $h)->assertOk();
    $v2 = $this->postJson('/api/v1/reg/accidents/generate', $q, $h)->assertCreated()->json('data');
    $old = $this->getJson("/api/v1/reg/exports/{$v1['id']}", $h)->json('data');
    expect($old['status'])->toBe('superseded')->and($old['superseded_by'])->toBe($v2['id'])
        ->and($v2['data_hash'])->not->toBe($v1['data_hash'])
        ->and(collect($old['snapshot']['rows'])->flatten()->implode(' '))->not->toContain('en el almacén');

    $panel = $this->getJson('/api/v1/reg/panel', $h)->assertOk()->json('data');
    expect(collect($panel['versions'])->pluck('id'))->toContain($v1['id'], $v2['id'])
        ->and(collect($panel['signers'])->pluck('id'))->toContain(REG_SIGNER);
});

it('exige un responsable con permiso de firma y una sede válida', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->postJson('/api/v1/reg/inspections/generate', ['from' => '2025-01-01', 'to' => '2025-01-31'], $h)->assertStatus(422);
    $this->postJson('/api/v1/reg/inspections/generate', ['from' => '2025-01-01', 'to' => '2025-01-31', 'signer_user_id' => '01900000-0000-7000-8000-000000009999'], $h)
        ->assertStatus(422);
    $this->postJson('/api/v1/reg/inspections/generate', ['from' => '2025-01-01', 'to' => '2025-01-31', 'signer_user_id' => REG_SIGNER,
        'site_id' => '01900000-0000-7000-8000-000000009999'], $h)->assertStatus(400);
    $this->postJson('/api/v1/reg/inventado/generate', ['from' => '2025-01-01', 'to' => '2025-01-31', 'signer_user_id' => REG_SIGNER], $h)->assertNotFound();
});
