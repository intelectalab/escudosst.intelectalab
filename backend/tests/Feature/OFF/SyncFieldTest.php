<?php

// App offline (M-OFF, docs/16 §2, docs/08 §13): eventos SST con sus personas sin señal, PETAR en campo (inicio, suspensión,
// cierre y verificaciones; la solicitud y la aprobación siguen en línea) y GET /sync/changes con marca, páginas y lápidas.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $db = DB::connection('platform');
    $this->flags = $db->select("select id, mode, source from feature_flags where company_id = ? and module_code in ('OFF', 'ACC', 'PTR', 'ACT')", [HUAYLAS_ID]);
    $db->update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('OFF', 'ACC', 'PTR', 'ACT')", [HUAYLAS_ID]);
    \App\Core\Auth\Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    $db = DB::connection('platform');
    foreach ($this->flags as $f) {
        $db->update('update feature_flags set mode = ?, source = ? where id = ?', [$f->mode, $f->source, $f->id]);
    }
    \App\Core\Auth\Permissions::flush();
});

function sfAs(callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    try {
        return $fn();
    } finally {
        DB::select("select set_config('app.company_id', '', false)");
    }
}

function sfOp(string $entity, array $payload, array $extra = []): array
{
    return $extra + ['idempotency_key' => (string) Str::uuid7(), 'op' => 'create', 'entity' => $entity, 'entity_id' => (string) Str::uuid7(),
        'payload' => $payload, 'created_at_device' => now()->subMinutes(20)->toIso8601String()];
}

function sfBatch(array $ops, string $device = 'pwa-campo-1'): array
{
    return ['batch_uuid' => (string) Str::uuid7(), 'device_uid' => $device, 'platform' => 'web_pwa', 'operations' => $ops];
}

function sfPermit(string $status): string
{
    return sfAs(function () use ($status) {
        $site = DB::selectOne("insert into sites (company_id, code, name) values (?, ?, 'Sede de campo') returning id", [HUAYLAS_ID, 'SF-'.substr(uniqid(), -6)])->id;
        $type = DB::selectOne("insert into permit_types (company_id, code, name, category, validity_hours, requires_ats, requires_gas_test, checklist, approval_chain)
                               values (?, ?, 'Trabajo en altura SF', 'work_at_height', 8, false, false, '[]'::jsonb, '[]'::jsonb) returning id", [HUAYLAS_ID, 'SF-'.substr(uniqid(), -6)])->id;

        return DB::selectOne("insert into work_permits (company_id, code, permit_type_id, site_id, work_description, valid_from, valid_until, status, crew, activated_at, closed_at, area_left_safe)
                              values (?, ?, ?, ?, 'Soldadura de baranda', now() - interval '1 minute', now() + interval '6 hours', ?, '[{\"name\": \"Vigía SF\", \"role\": \"vigía\"}]'::jsonb,
                                      case when ?::text in ('active', 'closed') then now() end, case when ?::text = 'closed' then now() end, case when ?::text = 'closed' then true end) returning id",
            [HUAYLAS_ID, 'PTR-SF-'.substr(uniqid(), -6), $type, $site, $status, $status, $status, $status])->id;
    });
}

it('registra sin señal el evento con sus personas, traduce el id del evento y marca el testigo repetido', function () {
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'SF-ACC-'.substr(uniqid(), -4), 'name' => 'Sede del evento offline'], $this->h)->assertCreated()->json('data.id');
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '72727201', 'first_name' => 'Teo', 'paternal_last_name' => 'Testigo'], $this->h)->assertCreated()->json('data.id');
    $ev = sfOp('sst_events', ['event_type' => 'incident', 'occurred_at' => now()->subHour()->toIso8601String(), 'site_id' => $site,
        'description' => 'Caída de material desde andamio sin señal', 'origin' => 'app']);
    $p1 = sfOp('event_people', ['event_id' => $ev['entity_id'], 'person_role' => 'witness', 'worker_id' => $w, 'external_person_name' => 'Teo Testigo']);
    $p2 = sfOp('event_people', ['event_id' => $ev['entity_id'], 'person_role' => 'witness', 'worker_id' => $w, 'external_person_name' => 'Teo Testigo']);
    $res = $this->postJson('/api/v1/sync/batches', sfBatch([$ev, $p1, $p2]), $this->h)->assertOk();
    expect($res->json('data.results.0.result'))->toBe('applied', (string) json_encode($res->json()))
        ->and($res->json('data.results.1.result'))->toBe('applied')
        ->and($res->json('data.results.2.result'))->toBe('conflict')
        ->and($res->json('data.results.2.conflict_type'))->toBe('duplicate');
    $id = $res->json('data.results.0.entity_id');
    $e = $this->getJson("/api/v1/records/ACC/sst_events/{$id}", $this->h)->assertOk()->json('data');
    expect($e['code'])->toStartWith('ACC-');
    $row = DB::selectOne('select client_created_at, idempotency_key from sst_events where id = ?', [$id]);
    expect($row->idempotency_key)->toBe($ev['idempotency_key'])->and($row->client_created_at)->not->toBeNull();
    expect((int) DB::selectOne('select count(*) as n from event_people where event_id = ? and deleted_at is null', [$id])->n)->toBe(1);

    // Reenviado por reintento: no duplica.
    $again = $this->postJson('/api/v1/sync/batches', sfBatch([$ev]), $this->h)->assertOk();
    expect($again->json('data.results.0.replay'))->toBeTrue()->and($again->json('data.results.0.entity_id'))->toBe($id);
});

