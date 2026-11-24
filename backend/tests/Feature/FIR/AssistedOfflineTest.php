<?php

// Firma asistida con DNI + PIN del trabajador y testigo (RN-FIR-05), método mínimo por tipo de registro (RN-FIR-08) y
// firma capturada sin conexión con antigüedad máxima (RN-FIR-07).

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $this->dni = (string) random_int(40000000, 49999999);
    $this->worker = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => $this->dni, 'first_name' => 'Firma', 'paternal_last_name' => 'Asistida'], $this->h)
        ->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    DB::update('update workers set pin_hash = ? where id = ?', [Hash::make('482915'), $this->worker]);
    $this->request = function (string $text) {
        $doc = $this->postJson('/api/v1/records/ACT/substandard_reports', ['report_type' => 'suggestion', 'description' => $text], $this->h)->json('data.id');

        return $this->postJson('/api/v1/fir/requests', ['subject_table' => 'substandard_reports', 'subject_id' => $doc, 'signer_worker_id' => $this->worker, 'role_in_document' => 'worker'], $this->h)
            ->assertCreated()->json('data.id');
    };
});

afterEach(function () {
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    DB::update("update legal_parameters set deleted_at = now() where company_id = ? and key in ('fir_min_method_by_type') and deleted_at is null", [ANDINAS_ID]);
});

it('la firma asistida exige que el trabajador se identifique con DNI y PIN y deja a quien asiste como testigo', function () {
    $req = ($this->request)('FIR asistida');
    $this->postJson("/api/v1/fir/requests/{$req}/sign", ['assisted' => true], $this->h)->assertStatus(422)->assertJsonValidationErrors('pin');
    $this->postJson("/api/v1/fir/requests/{$req}/sign", ['assisted' => true, 'dni' => '39999999', 'pin' => '482915'], $this->h)->assertStatus(422)->assertJsonValidationErrors('dni');
    $this->postJson("/api/v1/fir/requests/{$req}/sign", ['assisted' => true, 'dni' => $this->dni, 'pin' => '000000'], $this->h)->assertStatus(422)->assertJsonValidationErrors('pin');
    $this->postJson("/api/v1/fir/requests/{$req}/sign", ['assisted' => true, 'dni' => $this->dni, 'pin' => '482915'], $this->h)->assertOk()->assertJsonPath('data.method', 'assisted_in_person');

    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $ev = json_decode(DB::selectOne('select evidence from signatures where signature_request_id = ?', [$req])->evidence, true);
    expect($ev['identity'])->toBe('dni_pin')->and($ev['witness_user_id'])->not->toBeEmpty();
});

it('respeta el método mínimo por tipo de registro', function () {
    companyParamForTests(ANDINAS_ID, 'fir_min_method_by_type', json_encode(['substandard_reports' => 'pin']), 'json');

    $req = ($this->request)('FIR mínimo');
    $this->postJson("/api/v1/fir/requests/{$req}/sign", ['assisted' => true, 'dni' => $this->dni, 'pin' => '482915'], $this->h)
        ->assertStatus(422)->assertJsonPath('detail', 'Este registro exige firmar con contraseña o PIN.');
});

it('acepta una firma sin conexión reciente y rechaza una que pasó la antigüedad máxima', function () {
    $old = ($this->request)('FIR sin conexión vencida');
    $this->postJson("/api/v1/fir/requests/{$old}/sign", ['assisted' => true, 'dni' => $this->dni, 'pin' => '482915', 'offline_captured_at' => now()->subHours(100)->toIso8601String()], $this->h)
        ->assertStatus(409);

    $req = ($this->request)('FIR sin conexión');
    $at = now()->subHours(2)->startOfSecond();
    $this->postJson("/api/v1/fir/requests/{$req}/sign", ['assisted' => true, 'dni' => $this->dni, 'pin' => '482915', 'offline_captured_at' => $at->toIso8601String()], $this->h)->assertOk();
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $s = DB::selectOne('select client_created_at from signatures where signature_request_id = ?', [$req]);
    expect(strtotime($s->client_created_at))->toBe($at->getTimestamp());
});
