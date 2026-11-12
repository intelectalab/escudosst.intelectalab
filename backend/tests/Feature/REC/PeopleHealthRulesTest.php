<?php

// Reglas de personas y salud según sus fichas: descansos (DES), fotocheck (FOT), capacitación (CAP) y aptitud (EMO).

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $uid = DB::selectOne('select id from app_auth_lookup_user(?)', [PROFESIONAL_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $role = DB::selectOne("select id from roles where account_id is null and code = 'hr'")->id;
    DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, ?, 'active', now())", [HUAYLAS_ID, $uid, $role]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('DES', 'FOT', 'CAP', 'EMO') and deleted_at is null", [HUAYLAS_ID]);
    DB::select("select set_config('app.company_id', '', false)");
    Permissions::flush();
    $this->sst = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

function phAs(string $email): array
{
    app('auth')->forgetGuards();
    Permissions::flush();

    return authHeaders(tokenFor($email), HUAYLAS_ID);
}

it('los descansos no se cruzan, la prórroga sigue al anterior y el trabajador queda en licencia', function () {
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '61616161', 'first_name' => 'Luz', 'paternal_last_name' => 'Ríos'], $this->sst)->assertCreated()->json('data.id');
    $hr = phAs(PROFESIONAL_EMAIL);
    $d = fn (int $n) => now('America/Lima')->addDays($n)->toDateString();

    // RN-DES-02: el accidente de trabajo se liga a su evento.
    $this->postJson('/api/v1/records/DES/absences', ['worker_id' => $w, 'absence_type' => 'occupational_accident', 'starts_on' => $d(-1), 'ends_on' => $d(3)], $hr)
        ->assertStatus(422)->assertJsonValidationErrors('sst_event_id');
    $first = $this->postJson('/api/v1/records/DES/absences', ['worker_id' => $w, 'absence_type' => 'medical_leave', 'starts_on' => $d(-1), 'ends_on' => $d(3)], $hr)
        ->assertCreated()->assertJsonPath('data.days', 5)->json('data.id');
    // RN-DES-05: con descanso vigente queda en licencia.
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne('select status from workers where id = ?', [$w])->status)->toBe('on_leave');

    // RN-DES-04: no se superponen.
    $this->postJson('/api/v1/records/DES/absences', ['worker_id' => $w, 'absence_type' => 'medical_leave', 'starts_on' => $d(2), 'ends_on' => $d(6)], $hr)
        ->assertStatus(422)->assertJsonValidationErrors('starts_on');
    // RN-DES-06: la prórroga empieza al día siguiente del anterior.
    $this->postJson('/api/v1/records/DES/absences', ['worker_id' => $w, 'absence_type' => 'medical_leave', 'starts_on' => $d(5), 'ends_on' => $d(8), 'previous_absence_id' => $first], $hr)
        ->assertStatus(422)->assertJsonValidationErrors('starts_on');
    $this->postJson('/api/v1/records/DES/absences', ['worker_id' => $w, 'absence_type' => 'medical_leave', 'starts_on' => $d(4), 'ends_on' => $d(8), 'previous_absence_id' => $first], $hr)
        ->assertCreated();

    // Al terminar sus descansos vuelve a activo (revisión diaria).
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update absences set status = 'cancelled' where worker_id = ?", [$w]);
    \App\Modules\REC\Rules\PeopleRules::syncLeave(DB::connection(), $w);
    expect(DB::selectOne('select status from workers where id = ?', [$w])->status)->toBe('active');
});

it('el fotocheck vence con su primer requisito, es uno por tipo y respeta el puesto y los días de gracia', function () {
    $h = $this->sst;
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '62626262', 'first_name' => 'Raúl', 'paternal_last_name' => 'Soto', 'hire_on' => now()->subDays(2)->toDateString()], $h)
        ->assertCreated()->json('data.id');
    $type = $this->postJson('/api/v1/records/FOT/credential_types', ['code' => 'FOT-PH', 'name' => 'Fotocheck planta', 'validity_months' => 12, 'applies_to' => 'workers'], $h)
        ->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/FOT/credential_requirements', ['credential_type_id' => $type, 'requirement_type' => 'emo_fitness', 'is_blocking' => true, 'description' => 'Aptitud médica vigente'], $h)->assertCreated();
    // Requisito de otro puesto: no aplica a este trabajador (RN-FOT-01).
    $pos = $this->postJson('/api/v1/records/TEN/positions', ['code' => 'POS-PH', 'name' => 'Soldador'], $h)->json('data.id');
    if ($pos) {
        $this->postJson('/api/v1/records/FOT/credential_requirements', ['credential_type_id' => $type, 'requirement_type' => 'sctr', 'is_blocking' => true, 'position_id' => $pos, 'description' => 'SCTR del soldador'], $h)->assertCreated();
    }
    // Inducción con días de gracia: el recién ingresado no queda bloqueado (RN-FOT-13).
    $tr = $this->postJson('/api/v1/records/CAP/trainings', ['title' => 'Inducción planta', 'training_type' => 'induction', 'modality' => 'in_person', 'duration_minutes' => 60], $h)->json('data.id');
    $this->postJson('/api/v1/records/FOT/credential_requirements', ['credential_type_id' => $type, 'requirement_type' => 'induction', 'training_id' => $tr, 'is_blocking' => true, 'grace_days' => 15, 'description' => 'Inducción de planta'], $h)
        ->assertCreated();

    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::insert("insert into fitness_status (company_id, worker_id, status, valid_from, valid_until, is_current) values (?, ?, 'fit', current_date, current_date + 60, true)", [HUAYLAS_ID, $w]);
    $cred = $this->postJson('/api/v1/records/FOT/credentials', ['credential_type_id' => $type, 'worker_id' => $w], $h)->assertCreated();
    expect($cred->json('data.status'))->toBe('active')
        ->and($cred->json('data.expires_on'))->toStartWith(now()->addDays(60)->toDateString());   // RN-FOT-02

    // RN-FOT-09: uno vigente por tipo.
    $this->postJson('/api/v1/records/FOT/credentials', ['credential_type_id' => $type, 'worker_id' => $w], $h)
        ->assertStatus(422)->assertJsonValidationErrors('credential_type_id');

    // RN-EMO-07: la aptitud vencida pasa a «vencida» y el fotocheck se bloquea en la revisión diaria.
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update('update fitness_status set valid_from = current_date - 400, valid_until = current_date - 1 where worker_id = ?', [$w]);
    app(\App\Modules\NOT\SstAlerts::class)->refreshStatuses(DB::connection());
    expect(DB::selectOne('select status from fitness_status where worker_id = ?', [$w])->status)->toBe('expired');
    expect(DB::selectOne('select status from credentials where id = ?', [$cred->json('data.id')])->status)->toBe('blocked');
});