it('el PETAR en campo se inicia, se verifica, se suspende y se reanuda sin señal; la solicitud y lo demás siguen en línea', function () {
    $p = sfPermit('approved');
    $v = (int) sfAs(fn () => DB::selectOne('select version from work_permits where id = ?', [$p])->version);
    $start = sfOp('work_permits', ['status' => 'active'], ['op' => 'update', 'entity_id' => $p, 'base_version' => $v]);
    $check = sfOp('permit_checks', ['work_permit_id' => $p, 'check_phase' => 'pre_start', 'item_code' => 'C1', 'question' => '¿Extintor a la mano?', 'answer' => 'yes']);
    $create = sfOp('work_permits', ['work_description' => 'Permiso nuevo sin señal']);
    $edit = sfOp('work_permits', ['status' => 'active', 'work_description' => 'Otra tarea'], ['op' => 'update', 'entity_id' => $p, 'base_version' => $v]);
    $approve = sfOp('work_permits', ['status' => 'approved'], ['op' => 'update', 'entity_id' => $p, 'base_version' => $v]);
    $res = $this->postJson('/api/v1/sync/batches', sfBatch([$start, $check, $create, $edit, $approve]), $this->h)->assertOk();
    expect($res->json('data.results.0.result'))->toBe('applied', (string) json_encode($res->json()))
        ->and($res->json('data.results.1.result'))->toBe('applied')
        ->and($res->json('data.results.2.result'))->toBe('rejected')
        ->and($res->json('data.results.2.reason'))->toContain('con conexión')
        ->and($res->json('data.results.3.result'))->toBe('rejected')
        ->and($res->json('data.results.4.result'))->toBe('rejected');
    expect(DB::selectOne('select status from work_permits where id = ?', [$p])->status)->toBe('active');
    $now = (int) $res->json('data.results.0.version');

    // Suspendido en el servidor mientras el equipo estaba sin señal: el cierre con la versión vieja no pisa nada.
    $this->postJson("/api/v1/ptr/work-permits/{$p}/suspend", ['reason' => 'Viento fuerte'], $this->h)->assertOk();
    $close = sfOp('work_permits', ['status' => 'closed', 'area_left_safe' => true, 'closure_notes' => 'Área limpia'], ['op' => 'update', 'entity_id' => $p, 'base_version' => $now]);
    $c = $this->postJson('/api/v1/sync/batches', sfBatch([$close]), $this->h)->assertOk();
    expect($c->json('data.results.0.result'))->toBe('conflict')->and($c->json('data.results.0.conflict_type'))->toBe('version_mismatch');
    expect(DB::selectOne('select status from work_permits where id = ?', [$p])->status)->toBe('suspended');

    // Con la versión vigente: el cierre vuelve a validarse en el servidor (falta la firma del supervisor: rechazado con su motivo, RN-OFF-08)
    // y la reanudación del trabajo se aplica.
    $cur = (int) sfAs(fn () => DB::selectOne('select version from work_permits where id = ?', [$p])->version);
    $closeNow = sfOp('work_permits', ['status' => 'closed', 'area_left_safe' => true, 'closure_notes' => 'Área limpia'], ['op' => 'update', 'entity_id' => $p, 'base_version' => $cur]);
    $rej = $this->postJson('/api/v1/sync/batches', sfBatch([$closeNow]), $this->h)->assertOk();
    expect($rej->json('data.results.0.result'))->toBe('rejected')->and($rej->json('data.results.0.reason'))->toContain('firma');
    $resume = sfOp('work_permits', ['status' => 'active'], ['op' => 'update', 'entity_id' => $p, 'base_version' => $cur]);
    $okRes = $this->postJson('/api/v1/sync/batches', sfBatch([$resume]), $this->h);
    expect($okRes->json('data.results.0.result'))->toBe('applied', (string) json_encode($okRes->json()));
    expect(sfAs(fn () => DB::selectOne('select status from work_permits where id = ?', [$p])->status))->toBe('active');
});

