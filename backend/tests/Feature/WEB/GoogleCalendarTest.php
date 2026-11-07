<?php

// Google Calendar en la agenda de demos (RN-WEB-74): conectar por OAuth guarda el token cifrado y solo una referencia
// en demo_hosts; confirmar con Meet crea el evento y el enlace; cancelar lo borra; el token nunca sale por la API.

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Mail::fake();
    config(['escudo.google.client_id' => 'id-prueba.apps.googleusercontent.com', 'escudo.google.client_secret' => 'secreto-prueba', 'escudo.google.redirect_uri' => 'http://localhost:8000/api/v1/public/demo/google/callback']);
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

it('conecta el calendario, crea el Meet al confirmar y borra el evento al cancelar', function () {
    $idToken = 'x.'.rtrim(strtr(base64_encode(json_encode(['email' => 'asesora@gmail.test'])), '+/', '-_'), '=').'.y';
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'acceso-1', 'refresh_token' => 'refresco-1', 'expires_in' => 3600, 'id_token' => $idToken]),
        'www.googleapis.com/calendar/v3/calendars/primary/events?*' => Http::response(['id' => 'evento-123', 'hangoutLink' => 'https://meet.google.com/aaa-bbbb-ccc']),
        'www.googleapis.com/calendar/v3/calendars/primary/events/*' => Http::response([], 204),
    ]);
    $host = demoSetup();
    $h = authHeaders(tokenFor(ADMIN_EMAIL));

    $url = $this->getJson("/api/v1/internal/site/demos/hosts/{$host}/google/connect", $h)->assertOk()->json('data.url');
    parse_str(parse_url($url, PHP_URL_QUERY), $q);
    expect($q['access_type'])->toBe('offline')->and($q['scope'])->toContain('calendar.events');
    $this->get('/api/v1/public/demo/google/callback?code=codigo&state='.urlencode('estado-falso'))->assertRedirectContains('google=error');
    $this->get('/api/v1/public/demo/google/callback?code=codigo&state='.urlencode($q['state']))->assertRedirectContains('google=ok');

    $db = DB::connection('platform');
    $hostRow = $db->selectOne('select calendar_provider, calendar_secret_ref from demo_hosts where id = ?', [$host]);
    expect($hostRow->calendar_provider)->toBe('google')->and($hostRow->calendar_secret_ref)->toBe("db://demo-calendar/{$host}");
    expect($db->selectOne('select refresh_token_encrypted from demo_calendar_tokens where host_id = ?', [$host])->refresh_token_encrypted)->not->toContain('refresco-1');

    $day = now('America/Lima')->addDays(3)->toDateString();
    $slot = $this->getJson("/api/v1/public/demo/slots?from={$day}&to={$day}")->json('data.slots.0.starts_at');
    $this->postJson('/api/v1/public/demo/book', bookingForm($slot))->assertCreated();
    $list = $this->getJson("/api/v1/internal/site/demos?from={$day}&to={$day}", $h)->assertOk()->json('data');
    expect($list['hosts']['list'][0]['calendar_account'])->toBe('asesora@gmail.test')->and(json_encode($list))->not->toContain('refresco-1')->not->toContain('acceso-1');

    $id = $list['meetings'][0]['id'];
    $this->postJson("/api/v1/internal/site/demos/meetings/{$id}/confirm", ['meeting_provider' => 'meet'], $h)->assertOk()
        ->assertJsonPath('data.meeting_url', 'https://meet.google.com/aaa-bbbb-ccc')->assertJsonPath('data.calendar_event_ref', 'evento-123');
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'conferenceDataVersion=1') && $r['conferenceData']['createRequest']['conferenceSolutionKey']['type'] === 'hangoutsMeet'
        && $r['attendees'][0]['email'] === 'ana@visita.test' && $r->hasHeader('Authorization', 'Bearer acceso-1'));

    $this->postJson("/api/v1/internal/site/demos/meetings/{$id}/cancel", ['reason' => 'Prueba de cancelación'], $h)->assertOk();
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'events/evento-123'));

    $this->deleteJson("/api/v1/internal/site/demos/hosts/{$host}/google", [], $h)->assertNoContent();
    expect($db->selectOne('select count(*) as n from demo_calendar_tokens')->n)->toBe(0);
});

it('sin credenciales de Google lo dice claro', function () {
    config(['escudo.google.client_id' => null]);
    $host = demoSetup();
    $this->getJson("/api/v1/internal/site/demos/hosts/{$host}/google/connect", authHeaders(tokenFor(ADMIN_EMAIL)))->assertStatus(409);
});
