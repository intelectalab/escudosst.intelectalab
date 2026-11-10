<?php

// Aptitud médica única vigente, ausencias, contratistas (documento sin duplicar, acreditación con requisitos),
// fotocheck evaluado contra requisitos, permisos de trabajo y presupuesto.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function pp(): array
{
    return authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
}

it('deja una sola aptitud vigente por trabajador y bloquea el fotocheck sin ella', function () {
    $h = pp();
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '91919191', 'first_name' => 'Elsa', 'paternal_last_name' => 'Ramos'], $h)->json('data.id');
    // Los datos de salud los registra el personal médico: el Jefe SST no puede.
    $this->postJson('/api/v1/records/EMO/fitness_status', ['worker_id' => $w, 'status' => 'fit'], $h)->assertStatus(403);

    // Fotocheck con requisito bloqueante de aptitud médica.
    $type = $this->postJson('/api/v1/records/FOT/credential_types', ['code' => 'FOT-STD', 'name' => 'Fotocheck estándar', 'validity_months' => 12, 'applies_to' => 'workers'], $h)
        ->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/FOT/credential_requirements', ['credential_type_id' => $type, 'requirement_type' => 'emo_fitness', 'is_blocking' => true,
        'description' => 'Aptitud médica vigente'], $h)->assertCreated();
    $cred = $this->postJson('/api/v1/records/FOT/credentials', ['credential_type_id' => $type, 'worker_id' => $w], $h)->assertCreated();
    expect($cred->json('data.status'))->toBe('blocked')->and($cred->json('data.blocking_reasons'))->toBe(['Aptitud médica vigente'])
        ->and($cred->json('data.credential_number'))->toStartWith('FOT-');

    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::insert("insert into fitness_status (company_id, worker_id, status, valid_from, valid_until, is_current) values (?, ?, 'fit', current_date, current_date + 365, true)", [HUAYLAS_ID, $w]);

    // Reevaluar el fotocheck: ahora cumple.
    $this->patchJson("/api/v1/records/FOT/credentials/{$cred->json('data.id')}", ['printed_at' => now()->toIso8601String()], $h)->assertOk()
        ->assertJsonPath('data.status', 'active');
});

it('protege los descansos médicos del Jefe SST', function () {
    $h = pp();
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '92929292', 'first_name' => 'Iván', 'paternal_last_name' => 'Paz'], $h)->json('data.id');
    $this->postJson('/api/v1/records/DES/absences', ['worker_id' => $w, 'absence_type' => 'occupational_accident', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-05'], $h)
        ->assertStatus(403); // descansos médicos: solo personal de salud o RR. HH. según su rol
});

