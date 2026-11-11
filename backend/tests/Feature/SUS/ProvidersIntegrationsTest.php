<?php

// Panel › Proveedores y claves: copias de seguridad externas configuradas en el sistema (con prueba de subida y borrado),
// WhatsApp con la API Cloud de Meta (verificación y mensaje de prueba) y servicios sin proveedor definido (siguen en
// prueba, con las preguntas para el equipo). Las claves nunca vuelven al navegador. Nada sale a internet (Http::fake).

use App\Modules\SUS\Services\RemoteBackupStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->providerRows = DB::connection('platform')->select('select * from platform_providers');
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    foreach ($this->providerRows as $p) {
        DB::connection('platform')->update('update platform_providers set provider = ?, mode = ?, settings = ?::jsonb, secret_enc = ?, secret_hint = ?, last_tested_at = ?, last_test_ok = ?, last_test_message = ? where code = ?',
            [$p->provider, $p->mode, $p->settings, $p->secret_enc, $p->secret_hint, $p->last_tested_at, $p->last_test_ok === null ? null : ($p->last_test_ok ? 'true' : 'false'), $p->last_test_message, $p->code]);
    }
});

it('copias externas: se guardan en el panel, se prueban con subida y borrado firmados y tienen prioridad sobre el servidor', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    Http::fake(['b2.example.com/*' => Http::response('', 200)]);
    $this->putJson('/api/v1/internal/providers/backups', ['provider' => 'b2', 'mode' => 'live', 'settings' => ['endpoint' => 'https://b2.example.com'], 'secrets' => []], $h)
        ->assertStatus(422)->assertJsonValidationErrors('mode');
    $this->putJson('/api/v1/internal/providers/backups', ['provider' => 'b2', 'mode' => 'live', 'settings' => ['endpoint' => 'ftp://x', 'bucket' => 'b'], 'secrets' => []], $h)
        ->assertStatus(422)->assertJsonValidationErrors('settings.endpoint');
    $this->putJson('/api/v1/internal/providers/backups', ['provider' => 'b2', 'mode' => 'live',
        'settings' => ['endpoint' => 'https://b2.example.com', 'bucket' => 'escudo-copias', 'region' => 'us-west-004', 'retention_days' => '45'],
        'secrets' => ['key_id' => 'KEYPANEL1', 'secret' => 'secreto-panel-9876']], $h)->assertOk()->assertJsonPath('data.live', true);

    $p = collect($this->getJson('/api/v1/internal/providers', $h)->assertOk()->json('data'))->firstWhere('code', 'backups');
    expect($p['secrets_set']['secret'])->toBe('••••9876')->and(json_encode($p))->not->toContain('secreto-panel-9876')
        ->and($p['testable'])->toBeTrue()->and($p['required'])->toBe(['endpoint', 'bucket']);

    $r = $this->postJson('/api/v1/internal/providers/backups/test', [], $h)->assertOk()->json('data');
    expect($r['ok'])->toBeTrue();
    Http::assertSent(fn (Request $q) => $q->method() === 'PUT' && str_starts_with($q->url(), 'https://b2.example.com/escudo-copias/escudo-prueba-conexion/')
        && str_contains($q->header('Authorization')[0] ?? '', 'Credential=KEYPANEL1/'));
    Http::assertSent(fn (Request $q) => $q->method() === 'DELETE' && str_starts_with($q->url(), 'https://b2.example.com/escudo-copias/escudo-prueba-conexion/'));

    // El panel manda sobre las variables del servidor.
    config(['escudo.backups.remote' => ['endpoint' => 'https://env.example.com', 'bucket' => 'env', 'key' => 'K', 'secret' => 'S', 'region' => '', 'retention_days' => 7]]);
    $store = app(RemoteBackupStore::class);
    expect($store->source())->toBe('panel')->and($store->describe())->toBe('b2.example.com / escudo-copias')->and($store->retentionDays())->toBe(45);

    // Desactivada en el panel: vuelve a las variables del servidor.
    $this->putJson('/api/v1/internal/providers/backups', ['provider' => 'b2', 'mode' => 'test', 'settings' => ['endpoint' => 'https://b2.example.com', 'bucket' => 'escudo-copias'], 'secrets' => []], $h)->assertOk();
    expect(app(RemoteBackupStore::class)->source())->toBe('env')->and(app(RemoteBackupStore::class)->retentionDays())->toBe(7);
});

it('copias externas: una prueba fallida informa el error sin la clave', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    Http::fake(['*' => Http::response('<Error><Code>InvalidAccessKeyId</Code></Error>', 403)]);
    $this->putJson('/api/v1/internal/providers/backups', ['provider' => 'other', 'mode' => 'test',
        'settings' => ['endpoint' => 'https://minio.example.com', 'bucket' => 'copias'], 'secrets' => ['key_id' => 'K2', 'secret' => 'clave-que-no-sale']], $h)->assertOk();
    $r = $this->postJson('/api/v1/internal/providers/backups/test', [], $h)->assertOk()->json('data');
    expect($r['ok'])->toBeFalse()->and($r['message'])->toContain('InvalidAccessKeyId')->and($r['message'])->not->toContain('clave-que-no-sale');
});

