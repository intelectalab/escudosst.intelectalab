<?php

// Avisos de M-WEB §10 que faltaban: cambio de estado del referido (correo al partner), webinar cancelado o con grabación
// publicada (correo a los inscritos), enlace del recurso con registro (correo), calendario del anfitrión desconectado
// (correo y campana), versión nueva con re-aceptación (correo web.legal_notice_days antes) y avisos de la campana:
// interesados por anonimizar, demos sin resultado, reseñas con error y campañas por terminar.

use App\Modules\WEB\Http\Controllers\PartnersController;
use App\Modules\WEB\Mail\SiteRequestMail;
use App\Modules\WEB\Services\GoogleCalendarService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Mail::fake();
    Storage::fake('local');
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

function alertsApi(string $method, string $uri, array $data = [])
{
    return test()->json($method, '/api/v1/internal/site/'.$uri, $data, authHeaders(tokenFor(ADMIN_EMAIL)));
}

function alertsBell(): \Illuminate\Support\Collection
{
    return collect(test()->getJson('/api/v1/internal/notifications', authHeaders(tokenFor(ADMIN_EMAIL)))->assertOk()->json('data'))->keyBy('key');
}

function sentTo(string $email, string $titlePart): bool
{
    return Mail::sent(SiteRequestMail::class, fn (SiteRequestMail $m) => $m->hasTo($email) && str_contains($m->title, $titlePart))->isNotEmpty();
}

it('el partner recibe un correo cuando su referido pasa a prueba, a cliente o se da de baja', function () {
    test()->postJson('/api/v1/public/partners', [
        'partner_type' => 'consultancy', 'full_name' => 'Rosa Consultora', 'email' => 'rosa.alertas@consultora.test', 'company_name' => 'Consultora Rosa SAC',
        'ruc' => '20100047218', 'privacy_consent' => true,
    ])->assertCreated();
    $app = collect(alertsApi('GET', 'partners')->json('data.applications'))->firstWhere('email', 'rosa.alertas@consultora.test');
    alertsApi('POST', "partners/{$app['id']}/review", ['approve' => true, 'code' => 'rosa-alerta'])->assertOk();

    $co = DB::connection('platform')->selectOne('select account_id, ruc from companies where deleted_at is null and ruc <> ? order by created_at limit 1', ['20100047218']);
    PartnersController::attribute('ROSA-ALERTA', $co->account_id, trim($co->ruc), null);
    expect(sentTo('rosa.alertas@consultora.test', 'empezó su prueba gratis'))->toBeTrue();

    $ref = collect(alertsApi('GET', 'partners')->json('data.referrals'))->first();
    alertsApi('PATCH', "partners/referrals/{$ref['id']}", ['status' => 'customer'])->assertNoContent();
    expect(sentTo('rosa.alertas@consultora.test', 'ya es cliente'))->toBeTrue();
    alertsApi('PATCH', "partners/referrals/{$ref['id']}", ['status' => 'cancelled', 'reason' => 'Cerró la empresa'])->assertNoContent();
    $mail = Mail::sent(SiteRequestMail::class, fn ($m) => str_contains($m->title, 'se dio de baja'))->last();
    expect($mail)->not->toBeNull()->and(json_encode($mail->rows, JSON_UNESCAPED_UNICODE))->toContain('Cerró la empresa');

    $before = Mail::sent(SiteRequestMail::class)->count();
    alertsApi('PATCH', "partners/referrals/{$ref['id']}", ['status' => 'cancelled', 'reason' => 'Igual'])->assertNoContent();   // sin cambio: no avisa
    expect(Mail::sent(SiteRequestMail::class)->count())->toBe($before);
});

it('los inscritos reciben un correo si el webinar se cancela o se publica su grabación', function () {
    $id = alertsApi('POST', 'webinars', ['title' => 'Webinar alertas prueba', 'speaker_name' => 'Ana Ponente', 'starts_at' => now()->addDays(3)->toIso8601String(),
        'duration_minutes' => 60, 'meeting_provider' => 'meet'])->assertCreated()->json('data.id');
    alertsApi('POST', "webinars/{$id}/publish")->assertNoContent();
    $this->postJson('/api/v1/public/webinars/webinar-alertas-prueba/register', ['full_name' => 'Luis Inscrito', 'email' => 'luis.alertas@prueba.test', 'privacy_consent' => true])->assertCreated();

    alertsApi('POST', "webinars/{$id}/cancel")->assertNoContent();
    expect(sentTo('luis.alertas@prueba.test', 'Webinar cancelado'))->toBeTrue();

    $n = app(\App\Modules\WEB\Services\WebinarsService::class)->notifyAll($id, 'recording');
    expect($n)->toBe(1)->and(sentTo('luis.alertas@prueba.test', 'Ya está la grabación'))->toBeTrue();
    expect(Mail::sent(SiteRequestMail::class, fn ($m) => str_contains($m->title, 'grabación'))->last()->buttonUrl)->toEndWith('/webinars/webinar-alertas-prueba');
});