it('entrega los cambios del servidor desde una marca, por páginas y con lápidas para lo borrado', function () {
    $this->getJson('/api/v1/sync/changes', $this->h)->assertStatus(422);
    $t0 = DB::selectOne("select (now() - interval '1 second')::text as t")->t;
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'SF-CH-'.substr(uniqid(), -4), 'name' => 'Sede de los cambios'], $this->h)->assertCreated()->json('data.id');
    $rep = $this->postJson('/api/v1/sync/batches', sfBatch([sfOp('substandard_reports', ['report_type' => 'unsafe_condition', 'description' => 'Cable pelado en tablero'])]), $this->h)
        ->json('data.results.0.entity_id');
    $permit = sfPermit('active');
    $closed = sfPermit('closed');

    $url = fn (?string $since, int $limit = 500) => '/api/v1/sync/changes?device_id=pwa-campo-1&limit='.$limit.($since !== null ? '&since='.urlencode($since) : '');
    $r = $this->getJson($url($t0), $this->h)->assertOk()->json('data');
    $by = collect($r['changes'])->keyBy(fn ($c) => $c['entity'].':'.$c['id']);
    expect($by["sites:{$site}"]['op'])->toBe('upsert')->and($by["sites:{$site}"]['data']['name'])->toBe('Sede de los cambios')
        ->and($by["substandard_reports:{$rep}"]['data'])->toHaveKeys(['id', 'version', 'status'])
        ->and($by["work_permits:{$permit}"]['data']['status'])->toBe('active')
        ->and($by["work_permits:{$permit}"]['data']['crew'][0]['name'])->toBe('Vigía SF')
        ->and($by["work_permits:{$closed}"]['op'])->toBe('delete')
        ->and($r['has_more'])->toBeFalse()->and($r['next_since'])->toBeString();
    expect(strtolower((string) json_encode($r)))->not->toContain('fitness')->not->toContain('diagnos');

    // Por páginas: cada página sigue donde quedó la anterior, sin repetir.
    $p1 = $this->getJson($url($t0, 1), $this->h)->assertOk()->json('data');
    $p2 = $this->getJson($url($p1['next_since'], 1), $this->h)->assertOk()->json('data');
    expect($p1['has_more'])->toBeTrue()->and($p1['changes'])->toHaveCount(1)->and($p2['changes'])->toHaveCount(1)
        ->and($p2['changes'][0]['entity'].$p2['changes'][0]['id'])->not->toBe($p1['changes'][0]['entity'].$p1['changes'][0]['id']);

    // Lo archivado llega como lápida desde la última marca.
    $this->deleteJson("/api/v1/records/TEN/sites/{$site}", [], $this->h)->assertSuccessful();
    $after = collect($this->getJson($url($r['next_since']), $this->h)->assertOk()->json('data.changes'))->first(fn ($c) => $c['entity'] === 'sites' && $c['id'] === $site);
    expect($after['op'])->toBe('delete')->and($after)->not->toHaveKey('data');

    // Sin marca (descarga inicial) no hay lápidas; una marca inválida se rechaza.
    $full = collect($this->getJson($url(null), $this->h)->assertOk()->json('data.changes'));
    expect($full->where('op', 'delete'))->toHaveCount(0);
    $this->getJson($url('no-es-marca'), $this->h)->assertStatus(422);
});