it('WhatsApp (Meta): pide ID del número y plantilla para «en vivo», verifica el token y envía la plantilla de prueba', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    Http::fake([
        'graph.facebook.com/v21.0/1234567890/messages' => Http::response(['messages' => [['id' => 'wamid.PRUEBA1']]], 200),
        'graph.facebook.com/v21.0/1234567890*' => Http::response(['verified_name' => 'EscudoSST', 'display_phone_number' => '+51 900 000 000'], 200),
    ]);
    $this->putJson('/api/v1/internal/providers/whatsapp', ['provider' => 'meta', 'mode' => 'live', 'settings' => [], 'secrets' => ['token' => 'EAAtoken-meta-555']], $h)
        ->assertStatus(422)->assertJsonValidationErrors('mode');
    $this->putJson('/api/v1/internal/providers/whatsapp', ['provider' => 'meta', 'mode' => 'test', 'settings' => ['phone_id' => '+51 999'], 'secrets' => []], $h)
        ->assertStatus(422)->assertJsonValidationErrors('settings.phone_id');
    $this->putJson('/api/v1/internal/providers/whatsapp', ['provider' => 'meta', 'mode' => 'live',
        'settings' => ['phone_id' => '1234567890', 'waba_id' => '555', 'template_alert' => 'alerta_sst', 'template_lang' => 'es'], 'secrets' => ['token' => 'EAAtoken-meta-555']], $h)
        ->assertOk()->assertJsonPath('data.live', true);

    $p = collect($this->getJson('/api/v1/internal/providers', $h)->json('data'))->firstWhere('code', 'whatsapp');
    expect(json_encode($p))->not->toContain('EAAtoken-meta-555')->and($p['notice'])->toContain('código QR')->and($p['test_input']['key'])->toBe('to');

    // Sin número: solo verifica (no envía).
    $this->postJson('/api/v1/internal/providers/whatsapp/test', [], $h)->assertOk()->assertJsonPath('data.ok', true);
    Http::assertNotSent(fn (Request $q) => str_ends_with($q->url(), '/messages'));

    $this->postJson('/api/v1/internal/providers/whatsapp/test', ['to' => 'abc'], $h)->assertStatus(422)->assertJsonValidationErrors('to');
    $this->postJson('/api/v1/internal/providers/whatsapp/test', ['to' => '987 654 321'], $h)->assertOk()->assertJsonPath('data.ok', true);
    Http::assertSent(fn (Request $q) => str_ends_with($q->url(), '/1234567890/messages') && $q['to'] === '51987654321' && $q['type'] === 'template'
        && $q['template']['name'] === 'hello_world' && ($q->header('Authorization')[0] ?? '') === 'Bearer EAAtoken-meta-555');
    $row = DB::connection('platform')->selectOne("select last_test_ok, last_test_message from platform_providers where code = 'whatsapp'");
    expect($row->last_test_ok)->toBeTrue()->and($row->last_test_message)->not->toContain('EAAtoken');

    // Solo la dueña o el dueño prueban (enviar cuesta).
    $this->postJson('/api/v1/internal/providers/whatsapp/test', [], authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID))->assertStatus(403);
});

it('pagos, facturación y firma digital: guardan los datos pero siguen en prueba hasta definir el proveedor, con las preguntas', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    Http::fake();
    $list = collect($this->getJson('/api/v1/internal/providers', $h)->assertOk()->json('data'));
    foreach (['payments', 'einvoice', 'esign'] as $code) {
        $p = $list->firstWhere('code', $code);
        expect($p['pending'])->toBeTrue()->and($p['questions'])->not->toBeEmpty();
    }
    expect($list->firstWhere('code', 'payments')['questions'][0])->toContain('Culqi, Niubiz, Izipay o Mercado Pago')
        ->and($list->firstWhere('code', 'payments')['webhook_url'])->toEndWith('/api/v1/webhooks/payments/platform')
        ->and($list->firstWhere('code', 'einvoice')['questions'][0])->toContain('Nubefact')
        ->and($list->firstWhere('code', 'esign')['questions'][0])->toContain('INDECOPI');

    $this->putJson('/api/v1/internal/providers/payments', ['provider' => 'culqi', 'mode' => 'live', 'settings' => ['public_key' => 'pk'], 'secrets' => ['secret_key' => 'sk_live_x']], $h)
        ->assertStatus(422)->assertJsonValidationErrors('mode');
    $this->putJson('/api/v1/internal/providers/einvoice', ['provider' => 'nubefact', 'mode' => 'test', 'settings' => ['ruc' => '20123456789'], 'secrets' => ['token' => 'tok-ose-1234']], $h)
        ->assertOk()->assertJsonPath('data.live', false);
    $this->postJson('/api/v1/internal/providers/einvoice/test', [], $h)->assertOk()->assertJsonPath('data.ok', null);
    Http::assertNothingSent();

    // La app de la empresa no ve el proveedor interno de copias.
    $s = $this->getJson('/api/v1/providers/status', authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID))->assertOk()->json('data');
    expect($s)->not->toHaveKey('backups')->and($s['einvoice']['live'])->toBeFalse();
});
