<?php

// Descansos médicos y ausentismo (M-DES SCR-DES-01/03/04): indicadores de ausentismo por área y mes y días perdidos por
// accidente de trabajo (base principal); el diagnóstico CIE-10 y el certificado solo para el personal de salud, en la base
// médica, con clave médica y registro de cada acceso (RN-DES-03, criterio 3).

use App\Core\Auth\Permissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Storage::fake('local');
    DB::connection('medical')->beginTransaction();
    foreach ([[ESENCIAL_EMAIL, 'occupational_doctor'], [PROFESIONAL_EMAIL, 'hr']] as [$email, $code]) {
        $uid = DB::selectOne('select id from app_auth_lookup_user(?)', [$email])->id;
        DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
        $role = DB::selectOne('select id from roles where account_id is null and code = ?', [$code])->id;
        DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, ?, 'active', now())", [HUAYLAS_ID, $uid, $role]);
    }
    DB::select("select set_config('app.company_id', '', false)");
});

afterEach(function () {
    DB::connection('medical')->rollBack();
});

function desAs(string $email): array
{
    app('auth')->forgetGuards();
    Permissions::flush();

    return authHeaders(tokenFor($email), HUAYLAS_ID);
}

/** Área con un trabajador, un descanso de 10 días en el mes y un accidente que empieza el mes anterior. */
function desSetup(): array
{
    $sst = desAs(CORPORATIVO_EMAIL);
    $site = test()->postJson('/api/v1/records/TEN/sites', ['code' => 'DES-S', 'name' => 'Planta Ausentismo'], $sst)->assertCreated()->json('data.id');
    $area = test()->postJson('/api/v1/records/TEN/areas', ['site_id' => $site, 'code' => 'DES-A', 'name' => 'Área Ausentismo'], $sst)->assertCreated()->json('data.id');
    $w = test()->postJson('/api/v1/records/TRB/workers', ['document_number' => '66556655', 'first_name' => 'Ricardo', 'paternal_last_name' => 'Espinoza'], $sst)->assertCreated()->json('data.id');
    $start = now()->startOfMonth();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update('update workers set current_area_id = ? where id = ?', [$area, $w]);
    $leave = DB::selectOne("insert into absences (company_id, worker_id, absence_type, starts_on, ends_on) values (?, ?, 'medical_leave', ?, ?) returning id",
        [HUAYLAS_ID, $w, $start->copy()->addDays(10)->toDateString(), $start->copy()->addDays(19)->toDateString()])->id;
    $acc = DB::selectOne("insert into absences (company_id, worker_id, absence_type, starts_on, ends_on, is_work_related) values (?, ?, 'occupational_accident', ?, ?, true) returning id",
        [HUAYLAS_ID, $w, $start->copy()->subDays(2)->toDateString(), $start->copy()->addDays(2)->toDateString()])->id;
    DB::select("select set_config('app.company_id', '', false)");

    return [$w, $area, $leave, $acc];
}

it('calcula el ausentismo por área y mes y los días perdidos por accidente de trabajo', function () {
    [, $area] = desSetup();
    $month = now()->format('Y-m');
    $prev = now()->startOfMonth()->subMonth()->format('Y-m');

    $res = $this->getJson("/api/v1/des/indicators?month={$month}", desAs(PROFESIONAL_EMAIL))->assertOk();
    $row = collect($res->json('data.by_area'))->firstWhere('area_id', $area);
    $cur = collect($row['months'])->firstWhere('month', $month);
    $before = collect($row['months'])->firstWhere('month', $prev);
    // 10 días del descanso + 3 del accidente en el mes; 2 del accidente en el mes anterior.
    expect($cur['days'])->toBe(13)->and($before['days'])->toBe(2)->and($row['workers'])->toBe(1)
        ->and($cur['rate'])->toEqual(round(13 / now()->daysInMonth * 100, 2));
    expect($res->json('data.kpis.lost_days'))->toBeGreaterThanOrEqual(13)->and($res->json('data.kpis.accident_days'))->toBeGreaterThanOrEqual(3)
        ->and($res->json('data.kpis.accident_absences'))->toBeGreaterThanOrEqual(1)->and($res->json('data.months'))->toHaveCount(6)
        ->and($res->json('data.is_medical'))->toBeFalse();
});

