<?php

// Correcciones de la revisión por módulos (recorrido como empresa real): versiones y asignaciones con índices parciales,
// presupuesto con flujo, reportes y charlas con estados coherentes, acción anulada que libera su origen.

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function rf(): array
{
    return authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
}

it('un documento admite una segunda versión (el único «una vigente» lo valida la base)', function () {
    $h = rf();
    $cat = $this->postJson('/api/v1/records/DOC/document_categories', ['code' => 'RF-CAT', 'name' => 'Procedimientos RF'], $h)->assertCreated()->json('data.id');
    $doc = $this->postJson('/api/v1/records/DOC/documents', ['category_id' => $cat, 'code' => 'RF-DOC-1', 'title' => 'Procedimiento de prueba'], $h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/DOC/document_versions', ['document_id' => $doc, 'version_number' => 1, 'version_label' => 'v1'], $h)->assertCreated();
    $this->postJson('/api/v1/records/DOC/document_versions', ['document_id' => $doc, 'version_number' => 2, 'version_label' => 'v2'], $h)->assertCreated();
    expect($this->getJson("/api/v1/records/DOC/document_versions?f[document_id]={$doc}", $h)->json('meta.total'))->toBe(2);
});

it('el presupuesto se aprueba con líneas, no vuelve a borrador y sus líneas quedan fijas', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $b = $this->postJson('/api/v1/records/PRE/budgets', ['year' => 2027, 'title' => 'RF Presupuesto 2027', 'status' => 'approved'], $h)->assertCreated();
    expect($b->json('data.status'))->toBe('draft');
    $id = $b->json('data.id');
    $this->patchJson("/api/v1/records/PRE/budgets/{$id}", ['status' => 'approved'], $h)->assertStatus(422)->assertJsonValidationErrors('status');
    $line = $this->postJson('/api/v1/records/PRE/budget_lines', ['budget_id' => $id, 'category' => 'ppe', 'description' => 'Cascos', 'planned_amount' => 1500], $h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/PRE/budgets/{$id}", ['status' => 'approved'], $h)->assertOk()->assertJsonPath('data.approved_by', fn ($v) => $v !== null);
    $this->patchJson("/api/v1/records/PRE/budgets/{$id}", ['status' => 'draft'], $h)->assertStatus(422);
    $this->patchJson("/api/v1/records/PRE/budget_lines/{$line}", ['planned_amount' => 9000], $h)->assertStatus(409);
});

it('un reporte no se cierra sin revisarlo y una charla nace programada', function () {
    $h = rf();
    $r = $this->postJson('/api/v1/records/ACT/substandard_reports', ['report_type' => 'unsafe_condition', 'description' => 'RF Cable pelado en almacén'], $h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/ACT/substandard_reports/{$r}", ['status' => 'closed'], $h)->assertStatus(422)->assertJsonValidationErrors('status');
    $this->patchJson("/api/v1/records/ACT/substandard_reports/{$r}", ['status' => 'triaged', 'risk_level' => 'high'], $h)->assertOk();
    $this->patchJson("/api/v1/records/ACT/substandard_reports/{$r}", ['status' => 'closed'], $h)->assertOk();

    $t = $this->postJson('/api/v1/records/CH5/safety_talks', ['topic' => 'RF Orden y limpieza', 'talk_on' => now()->toDateString()], $h)->assertCreated();
    expect($t->json('data.status'))->toBe('scheduled');
});

it('una acción anulada libera su origen para asignarle otra', function () {
    $h = rf();
    $r = $this->postJson('/api/v1/records/ACT/substandard_reports', ['report_type' => 'unsafe_act', 'description' => 'RF Trabajo sin guantes'], $h)->json('data.id');
    $this->patchJson("/api/v1/records/ACT/substandard_reports/{$r}", ['status' => 'triaged', 'risk_level' => 'medium'], $h)->assertOk();
    $me = collect($this->getJson('/api/v1/records/ACR/corrective_actions/options/responsible_user_id', $h)->json('data'))->first()['id'];
    $body = ['title' => 'RF Charla de uso de guantes', 'responsible_user_id' => $me, 'due_on' => now()->addDays(3)->toDateString()];
    $a = $this->postJson("/api/v1/acr/from/substandard_reports/{$r}", $body, $h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/acr/from/substandard_reports/{$r}", $body, $h)->assertStatus(409);
    $this->patchJson("/api/v1/records/ACR/corrective_actions/{$a}", ['status' => 'cancelled', 'cancel_reason' => 'Se asignó mal'], $h)->assertOk();
    $this->getJson("/api/v1/records/ACT/substandard_reports/{$r}", $h)->assertJsonPath('data.corrective_action_id', null)->assertJsonPath('data.status', 'triaged');
    $this->postJson("/api/v1/acr/from/substandard_reports/{$r}", $body, $h)->assertCreated();
});

it('una evidencia vacía no cuenta y «no eficaz» exige explicar por qué', function () {
    $h = rf();
    $me = collect($this->getJson('/api/v1/records/ACR/corrective_actions/options/responsible_user_id', $h)->json('data'))->first()['id'];
    $a = $this->postJson('/api/v1/records/ACR/corrective_actions', ['source_type' => 'other', 'description' => 'RF origen', 'title' => 'RF acción',
        'responsible_user_id' => $me, 'due_on' => now()->addDays(3)->toDateString()], $h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/ACR/action_evidences', ['corrective_action_id' => $a], $h)->assertStatus(422)->assertJsonValidationErrors('description');
});

it('un trabajador reporta por el QR del punto sin sesión y el reporte llega al área SST', function () {
    $h = rf();
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'RF-QR', 'name' => 'Planta QR'], $h)->json('data.id');
    $point = $this->postJson('/api/v1/records/ACT/report_points', ['code' => 'P-01', 'name' => 'Almacén central', 'site_id' => $site], $h)->assertCreated()->json('data.id');
    $url = $this->getJson("/api/v1/act/report-points/{$point}/link", $h)->assertOk()->json('data.url');
    [$company, $token] = array_slice(explode('/', $url), -2);

    $this->getJson("/api/v1/public/report/{$company}/{$token}")->assertOk()->assertJsonPath('data.point', 'Almacén central');
    $this->postJson("/api/v1/public/report/{$company}/{$token}", ['report_type' => 'unsafe_condition', 'description' => 'corto'])->assertStatus(422);
    $this->postJson("/api/v1/public/report/{$company}/{$token}", ['report_type' => 'unsafe_condition', 'description' => 'Cable pelado junto a la máquina 3'])
        ->assertStatus(422)->assertJsonValidationErrors('reporter_name');
    $code = $this->postJson("/api/v1/public/report/{$company}/{$token}", ['report_type' => 'unsafe_condition', 'description' => 'Cable pelado junto a la máquina 3', 'is_anonymous' => true])
        ->assertCreated()->json('data.code');
    $rep = $this->getJson("/api/v1/records/ACT/substandard_reports?f[report_point_id]={$point}", rf())->json('data.0');
    expect($rep)->toMatchArray(['code' => $code, 'origin' => 'qr', 'status' => 'received', 'is_anonymous' => true]);

    $this->getJson("/api/v1/public/report/{$company}/".str_repeat('A', 32))->assertNotFound();
    $this->patchJson("/api/v1/records/ACT/report_points/{$point}", ['is_active' => false], rf())->assertOk();
    $this->getJson("/api/v1/public/report/{$company}/{$token}")->assertStatus(410);
});

it('firma electrónica: se pide sobre un registro, se firma con contraseña y deja evidencia; si el registro cambia, no procede', function () {
    $h = rf();
    $me = collect($this->getJson('/api/v1/records/ACR/corrective_actions/options/responsible_user_id', $h)->json('data'))->first()['id'];
    $doc = $this->postJson('/api/v1/records/ACT/substandard_reports', ['report_type' => 'suggestion', 'description' => 'RF documento para firmar'], $h)->json('data.id');

    $this->postJson('/api/v1/records/FIR/signature_requests', ['subject_type' => 'substandard_reports', 'role_in_document' => 'approver', 'signer_user_id' => $me], $h)->assertStatus(409);
    $req = $this->postJson('/api/v1/fir/requests', ['subject_table' => 'substandard_reports', 'subject_id' => $doc, 'signer_user_id' => $me, 'role_in_document' => 'approver'], $h)
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/fir/requests/{$req}/sign", ['password' => 'incorrecta'], $h)->assertStatus(422)->assertJsonValidationErrors('password');
    $this->postJson("/api/v1/fir/requests/{$req}/sign", ['password' => DEMO_PASSWORD], $h)->assertOk()->assertJsonPath('data.method', 'pin');
    $this->getJson("/api/v1/fir/subject/substandard_reports/{$doc}", $h)->assertJsonPath('data.0.status', 'signed')->assertJsonPath('data.0.method', 'pin');

    // Se pide otra firma y luego cambia el registro: la firma ya no procede.
    $req2 = $this->postJson('/api/v1/fir/requests', ['subject_table' => 'substandard_reports', 'subject_id' => $doc, 'signer_user_id' => $me, 'role_in_document' => 'reviewer'], $h)->json('data.id');
    $this->patchJson("/api/v1/records/ACT/substandard_reports/{$doc}", ['description' => 'RF documento cambiado después'], $h)->assertOk();
    $this->postJson("/api/v1/fir/requests/{$req2}/sign", ['password' => DEMO_PASSWORD], $h)->assertStatus(409);
});

it('mi firma (dibujada o foto) se guarda y se pega sola al firmar un documento', function () {
    $h = rf();
    $png = 'data:image/png;base64,'.base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
    $this->putJson('/api/v1/me/signature', ['image' => 'data:image/png;base64,QUJD', 'kind' => 'drawn'], $h)->assertStatus(422);
    $this->putJson('/api/v1/me/signature', ['image' => $png, 'kind' => 'drawn'], $h)->assertOk();
    $this->getJson('/api/v1/me/signature', $h)->assertOk()->assertJsonPath('data.kind', 'drawn');

    $me = collect($this->getJson('/api/v1/records/ACR/corrective_actions/options/responsible_user_id', $h)->json('data'))->first()['id'];
    $doc = $this->postJson('/api/v1/records/ACT/substandard_reports', ['report_type' => 'suggestion', 'description' => 'RF documento con firma dibujada'], $h)->json('data.id');
    $req = $this->postJson('/api/v1/fir/requests', ['subject_table' => 'substandard_reports', 'subject_id' => $doc, 'signer_user_id' => $me, 'role_in_document' => 'responsible'], $h)->json('data.id');
    $this->postJson("/api/v1/fir/requests/{$req}/sign", ['password' => DEMO_PASSWORD], $h)->assertOk();
    $row = $this->getJson("/api/v1/fir/subject/substandard_reports/{$doc}", $h)->json('data.0');
    expect($row['has_image'])->toBeTrue();
    $this->get("/api/v1/fir/signatures/{$row['signature_id']}/image", $h)->assertOk()->assertHeader('Content-Type', 'image/png');
});
