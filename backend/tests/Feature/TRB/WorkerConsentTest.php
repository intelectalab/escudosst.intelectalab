<?php

// M-TRB SCR-TRB-03 «consentimiento de datos» (Ley 29733): al dar de alta al trabajador se registra su consentimiento en
// worker_consents con la versión y la huella del texto vigente, la fecha, el canal y quién lo registró. El texto legal está
// «por confirmar» (texto neutro) hasta que lo apruebe un abogado.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $this->worker = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(40000000, 49999999), 'first_name' => 'Consiente', 'paternal_last_name' => 'Datos'], $this->h)
        ->assertCreated()->json('data.id');
});

it('muestra el texto vigente marcado «por confirmar» y registra el consentimiento con su versión, canal y quién lo registró', function () {
    $t = $this->getJson('/api/v1/trb/consent-text', $this->h)->assertOk()->json('data');
    expect($t['text'])->toBe('Texto de consentimiento pendiente de aprobación')->and($t['to_confirm'])->toBeTrue()->and($t['version'])->toStartWith('worker_data_consent_text@');

    expect($this->getJson("/api/v1/trb/workers/{$this->worker}/consents", $this->h)->assertOk()->json('data'))->toBe([]);
    $this->postJson("/api/v1/trb/workers/{$this->worker}/consents", ['granted' => true], $this->h)->assertStatus(422)->assertJsonValidationErrors('method');
    $this->postJson("/api/v1/trb/workers/{$this->worker}/consents", ['granted' => true, 'method' => 'paper_scanned'], $this->h)->assertStatus(422)->assertJsonValidationErrors('file_id');
    $this->postJson("/api/v1/trb/workers/{$this->worker}/consents", ['granted' => true, 'method' => 'in_person_assisted'], $this->h)->assertCreated()
        ->assertJsonPath('data.text_version', $t['version']);

    $rows = $this->getJson("/api/v1/trb/workers/{$this->worker}/consents", $this->h)->assertOk()->json('data');
    expect($rows)->toHaveCount(1)->and($rows[0]['consent_type'])->toBe('data_processing')->and($rows[0]['granted'])->toBeTrue()
        ->and($rows[0]['method'])->toBe('in_person_assisted')->and($rows[0]['registered_by'])->not->toBeEmpty();
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $c = DB::selectOne('select encode(text_sha256, \'hex\') as h, created_by from worker_consents where worker_id = ?', [$this->worker]);
    expect($c->h)->toBe(hash('sha256', $t['text']))->and($c->created_by)->not->toBeNull();
    expect(DB::selectOne("select 1 as ok from audit_logs where action = 'worker_consent_recorded' and entity_id = ?", [$this->worker]))->not->toBeNull();
});

it('no registra consentimientos de un trabajador de otra empresa', function () {
    $this->postJson('/api/v1/trb/workers/'.\Illuminate\Support\Str::uuid().'/consents', ['granted' => true, 'method' => 'in_person_assisted'], $this->h)->assertNotFound();
});