it('el diagnóstico y el certificado solo los ve el personal de salud, en la base médica y con clave médica', function () {
    [$w, , $leave] = desSetup();
    $hr = desAs(PROFESIONAL_EMAIL);
    $this->getJson("/api/v1/des/absences/{$leave}/medical", $hr)->assertStatus(403);
    $this->postJson('/api/v1/des/reauth', ['password' => DEMO_PASSWORD], $hr)->assertStatus(403);

    $doc = desAs(ESENCIAL_EMAIL);
    $this->getJson("/api/v1/des/absences/{$leave}/medical", $doc)->assertStatus(403)->assertJsonPath('type', 'reauth_required');
    $r = $this->postJson('/api/v1/des/reauth', ['password' => DEMO_PASSWORD], $doc)->assertOk()->json('data.reauth_id');
    $m = $doc + ['X-Medical-Reauth' => $r];
    $this->getJson("/api/v1/des/absences/{$leave}/medical", $m)->assertOk()->assertJsonPath('data.detail', null)->assertJsonPath('data.absence.days', 10);

    // Sin detalle no se adjunta certificado; el CIE-10 se valida.
    $pdf = fn () => UploadedFile::fake()->createWithContent('citt.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    $this->post("/api/v1/des/absences/{$leave}/certificate", ['file' => $pdf()], $m)->assertStatus(409);
    $this->putJson("/api/v1/des/absences/{$leave}/medical", ['diagnosis_code' => 'XX'], $m)->assertStatus(422)->assertJsonValidationErrors('diagnosis_code');
    $this->putJson("/api/v1/des/absences/{$leave}/medical", ['diagnosis_code' => 'S61.4', 'diagnosis_description' => 'Herida de la mano', 'citt_number' => 'A-2026-118273',
        'issuing_entity' => 'essalud', 'issuing_doctor_name' => 'Dr. Prueba'], $m)->assertCreated();
    $this->post("/api/v1/des/absences/{$leave}/certificate", ['file' => $pdf()], $m)->assertCreated();
    $stored = Storage::disk('local')->allFiles('medical');
    expect($stored)->toHaveCount(1)->and(Storage::disk('local')->get($stored[0]))->not->toContain('%PDF');
    expect($this->get("/api/v1/des/absences/{$leave}/certificate", $m)->assertOk()->getContent())->toStartWith('%PDF');

    $show = $this->getJson("/api/v1/des/absences/{$leave}/medical", $m)->assertOk();
    expect($show->json('data.detail.diagnosis_code'))->toBe('S61.4')->and($show->json('data.detail.citt_number'))->toBe('A-2026-118273')
        ->and($show->json('data.detail.has_certificate'))->toBeTrue()->and($show->json('data.detail.validated_at'))->not->toBeNull();

    // Base médica cifrada; base principal solo con la referencia y sin diagnóstico.
    $med = DB::connection('medical');
    $med->select("select set_config('app.company_id', ?, false), set_config('app.reauth_session_id', ?, false)", [HUAYLAS_ID, $r]);
    expect($med->selectOne('select diagnosis_code from medical_leave_details where absence_ref = ?', [$leave])->diagnosis_code)->not->toContain('S61');
    $med->select("select set_config('app.company_id', '', false), set_config('app.reauth_session_id', '', false)");
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne('select medical_leave_ref, certificate_received from absences where id = ?', [$leave])->certificate_received)->toBeTrue();
    expect(DB::selectOne("select count(*) as n from sensitive_access_logs where reauth_session_id = ? and worker_id = ?", [$r, $w])->n)->toBeGreaterThanOrEqual(4);
    DB::select("select set_config('app.company_id', '', false)");

    // RR. HH. ve la lista con días y tipo, nunca el diagnóstico (ni con la clave del médico).
    $hr = desAs(PROFESIONAL_EMAIL);
    $row = collect($this->getJson('/api/v1/des/absences', $hr)->assertOk()->json('data'))->firstWhere('id', $leave);
    expect($row['has_medical'])->toBeTrue()->and($row['days'])->toBe(10)->and(json_encode($row))->not->toContain('S61');
    $this->getJson("/api/v1/des/absences/{$leave}/medical", $hr + ['X-Medical-Reauth' => $r])->assertStatus(403);
    $this->get("/api/v1/des/absences/{$leave}/certificate", $hr + ['X-Medical-Reauth' => $r])->assertStatus(403);
});
