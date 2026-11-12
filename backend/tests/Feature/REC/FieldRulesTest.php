<?php

// Reglas de campo según sus fichas: permisos de trabajo (orden de aprobación, requisitos del ejecutante, cruce de horarios,
// gases, vencimiento), charlas (firma y cierre), ATS (firmado no se edita) e inspecciones (foto del «no cumple» crítico).

use App\Modules\NOT\SstAlerts;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $this->site = DB::selectOne('select id from sites where company_id = ? and deleted_at is null order by is_main desc limit 1', [HUAYLAS_ID])->id;
});

function frWorker(string $dni, string $name): string
{
    return test()->postJson('/api/v1/records/TRB/workers', ['document_number' => $dni, 'first_name' => $name, 'paternal_last_name' => 'Campo'], test()->h)->assertCreated()->json('data.id');
}

function frPermitType(bool $gas = false, int $steps = 1): string
{
    $chain = array_map(fn ($i) => ['step' => $i, 'role' => 'sst_supervisor'], range(1, $steps));

    return DB::selectOne(
        "insert into permit_types (company_id, code, name, category, validity_hours, requires_ats, requires_gas_test, checklist, approval_chain)
         values (?, ?, 'Espacio confinado prueba', 'confined_space', 8, false, ?, '[]'::jsonb, ?::jsonb) returning id",
        [HUAYLAS_ID, 'FR-'.substr(uniqid(), -6), $gas ? 'true' : 'false', json_encode($chain)]
    )->id;
}

function frPermit(string $type, string $site, string $leader, string $from): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/records/PTR/work_permits', ['permit_type_id' => $type, 'site_id' => $site, 'work_description' => 'Limpieza de tanque',
        'executor_leader_worker_id' => $leader, 'crew' => [['worker_id' => $leader, 'role' => 'leader']], 'valid_from' => $from], test()->h);
}

it('el permiso: pasos en orden, sin cruce de horarios, requisitos del ejecutante, gases en rango y vencimiento', function () {
    $type = frPermitType(true, 2);
    $leader = frWorker('62626201', 'Lider');
    $id = frPermit($type, $this->site, $leader, now()->addHour()->toIso8601String())->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/PTR/work_permits/{$id}", ['status' => 'pending_approval'], $this->h)->assertOk();

    // RN-PTR-09: el mismo líder no entra a otro permiso en el mismo horario.
    frPermit($type, $this->site, $leader, now()->addHours(2)->toIso8601String())->assertStatus(422)->assertJsonValidationErrors('crew');

    // RN-PTR-04: no se aprueba el paso 2 antes del 1, y quien firmó un paso no firma el otro.
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    // Lo solicitó otra persona (quien solicita no aprueba).
    $me = DB::connection('platform')->selectOne('select id from users where email = ?', [CORPORATIVO_EMAIL])->id;
    DB::update('update work_permits set requested_by = (select id from users where id <> ? and deleted_at is null order by created_at limit 1) where id = ?', [$me, $id]);
    $steps = collect(DB::select('select id, step_order from permit_approvals where work_permit_id = ?', [$id]))->mapWithKeys(fn ($r) => [$r->step_order => ['id' => $r->id]]);
    $this->patchJson("/api/v1/records/PTR/permit_approvals/{$steps[2]['id']}", ['decision' => 'approved'], $this->h)
        ->assertStatus(422)->assertJsonPath('errors.decision.0', fn ($m) => str_contains($m, 'paso anterior'));
    $this->patchJson("/api/v1/records/PTR/permit_approvals/{$steps[1]['id']}", ['decision' => 'approved'], $this->h)->assertOk();
    $this->patchJson("/api/v1/records/PTR/permit_approvals/{$steps[2]['id']}", ['decision' => 'approved'], $this->h)
        ->assertStatus(422)->assertJsonPath('errors.decision.0', fn ($m) => str_contains($m, 'otro paso'));

    // RN-PTR-01: con las aprobaciones completas, el líder sin requisitos SST (inducción, SCTR…) no deja aprobar.
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update permit_approvals set decision = 'approved', approver_user_id = coalesce(approver_user_id, ?), decided_at = now() where work_permit_id = ?", [$me, $id]);
    $this->patchJson("/api/v1/records/PTR/work_permits/{$id}", ['status' => 'approved'], $this->h)
        ->assertStatus(422)->assertJsonPath('errors.status.0', fn ($m) => str_contains($m, 'no cumplen sus requisitos'));

    // RN-PTR-03: aprobado, no se inicia con oxígeno bajo.
    DB::update("update work_permits set status = 'approved', valid_from = now() - interval '1 minute' where id = ?", [$id]);
    $this->patchJson("/api/v1/records/PTR/work_permits/{$id}", ['status' => 'active', 'gas_measurements' => ['o2' => 18.2, 'lel' => 0, 'co' => 3, 'h2s' => 0]], $this->h)
        ->assertStatus(422)->assertJsonPath('errors.gas_measurements.0', fn ($m) => str_contains($m, 'oxígeno'));
    $this->patchJson("/api/v1/records/PTR/work_permits/{$id}", ['status' => 'active', 'gas_measurements' => ['o2' => 20.9, 'lel' => 0, 'co' => 3, 'h2s' => 0]], $this->h)->assertOk();

    // RN-PTR-05: un permiso aprobado cuyo horario terminó sin iniciarse queda vencido.
    $other = frPermit($type, $this->site, frWorker('62626202', 'Otro'), now()->addDays(3)->toIso8601String())->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update work_permits set status = 'approved', valid_from = now() - interval '10 hours', valid_until = now() - interval '2 hours' where id = ?", [$other]);
    app(SstAlerts::class)->refreshStatuses(DB::connection());
    expect(DB::selectOne('select status from work_permits where id = ?', [$other])->status)->toBe('expired');
});

