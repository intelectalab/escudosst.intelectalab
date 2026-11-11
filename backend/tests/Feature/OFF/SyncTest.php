<?php

// App offline (M-OFF, docs/16): lotes idempotentes, ids del dispositivo, conflictos sin pisar versiones,
// dispositivo revocado, reloj adelantado y complemento requerido.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $db = DB::connection('platform');
    $this->flags = $db->select("select id, mode, source from feature_flags where company_id = ? and module_code in ('OFF', 'ACT', 'CH5')", [HUAYLAS_ID]);
    $db->update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('OFF', 'ACT', 'CH5')", [HUAYLAS_ID]);
    \App\Core\Auth\Permissions::flush();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    $db = DB::connection('platform');
    foreach ($this->flags as $f) {
        $db->update('update feature_flags set mode = ?, source = ? where id = ?', [$f->mode, $f->source, $f->id]);
    }
    \App\Core\Auth\Permissions::flush();
});

function offH(): array
{
    return authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
}

function offOp(string $entity, array $payload, array $extra = []): array
{
    return $extra + [
        'idempotency_key' => (string) Str::uuid7(),
        'op' => 'create',
        'entity' => $entity,
        'entity_id' => (string) Str::uuid7(),
        'payload' => $payload,
        'created_at_device' => now()->subMinutes(30)->toIso8601String(),
    ];
}

function offBatch(array $ops, string $device = 'pwa-test-1'): array
{
    return ['batch_uuid' => (string) Str::uuid7(), 'device_uid' => $device, 'platform' => 'web_pwa', 'operations' => $ops];
}

it('exige el complemento OFF', function () {
    DB::connection('platform')->update("update feature_flags set mode = 'locked' where company_id = ? and module_code = 'OFF'", [HUAYLAS_ID]);
    \App\Core\Auth\Permissions::flush();
    $this->postJson('/api/v1/sync/batches', offBatch([offOp('substandard_reports', ['report_type' => 'unsafe_condition', 'description' => 'Cable suelto'])]), offH())
        ->assertStatus(403)->assertJsonPath('type', 'module_not_in_plan');
});

it('un reporte enviado dos veces por reintento queda una sola vez', function () {
    $op = offOp('substandard_reports', ['report_type' => 'unsafe_condition', 'description' => 'Escalera sin barandas en almacén offline']);
    $first = $this->postJson('/api/v1/sync/batches', offBatch([$op]), offH())->assertOk();
    expect($first->json('data.results.0.result'))->toBe('applied', (string) json_encode($first->json()));
    $id = $first->json('data.results.0.entity_id');

    $again = $this->postJson('/api/v1/sync/batches', offBatch([$op]), offH())->assertOk();
    expect($again->json('data.results.0.replay'))->toBeTrue();
    expect($again->json('data.results.0.entity_id'))->toBe($id);

    $n = DB::selectOne("select count(*) as n from substandard_reports where description = 'Escalera sin barandas en almacén offline'")->n;
    expect((int) $n)->toBe(1);
    $row = DB::selectOne('select idempotency_key, sync_batch_id, client_created_at from substandard_reports where id = ?', [$id]);
    expect($row->idempotency_key)->toBe($op['idempotency_key']);
    expect($row->sync_batch_id)->not->toBeNull();
    expect($row->client_created_at)->not->toBeNull();
});

it('traduce los ids del dispositivo y marca la asistencia repetida para revisión', function () {
    $h = offH();
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '71717171', 'first_name' => 'Lucía', 'paternal_last_name' => 'Campo'], $h)->json('data.id');
    $talk = offOp('safety_talks', ['topic' => 'Orden y limpieza (offline)', 'talk_on' => now()->toDateString()]);
    $att = offOp('talk_attendances', ['safety_talk_id' => $talk['entity_id'], 'worker_id' => $w]);
    $res = $this->postJson('/api/v1/sync/batches', offBatch([$talk, $att]), $h)->assertOk();
    expect($res->json('data.applied'))->toBe(2, (string) json_encode($res->json()));
    $serverTalk = $res->json('data.results.0.entity_id');
    expect(DB::selectOne('select safety_talk_id from talk_attendances where id = ?', [$res->json('data.results.1.entity_id')])->safety_talk_id)->toBe($serverTalk);

    $dup = offOp('talk_attendances', ['safety_talk_id' => $talk['entity_id'], 'worker_id' => $w]);
    $r2 = $this->postJson('/api/v1/sync/batches', offBatch([$dup]), $h)->assertOk();
    expect($r2->json('data.results.0.result'))->toBe('conflict');
    expect($r2->json('data.results.0.conflict_type'))->toBe('duplicate');
    expect((int) DB::selectOne('select count(*) as n from talk_attendances where safety_talk_id = ?', [$serverTalk])->n)->toBe(1);
});