it('el certificado exige asistencia firmada con horas mínimas, sin cruces de horario, y se reemite en vez de corregirse', function () {
    $h = $this->sst;
    $site = DB::selectOne('select id from sites where company_id = ? and deleted_at is null limit 1', [HUAYLAS_ID])?->id
        ?? $this->postJson('/api/v1/records/TEN/sites', ['code' => 'PH-S', 'name' => 'Sede PH'], $h)->json('data.id');
    $mk = fn ($doc, $name) => $this->postJson('/api/v1/records/TRB/workers', ['document_number' => $doc, 'first_name' => $name, 'paternal_last_name' => 'Prueba'], $h)->assertCreated()->json('data.id');
    [$a, $b, $c] = [$mk('63636361', 'Ana'), $mk('63636362', 'Beto'), $mk('63636363', 'Carla')];
    $t = $this->postJson('/api/v1/records/CAP/trainings', ['title' => 'Espacios confinados', 'training_type' => 'specific', 'modality' => 'in_person', 'duration_minutes' => 120,
        'certificate_validity_months' => 12], $h)->assertCreated()->json('data.id');
    $s = $this->postJson('/api/v1/records/CAP/training_sessions', ['training_id' => $t, 'session_number' => 1, 'starts_at' => now()->subHours(3)->toIso8601String(),
        'ends_at' => now()->subHour()->toIso8601String(), 'site_id' => $site], $h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/CAP/training_attendances', ['session_id' => $s, 'worker_id' => $a, 'hours' => 2, 'check_in_method' => 'list'], $h)->assertCreated();
    $this->postJson('/api/v1/records/CAP/training_attendances', ['session_id' => $s, 'worker_id' => $b, 'hours' => 2], $h)->assertCreated();   // sin firma
    $this->postJson('/api/v1/records/CAP/training_attendances', ['session_id' => $s, 'worker_id' => $c, 'hours' => 1, 'check_in_method' => 'list'], $h)->assertCreated();   // 50 %

    // RN-CAP-16: otra sesión a la misma hora no admite a la misma persona.
    $t2 = $this->postJson('/api/v1/records/CAP/trainings', ['title' => 'Primeros auxilios', 'training_type' => 'specific', 'modality' => 'in_person', 'duration_minutes' => 60], $h)->json('data.id');
    $s2 = $this->postJson('/api/v1/records/CAP/training_sessions', ['training_id' => $t2, 'session_number' => 1, 'starts_at' => now()->subHours(2)->toIso8601String(),
        'ends_at' => now()->subMinutes(30)->toIso8601String(), 'site_id' => $site], $h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/CAP/training_attendances', ['session_id' => $s2, 'worker_id' => $a, 'check_in_method' => 'list'], $h)
        ->assertStatus(422)->assertJsonValidationErrors('worker_id');

    $this->patchJson("/api/v1/records/CAP/training_sessions/{$s}", ['status' => 'held'], $h)->assertOk();
    $r = $this->postJson("/api/v1/cap/trainings/{$t}/certificates", [], $h)->assertOk();
    expect($r->json('data.issued'))->toBe(1)->and(implode(' ', $r->json('data.skipped')))->toContain('sin firma')->toContain('menos del 80');

    // RN-CAP-11: el validado no se corrige; se reemite.
    $cert = $this->getJson("/api/v1/records/CAP/training_certificates?f[training_id]={$t}", $h)->json('data.0.id');
    $this->patchJson("/api/v1/records/CAP/training_certificates/{$cert}", ['holder_name' => 'Ana Prueba Corregida'], $h);
    expect($this->getJson("/api/v1/records/CAP/training_certificates/{$cert}", $h)->json('data.holder_name'))->not->toBe('Ana Prueba Corregida');
    $new = $this->postJson("/api/v1/cap/certificates/{$cert}/reissue", ['reason' => 'Nombre mal escrito', 'holder_name' => 'Ana Prueba Corregida'], $h)
        ->assertCreated()->assertJsonPath('data.replaces_certificate_id', $cert)->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne('select status from training_certificates where id = ?', [$cert])->status)->toBe('voided')
        ->and(DB::selectOne('select holder_name from training_certificates where id = ?', [$new])->holder_name)->toBe('Ana Prueba Corregida');
});
