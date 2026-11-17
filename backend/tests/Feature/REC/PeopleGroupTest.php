<?php

// Personas: M-CAP (firma en pantalla, vencimientos y reentrenamiento, reporte, verificación pública por empresa y tema, disponibilidad del
// capacitador), M-FOT (EPP y documentos como requisitos, matriz por puesto, verificación en campo, lote), M-EMO (vencidos y órdenes en lote),
// M-VMD (indicadores agregados con grupo mínimo, restricción a la aptitud, notas compartidas) y M-VUL (adecuaciones en el expediente).

use Illuminate\Support\Facades\DB;

function pgAs(callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    try {
        return $fn();
    } finally {
        DB::select("select set_config('app.company_id', '', false)");
    }
}

afterEach(function () {
    app('auth')->forgetGuards();
    \App\Core\Auth\Permissions::flush();
    $GLOBALS['sus_tokens'] = [];
});

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    pgAs(fn () => DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('CAP', 'FOT', 'EMO', 'VMD', 'EPP') and deleted_at is null", [HUAYLAS_ID]));
    app('auth')->forgetGuards();
    \App\Core\Auth\Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->me = pgAs(fn () => DB::selectOne('select id from app_auth_lookup_user(?)', [CORPORATIVO_EMAIL])->id);
});

const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

it('firma la asistencia en pantalla, controla vencimientos, reentrena, reporta y verifica públicamente solo si la empresa y el tema lo permiten', function () {
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '76767676', 'first_name' => 'Rosa', 'paternal_last_name' => 'Firma'], $this->h)->assertCreated()->json('data.id');
    $t = $this->postJson('/api/v1/records/CAP/trainings', ['title' => 'Trabajo en altura PG', 'training_type' => 'specific', 'certificate_validity_months' => 12, 'public_verification_enabled' => true], $this->h)
        ->assertCreated()->json('data.id');
    $s = $this->postJson('/api/v1/records/CAP/training_sessions', ['training_id' => $t, 'starts_at' => now()->subHours(3)->toIso8601String(), 'ends_at' => now()->subHour()->toIso8601String(), 'status' => 'scheduled'], $this->h)
        ->assertCreated()->json('data.id');
    $a = $this->postJson('/api/v1/records/CAP/training_attendances', ['session_id' => $s, 'worker_id' => $w, 'attended' => false], $this->h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/cap/attendances/{$a}/sign", ['image' => 'data:image/png;base64,AAAA'], $this->h)->assertStatus(422);
    $this->postJson("/api/v1/cap/attendances/{$a}/sign", ['image' => PNG], $this->h)->assertCreated();
    $this->postJson("/api/v1/cap/attendances/{$a}/sign", ['image' => PNG], $this->h)->assertStatus(409);
    expect(pgAs(fn () => DB::selectOne('select attended, check_in_method, signature_id from training_attendances where id = ?', [$a])))->attended->toBeTrue()->check_in_method->toBe('signature');

    // Verificación pública: la empresa la activa; el tema ya la permite.
    $this->getJson('/api/v1/cap/verification', $this->h)->assertOk()->assertJsonPath('data.enabled', false);
    $this->putJson('/api/v1/cap/verification', ['enabled' => true], $this->h)->assertOk();
    $this->patchJson("/api/v1/records/CAP/training_sessions/{$s}", ['status' => 'held'], $this->h)->assertOk();
    $this->postJson("/api/v1/cap/trainings/{$t}/certificates", [], $this->h)->assertSuccessful();
    $cert = pgAs(fn () => DB::selectOne('select id, verification_code, public_verification_enabled from training_certificates where training_id = ? and worker_id = ?', [$t, $w]));
    expect($cert->public_verification_enabled)->toBeTrue();
    $this->putJson('/api/v1/cap/verification', ['enabled' => false], $this->h)->assertOk();
    expect(pgAs(fn () => DB::selectOne('select public_verification_enabled from training_certificates where id = ?', [$cert->id])->public_verification_enabled))->toBeFalse();

    // Vencimientos y reentrenamiento masivo.
    pgAs(fn () => DB::update("update training_certificates set status = 'validated', validated_at = now(), validated_by = ?, expires_on = current_date + 10 where id = ?", [$this->me, $cert->id]));
    $exp = $this->getJson('/api/v1/cap/expirations?days=30', $this->h)->assertOk()->json('data.rows');
    expect(collect($exp)->pluck('worker_id'))->toContain($w);
    $r = $this->postJson('/api/v1/cap/expirations/retrain', ['training_id' => $t, 'worker_ids' => [$w], 'starts_at' => now()->addDays(5)->setTime(9, 0)->toIso8601String(),
        'ends_at' => now()->addDays(5)->setTime(11, 0)->toIso8601String()], $this->h)->assertCreated()->json('data');
    expect($r['invited'])->toBe(1);
    $this->get('/api/v1/cap/reports.xlsx', $this->h)->assertOk();

    // Disponibilidad del capacitador: con un horario registrado que no cubre la sesión, advierte.
    pgAs(fn () => DB::insert("insert into trainer_availability (company_id, trainer_user_id, kind, weekday, start_time, end_time) values (?, ?, 'weekly', 1, '08:00', '09:00')", [HUAYLAS_ID, $this->me]));
    pgAs(fn () => DB::update('update training_sessions set instructor_user_id = ? where id = ?', [$this->me, $r['session_id']]));
    expect($this->getJson("/api/v1/cap/sessions/{$r['session_id']}/trainer-check", $this->h)->assertOk()->json('data.warning'))->toContain('disponibilidad');
});

