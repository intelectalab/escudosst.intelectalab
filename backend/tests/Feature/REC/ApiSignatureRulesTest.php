<?php

// Límite por cliente de la API (RN-API-05), reintentos de webhook con aviso (RN-API-04), registro firmado bloqueado
// (RN-FIR-03) y aviso del PIN bloqueado (M-FIR §10).

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
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

function apiAdmin(): array
{
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update memberships set role_id = (select id from roles where code = 'account_admin' and account_id is null limit 1)
                 where company_id = ? and user_id = (select id from app_auth_lookup_user(?))", [HUAYLAS_ID, CORPORATIVO_EMAIL]);
    Permissions::flush();

    return $h;
}

it('cada cliente de la API respeta su propio límite por minuto', function () {
    $h = apiAdmin();
    $c = $this->postJson('/api/v1/records/API/api_clients', ['name' => 'Tablero externo', 'rate_limit_per_minute' => 2], $h)->assertCreated();
    $ext = ['X-Company-Id' => HUAYLAS_ID, 'X-Api-Key' => $c->json('data.client_key'), 'X-Api-Secret' => $c->json('data.secret')];
    $this->getJson('/api/v1/ext/v1/workers', $ext)->assertOk();
    $this->getJson('/api/v1/ext/v1/workers', $ext)->assertOk();
    $this->getJson('/api/v1/ext/v1/workers', $ext)->assertStatus(429);
});

it('abandona la entrega del webhook tras los reintentos y avisa al administrador', function () {
    $h = apiAdmin();
    $id = $this->postJson('/api/v1/records/API/webhooks', ['name' => 'ERP', 'target_url' => 'https://erp.example.com/hook', 'event_codes' => ['acr.overdue']], $h)
        ->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $del = DB::selectOne("insert into webhook_deliveries (company_id, webhook_id, event_code, event_id, request_body, payload_sha256, status, attempt, next_retry_at)
                          values (?, ?, 'acr.overdue', gen_random_uuid(), '{}'::jsonb, decode(repeat('0', 64), 'hex'), 'pending', 6, now()) returning id", [HUAYLAS_ID, $id])->id;
    Http::fake(['erp.example.com/*' => Http::response('caído', 500)]);
    $d = DB::selectOne('select d.*, w.target_url, w.secret, w.failure_count from webhook_deliveries d join webhooks w on w.id = d.webhook_id where d.id = ?', [$del]);
    \App\Modules\API\Webhooks::send(DB::connection(), $d);
    expect(DB::selectOne('select status, next_retry_at from webhook_deliveries where id = ?', [$del]))->status->toBe('abandoned');
    expect((int) DB::selectOne("select count(*) as n from notifications where event_code = 'api.delivery_abandoned' and subject_id = ?", [$id])->n)->toBeGreaterThan(0);
});

it('un registro con todas sus firmas ya no se edita', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'FIR-S', 'name' => 'Sede firmada'], $h)->assertCreated()->json('data.id');
    $me = DB::selectOne('select id from app_auth_lookup_user(?)', [CORPORATIVO_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $req = DB::selectOne("insert into signature_requests (company_id, subject_type, subject_id, subject_module, document_hash, signer_user_id, role_in_document, status)
                          values (?, 'sites', ?, 'TEN', decode(repeat('a', 64), 'hex'), ?, 'approver', 'pending') returning id", [HUAYLAS_ID, $site, $me])->id;
    $this->patchJson("/api/v1/records/TEN/sites/{$site}", ['name' => 'Aún editable'], $h)->assertOk();
    DB::update("update signature_requests set status = 'signed', signed_at = now() where id = ?", [$req]);
    $this->patchJson("/api/v1/records/TEN/sites/{$site}", ['name' => 'Ya no'], $h)->assertStatus(409);
});

it('avisa al supervisor cuando se bloquea el PIN de un trabajador', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '67676767', 'first_name' => 'Pía', 'paternal_last_name' => 'Lazo'], $h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $hash = \Illuminate\Support\Facades\Hash::make('482915');
    foreach (range(1, (int) config('escudo.worker_app.pin_max_attempts', 5)) as $i) {
        \App\Modules\TRB\WorkerAppAccess::checkPin($w, $hash, '000000');
    }
    expect((int) DB::selectOne("select count(*) as n from notifications where event_code = 'fir.pin_locked' and subject_id = ?", [$w])->n)->toBeGreaterThan(0);
    \Illuminate\Support\Facades\Cache::flush();
});
