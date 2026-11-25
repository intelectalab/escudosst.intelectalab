<?php

// docs/10 §1.4 y §1.5: registro de solicitudes ARCO (plazo por parámetro en días hábiles, flujo de estados, retiro por el propio
// trabajador) y registro de brechas de seguridad (plazo de comunicación a la ANPD por parámetro, cierre con causa y medidas).
// docs/10 §4: alerta por accesos anómalos a datos sensibles (muchos trabajadores distintos en poco tiempo).

use App\Core\Auth\Permissions;
use App\Modules\NOT\SstAlerts;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Permissions::flush();
});

afterEach(fn () => Permissions::flush());

/** El Jefe SST de Huaylas pasa a administrador de la cuenta (responsable de los datos personales) dentro de la prueba. */
function prvAdmin(): array
{
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update memberships set role_id = (select id from roles where code = 'account_admin' and account_id is null limit 1)
                 where company_id = ? and user_id = (select id from app_auth_lookup_user(?))", [HUAYLAS_ID, CORPORATIVO_EMAIL]);
    Permissions::flush();

    return $h;
}

function prvParam(string $key): float
{
    return (float) DB::selectOne('select value from legal_parameters where key = ? and company_id is null and deleted_at is null order by valid_from desc limit 1', [$key])->value;
}

it('registra la solicitud ARCO con el plazo del parámetro según el tipo y avisa al administrador', function () {
    $h = prvAdmin();
    expect(json_encode($this->getJson('/api/v1/records/TRB', $h)->assertOk()->json()))->toContain('arco_requests')->toContain('data_breaches');

    $r = $this->postJson('/api/v1/records/TRB/arco_requests', [
        'requester_name' => 'Ana Titular', 'request_type' => 'access', 'channel' => 'email',
        'received_at' => '2026-11-02T09:00:00-05:00', 'description' => 'Pide copia de sus datos personales.',
    ], $h)->assertCreated()->json('data');
    $expected = DB::selectOne('select add_business_days(?::date, ?) as d', ['2026-11-02', (int) prvParam('arco_access_response_business_days')])->d;
    expect($r['status'])->toBe('received')
        ->and($r['legal_parameter_key'])->toBe('arco_access_response_business_days')
        ->and(substr((string) $r['due_on'], 0, 10))->toBe($expected);

    // Rectificación: plazo general.
    $r2 = $this->postJson('/api/v1/records/TRB/arco_requests', [
        'requester_name' => 'Luis Titular', 'request_type' => 'rectification', 'channel' => 'letter',
        'received_at' => '2026-11-02T09:00:00-05:00', 'description' => 'Corrige su dirección.',
    ], $h)->assertCreated()->json('data');
    expect($r2['legal_parameter_key'])->toBe('arco_response_business_days')
        ->and(substr((string) $r2['due_on'], 0, 10))->toBe(DB::selectOne('select add_business_days(?::date, ?) as d', ['2026-11-02', (int) prvParam('arco_response_business_days')])->d);

    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne("select count(*) as n from notifications where event_code = 'trb.arco_received' and subject_id = ?", [$r['id']])->n)->toBeGreaterThan(0);
});

it('sigue el flujo ARCO: prórroga con motivo, atender con respuesta y cerrada no cambia (409 invalid_transition)', function () {
    $h = prvAdmin();
    $id = $this->postJson('/api/v1/records/TRB/arco_requests', [
        'requester_name' => 'Rosa Titular', 'request_type' => 'cancellation', 'channel' => 'in_person', 'description' => 'Pide borrar su celular.',
    ], $h)->assertCreated()->json('data.id');

    $this->patchJson("/api/v1/records/TRB/arco_requests/{$id}", ['status' => 'extension_requested'], $h)->assertStatus(422)->assertJsonPath('errors.extension_reason.0', fn ($m) => str_contains($m, 'motivo'));
    $this->patchJson("/api/v1/records/TRB/arco_requests/{$id}", ['status' => 'extension_requested', 'extension_reason' => 'Hay que revisar el legajo en sede.'], $h)->assertOk();
    $this->patchJson("/api/v1/records/TRB/arco_requests/{$id}", ['status' => 'resolved'], $h)->assertStatus(422);
    $ok = $this->patchJson("/api/v1/records/TRB/arco_requests/{$id}", ['status' => 'resolved', 'resolution' => 'Se borró el celular; los registros SST se conservan bloqueados.'], $h)->assertOk()->json('data');
    expect($ok['resolved_at'])->not->toBeNull();

    $this->patchJson("/api/v1/records/TRB/arco_requests/{$id}", ['description' => 'Otro texto'], $h)
        ->assertStatus(409)->assertJsonPath('type', 'invalid_transition');
    $this->deleteJson("/api/v1/records/TRB/arco_requests/{$id}", [], $h)->assertForbidden();   // el administrador no borra: se conserva
});