it('el fotocheck evalúa EPP y documentos, muestra la matriz por puesto, registra la verificación en campo y lista el lote', function () {
    $pos = $this->postJson('/api/v1/records/TEN/positions', ['code' => 'FOT-PG', 'name' => 'Soldador PG'], $this->h)->json('data.id');
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '77667766', 'first_name' => 'Luis', 'paternal_last_name' => 'Fotocheck', 'current_position_id' => $pos], $this->h)
        ->assertCreated()->json('data.id');
    $item = $this->postJson('/api/v1/records/EPP/ppe_items', ['code' => 'CAR-PG', 'name' => 'Careta PG', 'category' => 'eye_face'], $this->h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/EPP/ppe_position_requirements', ['position_id' => $pos, 'ppe_item_id' => $item, 'quantity' => 1, 'is_mandatory' => true], $this->h)->assertCreated();
    $type = pgAs(fn () => DB::selectOne("insert into credential_types (company_id, code, name, applies_to) values (?, 'FT-PG', 'Fotocheck PG', 'workers') returning id", [HUAYLAS_ID])->id);
    pgAs(function () use ($type, $pos) {
        DB::insert("insert into credential_requirements (company_id, credential_type_id, requirement_type, description, is_blocking) values (?, ?, 'ppe_delivery', 'EPP obligatorio', true)", [HUAYLAS_ID, $type]);
        DB::insert("insert into credential_requirements (company_id, credential_type_id, requirement_type, description, worker_document_kind, position_id, is_blocking) values (?, ?, 'document', 'Contrato', 'contract', ?, true)", [HUAYLAS_ID, $type, $pos]);
    });
    $ev = fn () => pgAs(fn () => \App\Modules\REC\Rules\PeopleRules::credentialEvaluation($type, $w, null, null));
    expect(collect($ev()['reasons'])->pluck('type')->all())->toContain('ppe_delivery', 'document');
    pgAs(function () use ($w, $item) {
        DB::insert("insert into ppe_deliveries (company_id, worker_id, ppe_item_id, quantity, delivered_at, status) values (?, ?, ?, 1, now(), 'delivered')", [HUAYLAS_ID, $w, $item]);
        DB::insert("insert into worker_documents (company_id, worker_id, document_kind, title, status) values (?, ?, 'contract', 'Contrato', 'valid')", [HUAYLAS_ID, $w]);
    });
    expect($ev()['reasons'])->toBe([]);

    $m = $this->getJson("/api/v1/fot/matrix?credential_type_id={$type}", $this->h)->assertOk()->json('data');
    expect($m['general'])->toHaveCount(1)->and(collect($m['positions'])->firstWhere('id', $pos)['total'])->toBe(2);
    $cred = pgAs(fn () => DB::selectOne("insert into credentials (company_id, credential_type_id, worker_id, credential_number, token_hash, status, issued_at) values (?, ?, ?, 'FOT-PG-1', decode(md5('fotpg'), 'hex'), 'active', now()) returning id", [HUAYLAS_ID, $type, $w])->id);
    $this->postJson("/api/v1/fot/credentials/{$cred}/verifications", [], $this->h)->assertCreated()->assertJsonPath('data.result', 'enabled');
    expect($this->getJson("/api/v1/fot/credentials/{$cred}/verifications", $this->h)->assertOk()->json('data'))->toHaveCount(1);
    expect($this->getJson("/api/v1/fot/credentials/batch?credential_type_id={$type}", $this->h)->assertOk()->json('data'))->toBe([$cred]);
});