it('un dispositivo revocado no recibe cambios y se le ordena borrar sus datos', function () {
    $this->postJson('/api/v1/sync/devices', ['device_uid' => 'pwa-cambios-rev', 'platform' => 'web_pwa'], $this->h)->assertOk();
    DB::update("update devices set status = 'revoked', revoked_at = now() where device_uid = 'pwa-cambios-rev'");
    $this->getJson('/api/v1/sync/changes?device_id=pwa-cambios-rev', $this->h)->assertStatus(403)->assertJsonPath('wipe', true);
});

it('el vigía con rol trabajador suspende sin señal el permiso en que participa y solo recibe sus permisos', function () {
    $me = DB::connection('platform')->selectOne('select id from users where email = ?', [CORPORATIVO_EMAIL])->id;
    $watcher = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '72727211', 'first_name' => 'Vito', 'paternal_last_name' => 'Vigía'], $this->h)->assertCreated()->json('data.id');
    sfAs(fn () => DB::update('update workers set user_id = ? where id = ?', [$me, $watcher]));
    $mine = sfPermit('active');
    $other = sfPermit('active');
    sfAs(fn () => DB::update("update work_permits set crew = ?::jsonb where id = ?", [json_encode([['worker_id' => $watcher, 'name' => 'Vito Vigía', 'role' => 'watcher']]), $mine]));
    sfAs(fn () => DB::update("update memberships set role_id = (select id from roles where account_id is null and code = 'worker') where user_id = ? and company_id = ?", [$me, HUAYLAS_ID]));
    app('auth')->forgetGuards();
    \App\Core\Auth\Permissions::flush();

    $permits = collect($this->getJson('/api/v1/sync/changes?device_id=pwa-vigia&since='.urlencode(DB::selectOne("select (now() - interval '1 second')::text as t")->t), $this->h)
        ->assertOk()->json('data.changes'))->where('entity', 'work_permits')->pluck('id');
    expect($permits)->toContain($mine)->not->toContain($other);

    $v = (int) sfAs(fn () => DB::selectOne('select version from work_permits where id = ?', [$mine])->version);
    $suspend = fn (string $id, int $ver) => sfOp('work_permits', ['status' => 'suspended', 'suspended_reason' => 'Olor a gas'], ['op' => 'update', 'entity_id' => $id, 'base_version' => $ver]);
    $res = $this->postJson('/api/v1/sync/batches', sfBatch([$suspend($mine, $v), $suspend($other, $v)], 'pwa-vigia'), $this->h)->assertOk();
    expect($res->json('data.results.0.result'))->toBe('applied', (string) json_encode($res->json()))
        ->and($res->json('data.results.1.result'))->toBe('rejected');
    expect(sfAs(fn () => DB::selectOne('select status, suspended_reason from work_permits where id = ?', [$mine]))->status)->toBe('suspended');
});
