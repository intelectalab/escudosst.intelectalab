<?php

// API: el secreto del cliente se muestra una sola vez y solo se guarda su hash; webhooks solo https.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    // Complemento API activo en la empresa de prueba (se restaura al terminar).
    $this->flag = DB::connection('platform')->selectOne("select id, mode from feature_flags where company_id = ? and module_code = 'API' and deleted_at is null", [HUAYLAS_ID]);
    DB::connection('platform')->update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'API'", [HUAYLAS_ID]);
    Permissions::flush();
});

afterEach(function () {
    if ($this->flag) {
        DB::connection('platform')->update('update feature_flags set mode = ? where id = ?', [$this->flag->mode, $this->flag->id]);
    }
    Permissions::flush();
});

/** Las claves de la API y los webhooks los gestiona el administrador de cuenta: el usuario de prueba lo es dentro de la prueba. */
function asAccountAdmin(): array
{
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update memberships set role_id = (select id from roles where code = 'account_admin' and account_id is null limit 1)
                 where company_id = ? and user_id = (select id from app_auth_lookup_user(?))", [HUAYLAS_ID, CORPORATIVO_EMAIL]);
    Permissions::flush();

    return $h;
}

it('crea el cliente con clave y muestra el secreto una sola vez', function () {
    $h = asAccountAdmin();
    $c = $this->postJson('/api/v1/records/API/api_clients', ['name' => 'ERP de planillas'], $h)->assertCreated();
    $id = $c->json('data.id');
    $secret = $c->json('data.secret');
    expect($c->json('data.client_key'))->toStartWith('esc_')
        ->and($secret)->toStartWith('sk_');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::table('api_clients')->where('id', $id)->value('secret_hash'))->toBe(hash('sha256', $secret));

    // Después ya no se muestra: ni en la ficha ni en la lista.
    $this->getJson("/api/v1/records/API/api_clients/{$id}", $h)->assertOk()->assertJsonMissingPath('data.secret')->assertJsonMissingPath('data.secret_hash');
    expect(json_encode($this->getJson('/api/v1/records/API/api_clients', $h)->json()))->not->toContain($secret);

    // Rotar: nuevo secreto (una vez) y el anterior deja de servir.
    $new = $this->postJson("/api/v1/api-clients/{$id}/rotate-secret", [], $h)->assertOk()->json('data.secret');
    expect($new)->toStartWith('sk_')->not->toBe($secret);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::table('api_clients')->where('id', $id)->value('secret_hash'))->toBe(hash('sha256', $new));
    $ext = fn (string $s) => ['X-Company-Id' => HUAYLAS_ID, 'X-Api-Key' => $c->json('data.client_key'), 'X-Api-Secret' => $s];
    $this->getJson('/api/v1/ext/v1/workers', $ext($secret))->assertStatus(401);
    $this->getJson('/api/v1/ext/v1/workers', $ext($new))->assertOk();

    // Revocado: ya no se puede rotar.
    $this->patchJson("/api/v1/records/API/api_clients/{$id}", ['status' => 'revoked'], $h)->assertOk();
    $this->postJson("/api/v1/api-clients/{$id}/rotate-secret", [], $h)->assertStatus(409);

    $this->postJson('/api/v1/records/API/webhooks', ['name' => 'Avisos', 'target_url' => 'http://inseguro.test/hook'], $h)
        ->assertStatus(422)->assertJsonPath('errors.target_url.0', 'La dirección debe empezar con https://');
    $this->postJson('/api/v1/records/API/webhooks', ['name' => 'Avisos', 'target_url' => 'https://erp.example.com/hook', 'event_codes' => ['acr.overdue']], $h)
        ->assertCreated();
});

it('el webhook muestra su secreto de firma solo al crearlo o rotarlo y envía una prueba firmada', function () {
    $h = asAccountAdmin();
    $w = $this->postJson('/api/v1/records/API/webhooks', ['name' => 'Avisos', 'target_url' => 'https://erp.example.com/hook', 'event_codes' => ['acr.overdue']], $h)->assertCreated();
    $id = $w->json('data.id');
    $secret = $w->json('data.secret');
    expect($secret)->toStartWith('whsec_');
    $this->getJson("/api/v1/records/API/webhooks/{$id}", $h)->assertOk()->assertJsonMissingPath('data.secret');
    $this->postJson("/api/v1/webhooks/{$id}/secret", [], $h)->assertNotFound();

    $new = $this->postJson("/api/v1/webhooks/{$id}/rotate-secret", [], $h)->assertOk()->json('data.secret');
    expect($new)->toStartWith('whsec_')->not->toBe($secret);

    Http::fake(['erp.example.com/*' => Http::sequence()->push('ok', 200)->push('caído', 500)]);
    $this->postJson("/api/v1/webhooks/{$id}/test", [], $h)->assertOk()
        ->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.response_status', 200);
    Http::assertSent(fn ($r) => $r->url() === 'https://erp.example.com/hook'
        && $r->header('X-EscudoSST-Event')[0] === 'webhook.test'
        && $r->header('X-EscudoSST-Signature')[0] === 'sha256='.hash_hmac('sha256', $r->body(), $new));
    $this->getJson("/api/v1/records/API/webhook_deliveries?f[webhook_id]={$id}", $h)->assertOk()->assertJsonPath('meta.total', 1);

    $this->postJson("/api/v1/webhooks/{$id}/test", [], $h)->assertOk()->assertJsonPath('data.status', 'failed');
});