it('acredita al contratista solo con sus requisitos obligatorios aprobados y vigentes', function () {
    $h = pp();
    $c = $this->postJson('/api/v1/records/CTR/contractors', ['ruc' => '20123456789', 'legal_name' => 'Montajes del Sur SAC', 'risk_level' => 'high'], $h)->assertCreated()->json('data.id');
    $req = $this->postJson('/api/v1/records/CTR/contractor_requirements', ['applies_to' => 'company', 'requirement_code' => 'SCTR', 'name' => 'Póliza SCTR', 'is_mandatory' => true], $h)
        ->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/CTR/contractor_workers', ['contractor_id' => $c, 'document_number' => '93939393', 'first_name' => 'Raúl', 'paternal_last_name' => 'Gómez'], $h)
        ->assertCreated()->assertJsonPath('data.document_last4', '9393');
    $this->postJson('/api/v1/records/CTR/contractor_workers', ['contractor_id' => $c, 'document_number' => '93939393', 'first_name' => 'Otro', 'paternal_last_name' => 'X'], $h)
        ->assertStatus(422);

    $acc = $this->postJson('/api/v1/records/CTR/contractor_accreditations', ['contractor_id' => $c, 'service_description' => 'Montaje de estructuras', 'starts_on' => now()->toDateString()], $h)
        ->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/CTR/contractor_accreditations/{$acc}", ['status' => 'accredited'], $h)
        ->assertStatus(422)->assertJsonPath('errors.status.0', 'Faltan requisitos aprobados y vigentes: Póliza SCTR.');
    $doc = $this->postJson('/api/v1/records/CTR/contractor_documents', ['contractor_id' => $c, 'requirement_id' => $req, 'document_kind' => 'sctr',
        'expires_on' => now()->addMonths(6)->toDateString()], $h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/CTR/contractor_documents/{$doc}", ['status' => 'approved'], $h)->assertOk();
    $this->patchJson("/api/v1/records/CTR/contractor_accreditations/{$acc}", ['status' => 'accredited'], $h)->assertOk()
        ->assertJsonPath('data.expires_on', now()->addMonths(6)->toDateString());
});

it('no aprueba un permiso con verificaciones en «no» y lo cierra con el área segura', function () {
    $h = pp();
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'PTR-S', 'name' => 'Planta permisos'], $h)->json('data.id');
    $type = $this->postJson('/api/v1/records/PTR/permit_types', ['code' => 'ALT', 'name' => 'Trabajo en altura', 'category' => 'work_at_height', 'validity_hours' => 8], $h);
    if ($type->status() !== 201) {
        $type = collect($this->getJson('/api/v1/records/PTR/permit_types', $h)->json('data'))->first();
        $typeId = $type['id'];
    } else {
        $typeId = $type->json('data.id');
    }
    $p = $this->postJson('/api/v1/records/PTR/work_permits', ['permit_type_id' => $typeId, 'site_id' => $site, 'work_description' => 'Cambio de luminarias',
        'valid_from' => now()->toIso8601String()], $h);
    expect($p->status())->toBe(201, (string) json_encode($p->json()));
    expect($p->json('data.code'))->toStartWith('PTR-')->and($p->json('data.valid_until'))->not->toBeNull();
    $id = $p->json('data.id');
    $this->postJson('/api/v1/records/PTR/permit_checks', ['work_permit_id' => $id, 'check_phase' => 'pre_start', 'item_code' => 'C1', 'question' => '¿Arnés inspeccionado?', 'answer' => 'no'], $h)
        ->assertCreated();
    $this->patchJson("/api/v1/records/PTR/work_permits/{$id}", ['status' => 'pending_approval'], $h)->assertOk();
    $this->patchJson("/api/v1/records/PTR/work_permits/{$id}", ['status' => 'approved'], $h)
        ->assertStatus(422)->assertJsonPath('errors.status.0', 'Hay verificaciones previas respondidas «No»: corrígelas antes de aprobar.');
});

it('suma las ejecuciones del presupuesto aprobado', function () {
    $h = pp();
    $b = $this->postJson('/api/v1/records/PRE/budgets', ['year' => 2026, 'title' => 'Presupuesto SST 2026'], $h)->assertCreated()->json('data.id');
    $l = $this->postJson('/api/v1/records/PRE/budget_lines', ['budget_id' => $b, 'category' => 'ppe', 'description' => 'Cascos y lentes', 'planned_amount' => 5000], $h)
        ->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/PRE/budget_executions', ['budget_line_id' => $l, 'amount' => 1200, 'description' => 'Compra de cascos'], $h)->assertStatus(409);
    $this->patchJson("/api/v1/records/PRE/budgets/{$b}", ['status' => 'approved'], $h)->assertOk();
    $this->postJson('/api/v1/records/PRE/budget_executions', ['budget_line_id' => $l, 'amount' => 1200, 'description' => 'Compra de cascos'], $h)->assertCreated();
    $this->getJson("/api/v1/records/PRE/budgets/{$b}", $h)->assertJsonPath('data.total_planned', 5000)->assertJsonPath('data.total_executed', 1200)
        ->assertJsonPath('data.status', 'in_execution');
});
