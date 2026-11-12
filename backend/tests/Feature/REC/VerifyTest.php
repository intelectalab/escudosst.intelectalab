<?php

// Certificado y fotocheck: datos para imprimir con QR y verificación pública sin sesión (registrada).

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

it('verifica públicamente un certificado solo si la empresa lo habilitó', function () {
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'VER-S', 'name' => 'Sede verificación'], $h)->json('data.id');
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '71717171', 'first_name' => 'Ana', 'paternal_last_name' => 'Lima'], $h)->json('data.id');
    $t = $this->postJson('/api/v1/records/CAP/trainings', ['title' => 'Trabajo en altura', 'training_type' => 'specific', 'modality' => 'in_person', 'duration_minutes' => 240,
        'certificate_validity_months' => 12], $h)->json('data.id');
    $s = $this->postJson('/api/v1/records/CAP/training_sessions', ['training_id' => $t, 'session_number' => 1, 'starts_at' => now()->subHours(5)->toIso8601String(),
        'ends_at' => now()->subHour()->toIso8601String(), 'site_id' => $site], $h)->json('data.id');
    $this->postJson('/api/v1/records/CAP/training_attendances', ['session_id' => $s, 'worker_id' => $w, 'hours' => 4, 'check_in_method' => 'list'], $h)->assertCreated();
    $this->patchJson("/api/v1/records/CAP/training_sessions/{$s}", ['status' => 'held'], $h)->assertOk();
    $this->postJson("/api/v1/cap/trainings/{$t}/certificates", [], $h)->assertOk();
    $cert = $this->getJson("/api/v1/records/CAP/training_certificates?f[training_id]={$t}", $h)->json('data.0.id');

    $p = $this->getJson("/api/v1/cap/certificates/{$cert}/print", $h)->assertOk()->json('data');
    expect($p['holder_name'])->toBe('Ana Lima')->and($p['verify_url'])->toContain('/verificar/certificado/'.$p['verification_code']);

    $this->getJson('/api/v1/public/verify/certificate/'.$p['verification_code'])->assertOk()->assertJsonPath('data.result', 'not_public');
    $this->patchJson("/api/v1/records/CAP/training_certificates/{$cert}", ['public_verification_enabled' => true], $h)->assertOk();
    $this->getJson('/api/v1/public/verify/certificate/'.$p['verification_code'])->assertOk()
        ->assertJsonPath('data.result', 'valid')->assertJsonPath('data.holder_name', 'Ana Lima')->assertJsonMissingPath('data.worker_id');
    $this->getJson('/api/v1/public/verify/certificate/NOEXISTE12345678')->assertOk()->assertJsonPath('data.result', 'invalid');
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    expect(DB::table('certificate_verifications')->where('certificate_id', $cert)->count())->toBe(2);
});

it('verifica el fotocheck por su QR en la garita', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '72727272', 'first_name' => 'Luz', 'paternal_last_name' => 'Rey'], $h)->json('data.id');
    $type = $this->postJson('/api/v1/records/FOT/credential_types', ['code' => 'FOT-V', 'name' => 'Fotocheck', 'validity_months' => 12, 'applies_to' => 'workers'], $h)->json('data.id');
    $cred = $this->postJson('/api/v1/records/FOT/credentials', ['credential_type_id' => $type, 'worker_id' => $w], $h)->assertCreated()->json('data.id');
    $url = $this->getJson("/api/v1/fot/credentials/{$cred}/print", $h)->assertOk()->json('data.verify_url');
    $token = basename($url);
    $this->getJson("/api/v1/public/verify/credential/{$token}")->assertOk()->assertJsonPath('data.result', 'enabled')->assertJsonPath('data.name', 'Luz Rey');
    $this->getJson('/api/v1/public/verify/credential/ABCDEF0123456789')->assertOk()->assertJsonPath('data.result', 'invalid');
});
