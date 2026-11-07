<?php

// Conexión con el CRM (SCR-WEB-63/64, RN-WEB-78 a 81): se encola al crear un interesado, se envía firmado con HMAC,
// los fallos se reintentan con espera creciente, el secreto nunca se muestra y Soporte no ve la conexión.

use App\Modules\WEB\Services\CrmService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    putenv('CRM_TEST_SECRET=secreto-de-prueba');
    $_ENV['CRM_TEST_SECRET'] = 'secreto-de-prueba';
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

function crmOn(): array
{
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    test()->patchJson('/api/v1/internal/site/crm', ['provider' => 'generic_webhook', 'name' => 'Mi CRM', 'base_url' => 'http://crm.test', 'secret_ref' => 'env://CRM_TEST_SECRET', 'entities' => ['marketing_lead']], $h)
        ->assertStatus(422)->assertJsonValidationErrors('base_url');
    test()->patchJson('/api/v1/internal/site/crm', ['provider' => 'generic_webhook', 'name' => 'Mi CRM', 'base_url' => 'https://crm.test/hook', 'secret_ref' => 'env://CRM_TEST_SECRET',
        'entities' => ['marketing_lead'], 'mapping' => ['marketing_lead' => ['full_name' => 'nombre', 'ip_hash' => 'x']]], $h)->assertOk();
    test()->postJson('/api/v1/internal/site/crm/activate', [], $h)->assertOk();

    return $h;
}

it('envía el interesado firmado, con el mapeo, y no muestra el secreto', function () {
    Http::fake(['crm.test/*' => Http::response(['id' => 'CRM-77'], 201)]);
    $h = crmOn();
    $this->postJson('/api/v1/public/newsletter', ['email' => 'lead@crm.test', 'full_name' => 'Lucía CRM', 'topics' => ['regulations'], 'privacy_consent' => true])->assertCreated();

    expect(app(CrmService::class)->processDue())->toBe(1);
    Http::assertSent(function (HttpRequest $r) {
        [$t, $sig] = array_map(fn ($p) => explode('=', $p, 2)[1], explode(',', $r->header('X-Escudo-Signature')[0]));

        return $r['event'] === 'marketing_lead.create' && $r['data']['nombre'] === 'Lucía CRM' && ! isset($r['data']['x']) && ! isset($r['data']['ip_hash'])
            && hash_equals(hash_hmac('sha256', $t.'.'.$r->body(), 'secreto-de-prueba'), $sig);
    });
    $d = $this->getJson('/api/v1/internal/site/crm', $h)->assertOk()->json('data');
    expect($d['events'][0]['status'])->toBe('sent')->and($d['events'][0]['external_id'])->toBe('CRM-77')
        ->and($d['connection']['secret_ref'])->toBe('env://•••')->and(json_encode($d))->not->toContain('secreto-de-prueba')->not->toContain('CRM_TEST_SECRET');
});

it('un fallo se reintenta con espera; el reintento manual usa la misma clave; Soporte no entra', function () {
    Http::fake(['crm.test/*' => Http::sequence()->push('caído', 500)->push(['ok' => true], 200)]);
    $h = crmOn();
    $this->postJson('/api/v1/public/newsletter', ['email' => 'otro@crm.test', 'topics' => ['sunafil'], 'privacy_consent' => true])->assertCreated();
    $svc = app(CrmService::class);
    $svc->processDue();
    $e = DB::connection('platform')->selectOne("select id, status, attempts, next_attempt_at, idempotency_key from crm_sync_events order by created_at desc limit 1");
    expect($e->status)->toBe('failed')->and($e->attempts)->toBe(1)->and($e->next_attempt_at)->not->toBeNull();
    expect($svc->processDue())->toBe(0);   // aún no toca

    $this->postJson('/api/v1/internal/site/crm/events/retry', ['ids' => [$e->id]], $h)->assertOk();
    $after = DB::connection('platform')->selectOne('select status, idempotency_key from crm_sync_events where id = ?', [$e->id]);
    expect($after->status)->toBe('sent')->and($after->idempotency_key)->toBe($e->idempotency_key);
    Http::assertSent(fn (HttpRequest $r) => $r->header('Idempotency-Key')[0] === $e->idempotency_key);

    $this->postJson('/api/v1/internal/site/crm/events/skip', ['ids' => [$e->id]], $h)->assertStatus(422)->assertJsonValidationErrors('reason');

    $admin = DB::connection('platform')->selectOne('select id from users where email = ?', [ADMIN_EMAIL])->id;
    DB::connection('pgsql')->select("select set_config('app.user_id', ?, false)", [$admin]);
    DB::connection('pgsql')->update("update users set platform_role = 'support' where id = ?", [$admin]);
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/internal/site/crm', $h)->assertForbidden();
});

it('el Libro de Reclamaciones nunca es una entidad del CRM', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $this->patchJson('/api/v1/internal/site/crm', ['provider' => 'generic_webhook', 'name' => 'Mi CRM', 'entities' => ['consumer_complaint']], $h)->assertStatus(422)->assertJsonValidationErrors('entities.0');
    expect(array_keys(CrmService::ENTITIES))->not->toContain('consumer_complaint');
});

it('Fidelia recibe cada interesado nuevo como lead, toma el 409 de correo repetido como enlazado y descarta los cambios', function () {
    Http::fake([
        'crm.intelectalab.test/api/v1/ext/leads?*' => Http::response(['error' => false, 'data' => []], 200),
        'crm.intelectalab.test/api/v1/ext/leads' => Http::sequence()
            ->push(['error' => false, 'message' => 'Lead creado', 'data' => ['id' => 'fid-1']], 201)
            ->push(['error' => true, 'message' => 'Ya existe un lead con ese correo.', 'data' => ['id' => 'fid-1']], 409),
    ]);
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $this->patchJson('/api/v1/internal/site/crm', ['provider' => 'fidelia', 'name' => 'Fidelia', 'base_url' => 'https://crm.intelectalab.test/api/v1/ext/leads', 'secret_ref' => 'env://CRM_TEST_SECRET', 'entities' => ['marketing_lead', 'demo_request']], $h)->assertOk();
    $this->postJson('/api/v1/internal/site/crm/test', [], $h)->assertOk()->assertJsonPath('data.ok', true);
    $this->postJson('/api/v1/internal/site/crm/activate', [], $h)->assertOk();

    $this->postJson('/api/v1/public/newsletter', ['email' => 'rosa@fidelia.test', 'full_name' => 'Rosa María Pérez', 'topics' => ['regulations'], 'privacy_consent' => true])->assertCreated();
    DB::connection('platform')->update("update marketing_leads set phone = '999888777' where email = 'rosa@fidelia.test'");   // un cambio
    app(CrmService::class)->processDue();

    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r['first_name'] === 'Rosa' && $r['last_name'] === 'María Pérez' && $r['email'] === 'rosa@fidelia.test'
        && $r['source'] === 'EscudoSST web' && str_contains($r['note'], 'boletín') && $r->hasHeader('Authorization', 'Bearer secreto-de-prueba'));
    $ev = collect(DB::connection('platform')->select('select action, status, external_id from crm_sync_events order by created_at'));
    expect($ev->firstWhere('action', 'create')->status)->toBe('sent')->and($ev->firstWhere('action', 'create')->external_id)->toBe('fid-1')
        ->and($ev->firstWhere('action', 'update')->status)->toBe('skipped');
});