it('lista los EMO vencidos y sin examen por sede y puesto y programa órdenes en lote', function () {
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '78787878', 'first_name' => 'Juan', 'paternal_last_name' => 'Emo'], $this->h)->assertCreated()->json('data.id');
    $d = $this->getJson('/api/v1/emo/due', $this->h)->assertOk()->json('data');
    expect(collect($d['workers'])->firstWhere('id', $w)['state'])->toBe('none')->and($d['by_site'])->not->toBeEmpty();
    // Programa el personal de salud (permiso de crear órdenes).
    pgAs(fn () => DB::update("update memberships set role_id = (select id from roles where code = 'health_staff' and account_id is null) where user_id = ? and company_id = ?", [$this->me, HUAYLAS_ID]));
    $GLOBALS['sus_tokens'] = [];
    app('auth')->forgetGuards();
    \App\Core\Auth\Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $r = $this->postJson('/api/v1/emo/orders/bulk', ['worker_ids' => [$w], 'exam_type' => 'pre_employment', 'scheduled_at' => now()->addDays(3)->toIso8601String(), 'clinic_name' => 'Clínica PG'], $this->h)
        ->assertCreated()->json('data');
    expect($r['created'])->toBe(1);
    expect($this->postJson('/api/v1/emo/orders/bulk', ['worker_ids' => [$w], 'exam_type' => 'pre_employment', 'scheduled_at' => now()->addDays(3)->toIso8601String()], $this->h)->json('data.skipped'))->toHaveCount(1);
});

it('las adecuaciones de los trabajadores vulnerables aparecen en el expediente SUNAFIL', function () {
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '79797979', 'first_name' => 'Eva', 'paternal_last_name' => 'Vul'], $this->h)->assertCreated()->json('data.id');
    pgAs(fn () => DB::insert("insert into work_adjustments (company_id, worker_id, adjustment_category, description, starts_on, status) values (?, ?, 'schedule', 'Turno diurno', current_date - 10, 'active')", [HUAYLAS_ID, $w]));
    pgAs(fn () => DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('SUN', 'VUL') and deleted_at is null", [HUAYLAS_ID]));
    $d = $this->postJson('/api/v1/records/SUN/inspection_dossiers', ['title' => 'Expediente PG', 'period_from' => now()->subMonth()->toDateString(), 'period_to' => now()->toDateString()], $this->h)
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/sun/dossiers/{$d}/build", [], $this->h)->assertOk();
    $item = pgAs(fn () => DB::selectOne("select status from dossier_items where dossier_id = ? and section_code = '17'", [$d]));
    expect($item?->status)->toBe('included');
});