it('el recurso con registro envía el enlace firmado por correo; el libre no', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $m = $this->post('/api/v1/internal/site/media', ['file' => UploadedFile::fake()->create('guia.pdf', 40, 'application/pdf'), 'title' => 'Guía alertas'], $h + ['Accept' => 'application/json'])->json('data');
    foreach ([['Guía de alertas con registro', true], ['Guía de alertas libre', false]] as [$title, $gated]) {
        $rid = $this->postJson('/api/v1/internal/site/resources', ['title' => $title, 'resource_type' => 'guide', 'media_id' => $m['id'], 'is_gated' => $gated], $h)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/internal/site/resources/{$rid}/publish", [], $h)->assertNoContent();
    }
    $url = $this->postJson('/api/v1/public/resources/guia-de-alertas-con-registro/download', [
        'full_name' => 'Ana Prueba', 'email' => 'ana.recurso@ejemplo.test', 'company_name' => 'Obras Prueba', 'privacy_consent' => true,
    ])->assertOk()->json('data.url');
    $mail = Mail::sent(SiteRequestMail::class, fn ($x) => $x->hasTo('ana.recurso@ejemplo.test'))->last();
    expect($mail)->not->toBeNull()->and($mail->buttonUrl)->toBe($url)->and($mail->title)->toContain('Guía de alertas con registro');

    $before = Mail::sent(SiteRequestMail::class)->count();
    $this->postJson('/api/v1/public/resources/guia-de-alertas-libre/download')->assertOk();
    expect(Mail::sent(SiteRequestMail::class)->count())->toBe($before);
});

it('si Google no renueva el permiso del calendario avisa por correo una sola vez y sale en la campana', function () {
    config(['escudo.google.client_id' => 'id-prueba', 'escudo.google.client_secret' => 'secreto-prueba']);
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
    $host = demoSetup();
    DB::connection('platform')->insert(
        "insert into demo_calendar_tokens (host_id, provider, account_email, refresh_token_encrypted, access_token_encrypted, access_expires_at)
         values (?, 'google', 'asesora@gmail.test', ?, null, now() - interval '1 hour')",
        [$host, Crypt::encryptString('refresco-vencido')]
    );
    $svc = app(GoogleCalendarService::class);
    $token = fn () => (fn () => $this->accessToken($host))->call($svc);

    expect($token())->toBeNull();
    expect(sentTo('asesora@escudo.test', 'Se desconectó el calendario'))->toBeTrue();
    $count = Mail::sent(SiteRequestMail::class)->count();
    expect($token())->toBeNull();
    expect(Mail::sent(SiteRequestMail::class)->count())->toBe($count);   // no se repite

    expect(alertsBell()['calendar_disconnected']['count'] ?? 0)->toBe(1);
});

it('la campana avisa demos sin resultado, interesados por anonimizar, reseñas con error y campañas por terminar', function () {
    $db = DB::connection('platform');
    demoSetup();
    $day = now('America/Lima')->addDays(3)->toDateString();
    $slot = $this->getJson("/api/v1/public/demo/slots?from={$day}&to={$day}")->json('data.slots.0.starts_at');
    $this->postJson('/api/v1/public/demo/book', bookingForm($slot, ['email' => 'demo.alertas@visita.test']))->assertCreated();
    $before = alertsBell();
    $db->update(
        "update demo_meetings set status = 'confirmed', confirmed_at = now(), meeting_provider = 'phone', starts_at = now() - interval '2 hours', ends_at = now() - interval '90 minutes'
          where demo_request_id = (select id from demo_requests where email = 'demo.alertas@visita.test' order by created_at desc limit 1)"
    );
    $db->insert("insert into marketing_leads (email, source, privacy_consent, retain_until) values ('lead.alertas@ejemplo.test', 'contact', true, current_date + 5)");
    $db->update("update review_sources set deleted_at = now() where deleted_at is null");
    $db->insert("insert into review_sources (place_id, status, last_error) values ('ChIJalertas', 'error', 'Google respondió con error 403')");
    $page = $db->selectOne("insert into site_pages (slug, page_type, title) values ('l/alertas-prueba', 'campaign', 'Landing alertas') returning id")->id;
    $db->insert("insert into campaigns (name, channel, landing_page_id, status, ends_on) values ('Campaña alertas', 'google_ads', ?, 'active', current_date + 2)", [$page]);

    $after = alertsBell();
    $diff = fn (string $k) => ($after[$k]['count'] ?? 0) - ($before[$k]['count'] ?? 0);
    expect($diff('demos_no_outcome'))->toBe(1)
        ->and($diff('leads_retention'))->toBe(1)
        ->and($after['reviews_error']['count'])->toBe(1)
        ->and($diff('campaigns_ending'))->toBe(1);
});

it('una versión nueva con re-aceptación se avisa por correo antes de su vigencia, una sola vez', function () {
    $admin = authHeaders(tokenFor(ADMIN_EMAIL));
    $terms = collect($this->getJson('/api/v1/internal/site/legal', $admin)->json('data'))->firstWhere('doc_type', 'terms');
    $draft = $terms['versions'][0]['id'];
    $this->patchJson("/api/v1/internal/site/legal/versions/{$draft}", ['content' => "## 1. Objeto\n\nTexto final."], $admin)->assertNoContent();
    $this->postJson("/api/v1/internal/site/legal/versions/{$draft}/publish", [], $admin)->assertNoContent();
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/me/legal-accept', ['version_ids' => [$draft]], authHeaders(tokenFor(ESENCIAL_EMAIL)))->assertNoContent();
    app('auth')->forgetGuards();

    $new = $this->postJson("/api/v1/internal/site/legal/{$terms['id']}/versions", [], $admin)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/internal/site/legal/versions/{$new}", ['content' => "## 1. Objeto\n\nTexto nuevo.", 'summary_of_changes' => 'Cambia el plazo de aviso.'], $admin)->assertNoContent();
    $this->postJson("/api/v1/internal/site/legal/versions/{$new}/publish", ['requires_reacceptance' => true, 'effective_from' => now()->addDays(5)->toDateString()], $admin)->assertNoContent();

    $svc = app(\App\Modules\WEB\Services\LegalDocumentsService::class);
    expect($svc->sendReacceptanceNotices())->toBeGreaterThanOrEqual(1);
    expect(sentTo(ESENCIAL_EMAIL, 'Cambia el documento'))->toBeTrue();
    expect($svc->sendReacceptanceNotices())->toBe(0);
});