it('el trabajador registra su propia solicitud desde su sesión y solo puede retirarla', function () {
    $h = prvAdmin();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $worker = DB::selectOne("insert into workers (company_id, document_number, document_number_hash, first_name, paternal_last_name, status)
                             values (?, ?, decode(md5(random()::text), 'hex'), 'Pedro', 'Titular', 'active') returning id, full_name", [HUAYLAS_ID, (string) random_int(10000000, 99999999)]);
    DB::update("update memberships set role_id = (select id from roles where code = 'worker' and account_id is null limit 1), worker_id = ?
                 where company_id = ? and user_id = (select id from app_auth_lookup_user(?))", [$worker->id, HUAYLAS_ID, CORPORATIVO_EMAIL]);
    Permissions::flush();

    $r = $this->postJson('/api/v1/records/TRB/arco_requests', [
        'requester_name' => 'Otra Persona', 'worker_id' => null, 'request_type' => 'access', 'channel' => 'letter', 'description' => 'Quiero ver mis datos.',
        'assigned_to' => null,
    ], $h)->assertCreated()->json('data');
    expect($r['worker_id'])->toBe($worker->id)->and($r['channel'])->toBe('app')->and($r['requester_name'])->toBe($worker->full_name);

    $this->patchJson("/api/v1/records/TRB/arco_requests/{$r['id']}", ['status' => 'resolved', 'resolution' => 'x'], $h)->assertStatus(409)->assertJsonPath('type', 'invalid_transition');
    $this->patchJson("/api/v1/records/TRB/arco_requests/{$r['id']}", ['status' => 'withdrawn'], $h)->assertOk()->assertJsonPath('data.status', 'withdrawn');
});

it('registra la brecha con el plazo de la ANPD del parámetro y la cierra solo con causa y medidas', function () {
    $h = prvAdmin();
    $hours = prvParam('data_breach_notification_hours');
    $b = $this->postJson('/api/v1/records/TRB/data_breaches', [
        'title' => 'Correo con planilla enviado a un tercero', 'detected_at' => '2026-11-20T10:00:00-05:00', 'detection_source' => 'internal',
        'description' => 'Se envió por error la lista de trabajadores con su celular.', 'includes_sensitive_data' => false, 'affected_people_count' => 40,
    ], $h)->assertCreated()->json('data');
    expect($b['status'])->toBe('detected')->and($b['code'])->toStartWith('BRE-2026-')
        ->and(strtotime($b['anpd_due_at']))->toBe(strtotime('2026-11-20T10:00:00-05:00') + (int) $hours * 3600);

    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne("select count(*) as n from notifications where event_code = 'trb.breach_registered' and subject_id = ?", [$b['id']])->n)->toBeGreaterThan(0);

    // Sin comunicar y con el plazo vencido: aviso crítico al administrador.
    $n = app(AccountsService::class)->withCompany(HUAYLAS_ID, fn (ConnectionInterface $db) => app(SstAlerts::class)->forCompany($db, HUAYLAS_ID, false));
    expect($n)->toBeGreaterThan(0);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne("select count(*) as n from notifications where event_code = 'trb.breach_deadline' and subject_id = ?", [$b['id']])->n)->toBeGreaterThan(0);

    $this->patchJson("/api/v1/records/TRB/data_breaches/{$b['id']}", ['status' => 'closed'], $h)->assertStatus(409)->assertJsonPath('type', 'invalid_transition');
    $this->patchJson("/api/v1/records/TRB/data_breaches/{$b['id']}", ['status' => 'contained', 'containment_measures' => 'Se pidió borrar el correo.'], $h)->assertOk();
    $this->patchJson("/api/v1/records/TRB/data_breaches/{$b['id']}", ['status' => 'notified'], $h)->assertOk()->assertJsonPath('data.anpd_notified_at', fn ($v) => $v !== null);
    $this->patchJson("/api/v1/records/TRB/data_breaches/{$b['id']}", ['status' => 'closed', 'holders_notification_required' => true], $h)->assertStatus(422);
    $this->patchJson("/api/v1/records/TRB/data_breaches/{$b['id']}", [
        'status' => 'closed', 'cause' => 'Envío manual sin revisión.', 'corrective_measures' => 'Doble revisión de adjuntos.',
        'holders_notification_required' => true, 'holders_notified_at' => '2026-11-21T09:00:00-05:00',
    ], $h)->assertOk()->assertJsonPath('data.status', 'closed');
    $this->patchJson("/api/v1/records/TRB/data_breaches/{$b['id']}", ['title' => 'Otro'], $h)->assertStatus(409)->assertJsonPath('type', 'invalid_transition');
    $this->patchJson("/api/v1/records/TRB/data_breaches/{$b['id']}", ['lessons_learned' => 'Capacitación al área.'], $h)->assertOk();
});

it('avisa el acceso anómalo cuando un usuario abre datos sensibles de muchos trabajadores en poco tiempo', function () {
    prvAdmin();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $limit = (int) prvParam('sensitive_access_anomaly_workers');
    $user = DB::selectOne('select id from app_auth_lookup_user(?)', [PROFESIONAL_EMAIL])->id;
    for ($i = 0; $i < $limit; $i++) {
        $w = DB::selectOne("insert into workers (company_id, document_number, document_number_hash, first_name, paternal_last_name, status)
                            values (?, ?, decode(md5(random()::text), 'hex'), ?, 'Anomalía', 'active') returning id", [HUAYLAS_ID, (string) random_int(10000000, 99999999), 'T'.$i])->id;
        DB::insert("insert into sensitive_access_logs (company_id, user_id, role_code, worker_id, resource, action, result, occurred_at)
                    values (?, ?, 'occupational_doctor', ?, 'medical_exam', 'read', 'granted', now() - make_interval(mins => ?))", [HUAYLAS_ID, $user, $w, $i % 30]);
    }

    app(AccountsService::class)->withCompany(HUAYLAS_ID, fn (ConnectionInterface $db) => app(SstAlerts::class)->forCompany($db, HUAYLAS_ID, false));
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $n = DB::selectOne("select body from notifications where event_code = 'aud.sensitive_anomaly' and subject_id = ? order by created_at desc limit 1", [$user]);
    expect($n)->not->toBeNull()->and($n->body)->toContain("{$limit} trabajadores");
});