it('una edición con versión vieja crea un conflicto y no pisa el servidor; se resuelve aplicando el dispositivo', function () {
    $h = offH();
    $talk = $this->postJson('/api/v1/records/CH5/safety_talks', ['topic' => 'Uso de EPP', 'talk_on' => now()->toDateString()], $h)->assertCreated()->json('data.id');
    $version = (int) DB::selectOne('select version from safety_talks where id = ?', [$talk])->version;
    $this->patchJson("/api/v1/records/CH5/safety_talks/{$talk}", ['topic' => 'Uso de EPP (servidor)'], $h)->assertOk();

    $op = offOp('safety_talks', ['topic' => 'Uso de EPP (teléfono)'], ['op' => 'update', 'entity_id' => $talk, 'base_version' => $version]);
    $res = $this->postJson('/api/v1/sync/batches', offBatch([$op]), $h)->assertOk();
    expect($res->json('data.results.0.result'))->toBe('conflict', (string) json_encode($res->json()));
    expect($res->json('data.results.0.conflict_type'))->toBe('version_mismatch');
    expect(DB::selectOne('select topic from safety_talks where id = ?', [$talk])->topic)->toBe('Uso de EPP (servidor)');

    $conflict = $this->getJson('/api/v1/sync/conflicts', $h)->assertOk()->json('data.0');
    expect($conflict['entity_id'])->toBe($talk);
    $this->postJson("/api/v1/sync/conflicts/{$conflict['id']}/resolve", ['resolution' => 'client_wins'], $h)->assertOk();
    expect(DB::selectOne('select topic from safety_talks where id = ?', [$talk])->topic)->toBe('Uso de EPP (teléfono)');
    $this->postJson("/api/v1/sync/conflicts/{$conflict['id']}/resolve", ['resolution' => 'server_wins'], $h)->assertStatus(409);

    // Con la versión actual, la edición se aplica.
    $current = (int) DB::selectOne('select version from safety_talks where id = ?', [$talk])->version;
    $ok = offOp('safety_talks', ['topic' => 'Uso de EPP (final)'], ['op' => 'update', 'entity_id' => $talk, 'base_version' => $current]);
    expect($this->postJson('/api/v1/sync/batches', offBatch([$ok]), $h)->json('data.results.0.result'))->toBe('applied');
});

it('rechaza con motivo lo que no pasa las validaciones y aplica el resto; marca el reloj adelantado', function () {
    $bad = offOp('substandard_reports', ['report_type' => 'unsafe_act']);
    $future = offOp('substandard_reports', ['report_type' => 'near_miss', 'description' => 'Casi caída en rampa'], ['created_at_device' => now()->addHours(3)->toIso8601String()]);
    $other = offOp('findings', ['title' => 'x']);
    $res = $this->postJson('/api/v1/sync/batches', offBatch([$bad, $future, $other]), offH())->assertOk();
    expect($res->json('data.status'))->toBe('partially_applied', (string) json_encode($res->json()));
    expect($res->json('data.results.0.result'))->toBe('rejected');
    expect($res->json('data.results.0.reason'))->toBeString();
    expect($res->json('data.results.1.result'))->toBe('applied');
    expect($res->json('data.results.1.needs_review'))->toBeTrue();
    expect($res->json('data.results.2.result'))->toBe('rejected');
});

it('un dispositivo revocado no sincroniza y recibe la orden de borrar sus datos', function () {
    $h = offH();
    $this->postJson('/api/v1/sync/devices', ['device_uid' => 'pwa-revocar', 'platform' => 'web_pwa'], $h)->assertOk()->assertJsonPath('data.device.status', 'active');
    DB::update("update devices set status = 'revoked', revoked_at = now() where device_uid = 'pwa-revocar'");
    $this->postJson('/api/v1/sync/batches', offBatch([offOp('substandard_reports', ['report_type' => 'suggestion', 'description' => 'Más luz'])], 'pwa-revocar'), $h)
        ->assertStatus(403)->assertJsonPath('type', 'device_revoked')->assertJsonPath('wipe', true);
});