it('la charla se cierra con una firma y ya realizada no cambia', function () {
    $talk = $this->postJson('/api/v1/records/CH5/safety_talks', ['topic' => 'Orden y limpieza', 'talk_on' => now()->toDateString(), 'site_id' => $this->site], $this->h)
        ->assertCreated()->json('data.id');
    $w = frWorker('62626203', 'Asistente');
    $this->postJson('/api/v1/records/CH5/talk_attendances', ['safety_talk_id' => $talk, 'worker_id' => $w, 'method' => 'signature'], $this->h)->assertCreated();
    $this->patchJson("/api/v1/records/CH5/safety_talks/{$talk}", ['status' => 'held'], $this->h)
        ->assertStatus(422)->assertJsonPath('errors.status.0', fn ($m) => str_contains($m, 'firmó'));
    $this->postJson('/api/v1/records/CH5/talk_attendances', ['safety_talk_id' => $talk, 'worker_id' => frWorker('62626204', 'Lista'), 'method' => 'list'], $this->h)->assertCreated();
    $this->patchJson("/api/v1/records/CH5/safety_talks/{$talk}", ['status' => 'held'], $this->h)->assertOk();
    $this->patchJson("/api/v1/records/CH5/safety_talks/{$talk}", ['topic' => 'Otro tema'], $this->h)->assertStatus(409);
    $this->postJson('/api/v1/records/CH5/talk_attendances', ['safety_talk_id' => $talk, 'worker_id' => frWorker('62626205', 'Tarde'), 'method' => 'list'], $this->h)->assertStatus(409);
});

it('el ATS pide supervisor para firmarse y firmado no cambia sus pasos', function () {
    $ats = $this->postJson('/api/v1/records/ATS/ats_forms', ['task' => 'Cambio de bomba', 'site_id' => $this->site, 'performed_on' => now()->toDateString()], $this->h)
        ->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/ATS/ats_steps', ['ats_form_id' => $ats, 'step_number' => 1, 'description' => 'Bloquear energía', 'hazards' => 'Energía eléctrica', 'controls' => 'LOTO'], $this->h)
        ->assertCreated();
    $this->postJson('/api/v1/records/ATS/ats_participants', ['ats_form_id' => $ats, 'worker_id' => frWorker('62626206', 'Ejecutante'), 'role' => 'executor'], $this->h)->assertCreated();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update('update ats_participants set signed_at = now() where ats_form_id = ?', [$ats]);
    $this->patchJson("/api/v1/records/ATS/ats_forms/{$ats}", ['status' => 'signed'], $this->h)->assertStatus(422)->assertJsonValidationErrors('supervisor_user_id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update ats_forms set status = 'signed' where id = ?", [$ats]);
    $this->postJson('/api/v1/records/ATS/ats_steps', ['ats_form_id' => $ats, 'step_number' => 2, 'description' => 'Retirar bomba', 'hazards' => 'Carga', 'controls' => 'Tecle'], $this->h)
        ->assertStatus(409);
    $this->patchJson("/api/v1/records/ATS/ats_forms/{$ats}", ['task' => 'Otra tarea'], $this->h)->assertStatus(409);
});

it('un «no cumple» crítico pide foto y al finalizar avisa al equipo SST', function () {
    $tpl = DB::selectOne("insert into inspection_templates (company_id, code, name, inspection_type) values (?, ?, 'Extintores prueba', 'emergency_equipment') returning id",
        [HUAYLAS_ID, 'FR-'.substr(uniqid(), -6)])->id;
    $item = DB::selectOne("insert into inspection_template_items (company_id, template_id, item_code, question, answer_type, is_critical) values (?, ?, 'E1', '¿Extintor cargado?', 'yes_no_na', true) returning id",
        [HUAYLAS_ID, $tpl])->id;
    $ins = $this->postJson('/api/v1/records/INS/inspections', ['title' => 'Extintores planta', 'inspection_type' => 'emergency_equipment', 'site_id' => $this->site, 'template_id' => $tpl], $this->h)
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/ins/inspections/{$ins}/answers", ['answers' => [['item_id' => $item, 'answer' => 'no']]], $this->h)->assertOk();
    $this->postJson("/api/v1/ins/inspections/{$ins}/finish", [], $this->h)->assertStatus(422)->assertJsonPath('title', 'Falta la foto');

    $photo = $this->post('/api/v1/files', ['file' => UploadedFile::fake()->image('extintor.jpg'), 'module' => 'INS'], $this->h + ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/ins/inspections/{$ins}/answers", ['answers' => [['item_id' => $item, 'answer' => 'no', 'photo_file_id' => $photo]]], $this->h)->assertOk();
    $this->postJson("/api/v1/ins/inspections/{$ins}/finish", [], $this->h)->assertOk()->assertJsonPath('data.critical', true);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne("select count(*) as n from notifications where event_code = 'ins.critical' and subject_id = ?", [$ins])->n)->toBeGreaterThan(0);
});
