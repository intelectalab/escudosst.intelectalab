<?php

// Métricas propias (SCR-WEB-19/50, RN-WEB-65 a 67): sin IP ni navegador guardados, fuente y dispositivo calculados,
// páginas privadas y robots ignorados, resumen agregado y exportación solo del Dueño con auditoría.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

const PHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148';

it('guarda el evento sin IP ni navegador y calcula fuente y dispositivo', function () {
    $this->withHeader('User-Agent', PHONE_UA)->postJson('/api/v1/public/events', ['type' => 'page_view', 'path' => '/planes?x=1', 'referrer' => 'https://www.google.com/'])->assertNoContent();
    $this->withHeader('User-Agent', PHONE_UA)->postJson('/api/v1/public/events', ['type' => 'page_view', 'path' => '/', 'utm_source' => 'fb', 'utm_campaign' => 'lanzamiento'])->assertNoContent();
    $this->withHeader('User-Agent', 'Googlebot/2.1')->postJson('/api/v1/public/events', ['type' => 'page_view', 'path' => '/'])->assertNoContent();
    $this->withHeader('User-Agent', PHONE_UA)->postJson('/api/v1/public/events', ['type' => 'page_view', 'path' => '/plataforma/sitio'])->assertNoContent();
    $this->postJson('/api/v1/public/events', ['type' => 'hackeo', 'path' => '/'])->assertStatus(422);

    $rows = DB::connection('platform')->select('select path, source, device, utm_campaign, session_hash from site_events order by occurred_at');
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->path)->toBe('/planes')->and($rows[0]->source)->toBe('organic')->and($rows[0]->device)->toBe('mobile')
        ->and($rows[1]->source)->toBe('campaign')->and($rows[1]->utm_campaign)->toBe('lanzamiento')
        ->and($rows[0]->session_hash)->toBe($rows[1]->session_hash);   // misma persona el mismo día
    $cols = collect(DB::connection('platform')->select("select column_name from information_schema.columns where table_name = 'site_events'"))->pluck('column_name');
    expect($cols)->not->toContain('ip')->not->toContain('user_agent');
});

it('el resumen agrega visitas, fuentes, conversiones y embudo; exportar es solo del Dueño', function () {
    $ev = fn (array $b) => $this->withHeader('User-Agent', PHONE_UA)->postJson('/api/v1/public/events', $b)->assertNoContent();
    $ev(['type' => 'page_view', 'path' => '/']);
    $ev(['type' => 'page_view', 'path' => '/planes']);
    $ev(['type' => 'cta_click', 'path' => '/planes', 'key' => 'hero-demo']);
    $ev(['type' => 'form_submit', 'path' => '/demo', 'key' => 'demo']);

    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $d = $this->getJson('/api/v1/internal/site/analytics?days=7', $h)->assertOk()->json('data');
    expect($d['totals']['visits'])->toBe(2)->and($d['totals']['visitors'])->toBe(1)   // una persona, dos páginas
        ->and($d['funnel'])->toMatchArray(['visit' => 1, 'cta' => 1, 'lead' => 1])
        ->and(collect($d['conversions'])->firstWhere('event_type', 'form_submit')['element_key'])->toBe('demo')
        ->and($d['sources'][0]['source'])->toBe('direct');
    $this->getJson('/api/v1/internal/site/analytics?days=7&device=desktop', $h)->assertOk()->assertJsonPath('data.totals.visits', 0);

    $csv = $this->get('/api/v1/internal/site/analytics/export?days=7', $h)->assertOk()->streamedContent();
    expect($csv)->toContain('Página')->toContain('/planes');
    expect(DB::connection('platform')->selectOne("select 1 as ok from audit_logs where action = 'site_analytics_exported'"))->not->toBeNull();

    $admin = DB::connection('platform')->selectOne('select id from users where email = ?', [ADMIN_EMAIL])->id;
    DB::connection('pgsql')->select("select set_config('app.user_id', ?, false)", [$admin]);
    DB::connection('pgsql')->update("update users set platform_role = 'support' where id = ?", [$admin]);
    app('auth')->forgetGuards();
    $this->get('/api/v1/internal/site/analytics/export?days=7', $h + ['Accept' => 'application/json'])->assertForbidden();
});
