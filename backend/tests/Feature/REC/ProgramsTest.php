<?php

// Programa anual (PASST), programa de capacitación y capacitaciones: aprobación, estructura congelada,
// avance por ejecuciones, mínimo legal de temas, sesión realizada → tema ejecutado → certificados.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function pg(): array
{
    return authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
}

it('aprueba el PASST, congela su estructura y calcula el avance con las ejecuciones', function () {
    $h = pg();
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    DB::update("update companies set sst_model = 'supervisor' where id = ?", [ANDINAS_ID]);
    $year = (int) now()->year + 1;
    $prog = $this->postJson('/api/v1/records/PAS/annual_programs', ['year' => $year, 'title' => "PASST {$year}"], $h)->assertCreated()
        ->assertJsonPath('data.status', 'draft')->json('data.id');

    $this->patchJson("/api/v1/records/PAS/annual_programs/{$prog}", ['status' => 'approved'], $h)
        ->assertStatus(422)->assertJsonPath('errors.status.0', 'El programa no tiene actividades.');

    // RN-PAS-06: cada actividad va ligada a un objetivo SST.
    $obj = DB::selectOne("insert into sst_objectives (company_id, year, code, title, status) values (?, ?, 'OBJ-PAS', 'Cero accidentes', 'active') returning id", [ANDINAS_ID, $year])->id;
    $a1 = $this->postJson('/api/v1/records/PAS/program_activities', ['program_id' => $prog, 'code' => 'A1', 'title' => 'Inspecciones mensuales',
        'activity_type' => 'inspection', 'goal_quantity' => 4, 'planned_months' => [1, 4, 7, 10]], $h)->assertCreated()->json('data.id');
    $a2 = $this->postJson('/api/v1/records/PAS/program_activities', ['program_id' => $prog, 'code' => 'A2', 'title' => 'Simulacro', 'activity_type' => 'drill',
        'goal_quantity' => 1, 'planned_months' => [6]], $h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/PAS/annual_programs/{$prog}", ['status' => 'approved'], $h)
        ->assertStatus(422)->assertJsonPath('errors.status.0', fn ($m) => str_contains($m, 'sin objetivo'));
    foreach ([$a1, $a2] as $a) {
        $this->patchJson("/api/v1/records/PAS/program_activities/{$a}", ['objective_id' => $obj], $h)->assertOk();
    }

    // Antes de aprobar no se registran ejecuciones.
    $this->postJson('/api/v1/records/PAS/activity_executions', ['activity_id' => $a1, 'executed_on' => now()->toDateString(), 'quantity' => 1, 'description' => 'x'], $h)
        ->assertStatus(409);

    $this->patchJson("/api/v1/records/PAS/annual_programs/{$prog}", ['status' => 'approved'], $h)->assertOk()->assertJsonPath('data.status', 'approved');
    $this->postJson('/api/v1/records/PAS/program_activities', ['program_id' => $prog, 'code' => 'A3', 'title' => 'Nueva', 'activity_type' => 'other'], $h)
        ->assertStatus(409);

    $this->postJson('/api/v1/records/PAS/activity_executions', ['activity_id' => $a1, 'executed_on' => now()->toDateString(), 'quantity' => 1], $h)
        ->assertStatus(422)->assertJsonPath('errors.description.0', 'Describe la evidencia de lo ejecutado (o vincula el registro de origen).');
    // RN-PAS-04: la ejecución lleva su evidencia.
    $this->postJson('/api/v1/records/PAS/activity_executions', ['activity_id' => $a1, 'executed_on' => now()->toDateString(), 'quantity' => 2, 'description' => 'Actas INS-01 e INS-02'], $h)
        ->assertStatus(422)->assertJsonValidationErrors('evidence_file_id');
    $file = $this->post('/api/v1/files', ['file' => \Illuminate\Http\UploadedFile::fake()->create('actas.pdf', 20, 'application/pdf'), 'module' => 'PAS'], $h + ['Accept' => 'application/json'])->json('data.id');
    $this->postJson('/api/v1/records/PAS/activity_executions', ['activity_id' => $a1, 'executed_on' => now()->toDateString(), 'quantity' => 2, 'description' => 'Actas INS-01 e INS-02',
        'evidence_file_id' => $file], $h)->assertCreated();

    $this->getJson("/api/v1/records/PAS/program_activities/{$a1}", $h)->assertJsonPath('data.progress_pct', 50)->assertJsonPath('data.status', 'in_progress');
    $this->getJson("/api/v1/records/PAS/annual_programs/{$prog}", $h)->assertJsonPath('data.status', 'in_execution')->assertJsonPath('data.progress_pct', 25);
});

it('exige el mínimo legal de capacitaciones y marca el tema ejecutado con la sesión realizada; emite certificados', function () {
    $h = pg();
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    DB::update("update companies set sst_model = 'supervisor' where id = ?", [ANDINAS_ID]);
    $year = (int) now()->year + 1;
    $prog = $this->postJson('/api/v1/records/PCA/training_programs', ['year' => $year, 'title' => "Programa de capacitación {$year}"], $h)->json('data.id');
    $items = [];
    foreach (['Inducción', 'Uso de EPP', 'Primeros auxilios'] as $k => $topic) {
        $items[] = $this->postJson('/api/v1/records/PCA/training_program_items', ['training_program_id' => $prog, 'topic' => $topic,
            'training_type' => 'specific', 'planned_month' => $k + 3, 'target_audience' => 'Todo el personal'], $h)->assertCreated()->json('data.id');
    }
    $this->patchJson("/api/v1/records/PCA/training_programs/{$prog}", ['status' => 'approved'], $h)
        ->assertStatus(422)->assertJsonPath('errors.status.0', 'El programa debe tener al menos 4 capacitaciones al año (Ley 29783, art. 35). Tiene 3.');
    $items[] = $this->postJson('/api/v1/records/PCA/training_program_items', ['training_program_id' => $prog, 'topic' => 'Respuesta ante emergencias',
        'training_type' => 'specific', 'planned_month' => 9, 'target_audience' => 'Brigadistas'], $h)->json('data.id');
    $this->patchJson("/api/v1/records/PCA/training_programs/{$prog}", ['status' => 'approved'], $h)->assertOk();

    // Capacitación del tema 1, una sesión con un asistente.
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'CAP-S', 'name' => 'Sede capacitación'], $h)->json('data.id');
    $worker = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '70112233', 'first_name' => 'Luis', 'paternal_last_name' => 'Huamán'], $h)->json('data.id');
    $training = $this->postJson('/api/v1/records/CAP/trainings', ['title' => 'Inducción general', 'training_type' => 'induction', 'modality' => 'in_person',
        'duration_minutes' => 120, 'certificate_validity_months' => 12, 'training_program_item_id' => $items[0]], $h)->assertCreated();
    expect($training->json('data.code'))->toStartWith('CAP-');
    $tid = $training->json('data.id');
    $this->getJson("/api/v1/records/PCA/training_program_items/{$items[0]}", $h)->assertJsonPath('data.status', 'scheduled');

    $session = $this->postJson('/api/v1/records/CAP/training_sessions', ['training_id' => $tid, 'session_number' => 1, 'starts_at' => now()->subHours(3)->toIso8601String(),
        'ends_at' => now()->subHour()->toIso8601String(), 'site_id' => $site], $h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/CAP/training_sessions/{$session}", ['status' => 'held'], $h)
        ->assertStatus(422)->assertJsonPath('errors.status.0', 'Registra al menos una asistencia antes de marcar la sesión como realizada.');
    $this->postJson('/api/v1/records/CAP/training_attendances', ['session_id' => $session, 'worker_id' => $worker, 'hours' => 2, 'check_in_method' => 'list'], $h)->assertCreated();
    $this->postJson("/api/v1/cap/trainings/{$tid}/certificates", [], $h)->assertStatus(409);
    $this->patchJson("/api/v1/records/CAP/training_sessions/{$session}", ['status' => 'held'], $h)->assertOk();

    $this->getJson("/api/v1/records/PCA/training_program_items/{$items[0]}", $h)->assertJsonPath('data.status', 'done');

    $this->postJson("/api/v1/cap/trainings/{$tid}/certificates", [], $h)->assertOk()->assertJsonPath('data.issued', 1);
    $this->postJson("/api/v1/cap/trainings/{$tid}/certificates", [], $h)->assertOk()->assertJsonPath('data.issued', 0);
    $cert = $this->getJson("/api/v1/records/CAP/training_certificates?f[training_id]={$tid}", $h)->json('data.0');
    expect($cert['certificate_code'])->toMatch('/^CERT-\d{4}-\d{6}$/');
    $full = $this->getJson("/api/v1/records/CAP/training_certificates/{$cert['id']}", $h)->json('data');
    expect($full['hours'])->toEqual(2)->and($full['expires_on'])->toBe(now('America/Lima')->addMonthsNoOverflow(12)->toDateString());

    $this->patchJson("/api/v1/records/CAP/training_certificates/{$cert['id']}", ['status' => 'voided'], $h)
        ->assertStatus(422)->assertJsonPath('errors.void_reason.0', 'Para anular el certificado indica el motivo.');
});
