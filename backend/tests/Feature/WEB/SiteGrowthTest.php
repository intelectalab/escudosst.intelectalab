<?php

// Sitio web v1.5-b: comparativa, lineamientos, campañas, ventanas, integraciones, novedades, webinars, reseñas
// y calculadora de ahorro (SCR-WEB-31 y 68 a 83). La conexión 'platform' no entra en la transacción de la prueba:
// lo creado se da de baja (o se borra) al final.

use App\Modules\WEB\Mail\SiteRequestMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

function growthApi(string $method, string $uri, array $data = [])
{
    return test()->json($method, '/api/v1/internal/site/'.$uri, $data, authHeaders(tokenFor(ADMIN_EMAIL)));
}

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Mail::fake();
    // Todo lo de la conexión 'platform' queda en una transacción que se revierte al final (como AnalyticsTest):
    // no queda nada en la base de pruebas, ni siquiera en site_events (que es inalterable).
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

it('las pantallas del panel son solo para el equipo de la plataforma', function () {
    foreach (['campaigns', 'popups', 'integrations', 'product-updates', 'webinars', 'reviews', 'savings-calculator', 'guidelines'] as $uri) {
        $this->getJson("/api/v1/internal/site/{$uri}", authHeaders(tokenFor(PROFESIONAL_EMAIL)))->assertStatus(403);
        growthApi('GET', $uri)->assertOk();
    }
});

it('comparativa: bloque de tabla y no se publica con precios', function () {
    $id = growthApi('POST', 'pages', [
        'page_type' => 'comparison', 'slug' => 'comparativa-prueba', 'title' => 'EscudoSST vs Excel y papel', 'blocks' => [[
            'type' => 'compare', 'title' => 'Tarea por tarea', 'manual_label' => '', 'escudo_label' => '',
            'items' => [['task' => 'Capacitaciones', 'manual' => 'Listas en papel', 'escudo' => 'Registro con firma'], ['task' => '', 'manual' => 'x', 'escudo' => 'y']],
        ]],
    ])->assertCreated()->json('data.id');
    $page = growthApi('GET', "pages/{$id}")->json('data');
    expect($page['blocks'][0]['items'])->toHaveCount(1)->and($page['blocks'][0]['manual_label'])->toBe('Con Excel y papel');

    growthApi('POST', "pages/{$id}/publish")->assertNoContent();
    $this->getJson('/api/v1/public/pages/comparativa-prueba')->assertOk()->assertJsonPath('data.blocks.0.type', 'compare');

    growthApi('PATCH', "pages/{$id}", ['slug' => 'comparativa-prueba', 'title' => 'Desde S/ 100', 'blocks' => [], 'version' => $page['version'] + 1]);
    growthApi('POST', "pages/{$id}/publish")->assertStatus(422);   // «S/» está bloqueado en la comparativa
});

it('comparativa: el borrador guarda filas a medias, pero no se publica incompleta; el sitio avisa cuando está publicada', function () {
    $id = growthApi('POST', 'pages', ['page_type' => 'comparison', 'slug' => 'comparativa', 'title' => 'EscudoSST vs Excel y papel', 'blocks' => [[
        'type' => 'compare', 'title' => 'Tarea por tarea', 'manual_label' => '', 'escudo_label' => '',
        'items' => [['task' => 'IPERC', 'manual' => '', 'escudo' => ''], ['task' => 'Capacitaciones', 'manual' => 'Listas en papel', 'escudo' => 'Registro con firma']],
    ]]])->assertCreated()->json('data.id');
    expect(growthApi('GET', "pages/{$id}")->json('data.blocks.0.items'))->toHaveCount(2);
    growthApi('POST', "pages/{$id}/publish")->assertStatus(422)->assertJsonPath('errors', fn ($e) => str_contains(json_encode($e, JSON_UNESCAPED_UNICODE), 'IPERC'));
    expect($this->getJson('/api/v1/public/site')->json('data.optional.comparison'))->toBeFalse();

    $page = growthApi('GET', "pages/{$id}")->json('data');
    growthApi('PATCH', "pages/{$id}", ['slug' => 'comparativa', 'title' => 'EscudoSST vs Excel y papel', 'version' => $page['version'], 'blocks' => [[
        'type' => 'compare', 'title' => 'Tarea por tarea', 'items' => [['task' => 'IPERC', 'manual' => 'Matriz en Excel', 'escudo' => 'Matriz guiada en el sistema']],
    ]]])->assertOk();
    growthApi('POST', "pages/{$id}/publish")->assertNoContent();
    expect($this->getJson('/api/v1/public/site')->json('data.optional.comparison'))->toBeTrue();
});

it('lineamientos: lista editable y revisión de lo publicado', function () {
    growthApi('PUT', 'guidelines', ['terms' => ['cero accidentes garantizado'], 'comparison_terms' => ['S/']])->assertOk();
    DB::connection('platform')->table('product_updates')->insert([
        'title' => 'Novedad de prueba', 'slug' => 'novedad-lineamientos', 'body' => 'Logra cero accidentes garantizado con el nuevo módulo de prueba.',
        'category' => 'new', 'status' => 'published', 'published_at' => now(),
    ]);
    $data = growthApi('GET', 'guidelines')->assertOk()->json('data');
    expect($data['terms'])->toBe(['cero accidentes garantizado'])
        ->and(collect($data['findings'])->firstWhere('title', 'Novedad de prueba'))->not->toBeNull();
});

it('campañas: la landing sale solo con la campaña activa y vigente; después muestra «terminó»', function () {
    $utm = 'sst-prueba-'.bin2hex(random_bytes(4));
    $pageId = growthApi('POST', 'pages', ['page_type' => 'campaign', 'slug' => 'l/sst-prueba', 'title' => 'Landing de prueba',
        'blocks' => [['type' => 'hero', 'eyebrow' => '', 'title' => 'Ordena tu SST', 'text' => '']]])->assertCreated()->json('data.id');
    growthApi('POST', "pages/{$pageId}/publish")->assertNoContent();
    $this->getJson('/api/v1/public/campaigns/sst-prueba')->assertOk()->assertJsonPath('data.result', 'ended');

    $id = growthApi('POST', 'campaigns', ['name' => 'Campaña de prueba', 'channel' => 'google_ads', 'utm_source' => 'google', 'utm_medium' => 'cpc',
        'utm_campaign' => $utm, 'landing_page_id' => $pageId, 'form_type' => 'demo'])->assertCreated()->json('data.id');
    growthApi('POST', 'campaigns', ['name' => 'Otra', 'channel' => 'email', 'utm_campaign' => $utm])->assertStatus(422);
    growthApi('POST', "campaigns/{$id}/activate")->assertNoContent();
    $this->getJson('/api/v1/public/campaigns/sst-prueba')->assertOk()
        ->assertJsonPath('data.result', 'ok')->assertJsonPath('data.campaign.form_type', 'demo');

    DB::connection('platform')->insert("insert into site_events (event_type, path, utm_campaign, source, device, session_hash) values ('page_view', '/l/sst-prueba', ?, 'campaign', 'desktop', ?)", [$utm, str_repeat('a', 64)]);
    expect((int) collect(growthApi('GET', 'campaigns')->json('data'))->firstWhere('id', $id)['visits'])->toBe(1);

    growthApi('POST', "campaigns/{$id}/finish")->assertNoContent();
    $this->getJson('/api/v1/public/campaigns/sst-prueba')->assertJsonPath('data.result', 'ended');
    $this->getJson('/api/v1/public/campaigns/no-existe')->assertStatus(404);
});

it('ventanas emergentes: reglas de páginas y nunca en el Libro de Reclamaciones', function () {
    $id = growthApi('POST', 'popups', ['name' => 'Calculadora al salir', 'trigger_type' => 'scroll_percent', 'trigger_value' => 150, 'devices' => 'all',
        'offer_type' => 'fine_calculator', 'title' => '¿Cuánto podría costar una multa?', 'frequency_days' => 7])->assertStatus(422);
    $id = growthApi('POST', 'popups', ['name' => 'Calculadora al salir', 'trigger_type' => 'exit_intent', 'devices' => 'all', 'pages' => ['/planes', '/sectores/*'],
        'offer_type' => 'fine_calculator', 'title' => '¿Cuánto podría costar una multa?', 'cta_label' => 'Calcular', 'frequency_days' => 7])->assertCreated()->json('data.id');
    growthApi('POST', "popups/{$id}/activate")->assertNoContent();

    expect($this->getJson('/api/v1/public/popups?path=/planes&device=desktop')->json('data.popups.0.href'))->toBe('/herramientas/calculadora-multas')
        ->and($this->getJson('/api/v1/public/popups?path=/sectores/mineria')->json('data.popups'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/public/popups?path=/producto')->json('data.popups'))->toHaveCount(0)
        ->and($this->getJson('/api/v1/public/popups?path=/libro-de-reclamaciones')->json('data.popups'))->toHaveCount(0);
});

it('integraciones: solo se ven las publicadas, con su estado', function () {
    $id = growthApi('POST', 'integrations', ['name' => 'Google Calendar prueba', 'category' => 'calendar', 'availability' => 'available', 'description' => 'Agenda de demos.'])
        ->assertCreated()->json('data.id');
    expect(collect($this->getJson('/api/v1/public/integrations')->json('data'))->firstWhere('name', 'Google Calendar prueba'))->toBeNull();
    growthApi('POST', "integrations/{$id}/publish")->assertNoContent();
    expect(collect($this->getJson('/api/v1/public/integrations')->json('data'))->firstWhere('name', 'Google Calendar prueba')['availability'])->toBe('available');
});

it('novedades: se publican, salen en la app y avisan al boletín una sola vez', function () {
    $id = growthApi('POST', 'product-updates', ['title' => 'Nuevo reporte de prueba', 'body' => 'Ahora puedes descargar el reporte mensual en PDF desde el tablero.',
        'category' => 'new', 'modules' => ['DSH'], 'notify_subscribers' => true, 'show_in_app' => true])->assertCreated()->json('data.id');
    $this->getJson('/api/v1/public/product-updates/nuevo-reporte-de-prueba')->assertStatus(404);
    growthApi('POST', "product-updates/{$id}/publish")->assertNoContent();
    growthApi('POST', "product-updates/{$id}/publish")->assertNoContent();

    $this->getJson('/api/v1/public/product-updates/nuevo-reporte-de-prueba')->assertOk()->assertJsonPath('data.category', 'new');
    expect(collect($this->getJson('/api/v1/me/product-updates', authHeaders(tokenFor(PROFESIONAL_EMAIL)))->assertOk()->json('data'))->pluck('slug'))->toContain('nuevo-reporte-de-prueba');
    expect(DB::connection('platform')->selectOne("select count(*) as n from newsletter_issues where subject = 'Novedad: Nuevo reporte de prueba' and deleted_at is null")->n)->toBe(1);
});

it('webinars: inscripción con correo, cupos, cancelación y asistencia', function () {
    $id = growthApi('POST', 'webinars', ['title' => 'Webinar de prueba IPERC', 'speaker_name' => 'Ana Ponente', 'starts_at' => now()->addDays(3)->toIso8601String(),
        'duration_minutes' => 60, 'meeting_provider' => 'meet', 'meeting_url' => 'https://meet.google.com/abc-defg-hij', 'capacity' => 1])->assertCreated()->json('data.id');
    growthApi('POST', "webinars/{$id}/publish")->assertNoContent();
    $w = $this->getJson('/api/v1/public/webinars/webinar-de-prueba-iperc')->assertOk()->json('data');
    expect($w)->not->toHaveKey('meeting_url')->and($w['seats_left'])->toBe(1);

    $form = ['full_name' => 'Luis Inscrito', 'email' => 'luis.webinar@prueba.test', 'privacy_consent' => true];
    $this->postJson('/api/v1/public/webinars/webinar-de-prueba-iperc/register', ['privacy_consent' => false] + $form)->assertStatus(422);
    $this->postJson('/api/v1/public/webinars/webinar-de-prueba-iperc/register', $form)->assertCreated();
    $mail = Mail::sent(SiteRequestMail::class)->last();
    expect($mail->buttonUrl)->toBe('https://meet.google.com/abc-defg-hij')->and($mail->ics)->not->toBeNull();
    $this->postJson('/api/v1/public/webinars/webinar-de-prueba-iperc/register', ['email' => 'otra.webinar@prueba.test'] + $form)->assertStatus(409);   // sin cupos

    preg_match('#/webinars/gestionar/([A-Za-z0-9]{48})#', $mail->note, $m);
    $this->postJson("/api/v1/public/webinars/cancel/{$m[1]}")->assertOk();
    $regs = growthApi('GET', "webinars/{$id}/registrations")->json('data');
    expect($regs[0]['status'])->toBe('cancelled');
    growthApi('POST', "webinars/registrations/{$regs[0]['id']}/attended")->assertStatus(409);
});

it('webinars: recordatorio 24 horas antes, una sola vez', function () {
    $id = growthApi('POST', 'webinars', ['title' => 'Webinar recordatorio prueba', 'speaker_name' => 'Ana Ponente', 'starts_at' => now()->addHours(30)->toIso8601String(),
        'duration_minutes' => 45, 'meeting_provider' => 'zoom'])->json('data.id');
    growthApi('POST', "webinars/{$id}/publish")->assertNoContent();
    $this->postJson('/api/v1/public/webinars/webinar-recordatorio-prueba/register', ['full_name' => 'Rosa Recordada', 'email' => 'rosa.webinar@prueba.test', 'privacy_consent' => true])->assertCreated();
    DB::connection('platform')->update("update webinars set starts_at = now() + interval '20 hours' where id = ?", [$id]);
    $service = app(\App\Modules\WEB\Services\WebinarsService::class);
    expect($service->sendReminders())->toBe(1)->and($service->sendReminders())->toBe(0);
});

it('reseñas: se leen de Google, se filtran por calificación y se muestran con atribución', function () {
    config(['escudo.google.places_api_key' => 'llave-de-prueba']);
    Http::fake(['places.googleapis.com/*' => Http::response(['reviews' => [
        ['rating' => 5, 'text' => ['text' => 'Muy útil para el comité.'], 'publishTime' => '2026-10-01T10:00:00Z', 'authorAttribution' => ['displayName' => 'María P.', 'photoUri' => 'https://lh3.googleusercontent.com/x']],
        ['rating' => 2, 'text' => ['text' => 'Regular.'], 'publishTime' => '2026-09-01T10:00:00Z', 'authorAttribution' => ['displayName' => 'Juan Q.']],
    ]])]);
    $this->putJson('/api/v1/internal/site/reviews', ['place_id' => 'ChIJprueba1234567', 'min_rating' => 4, 'max_to_show' => 5, 'cache_ttl_hours' => 24, 'active' => true], authHeaders(tokenFor(ADMIN_EMAIL)))
        ->assertOk()->assertJsonPath('data.fetched', 2);
    $pub = $this->getJson('/api/v1/public/reviews')->assertOk()->json('data');
    expect($pub['attribution'])->toBe('Reseñas de Google')->and(collect($pub['reviews'])->pluck('author_name')->all())->toContain('María P.')->not->toContain('Juan Q.');
});

it('calculadora de ahorro: no calcula sin supuestos; con supuestos publicados calcula y envía el resultado', function () {
    $this->postJson('/api/v1/public/tools/savings-calculator', ['workers' => 50, 'sites' => 2, 'records_per_month' => 120])->assertStatus(409);
    growthApi('PUT', 'savings-calculator', ['minutes_saved_per_record' => 10, 'manual_hours_reduction_pct' => 30, 'hourly_cost_pen' => 25,
        'disclaimer' => 'Estimación referencial, no garantizada. Los supuestos se muestran.', 'to_validate' => true])->assertOk();
    growthApi('POST', 'savings-calculator/publish')->assertOk()->assertJsonPath('data.status', 'published');

    $r = $this->postJson('/api/v1/public/tools/savings-calculator', ['workers' => 50, 'sites' => 2, 'records_per_month' => 120, 'hours_per_month' => 40,
        'email' => 'calc@prueba.test', 'full_name' => 'Carla Cálculo', 'privacy_consent' => true])->assertOk()->json('data');
    // 120 registros × 10 min = 20 h, más 30 % de 40 h = 12 h → 32 h; × S/ 25 = S/ 800
    expect($r['result_hours_month'])->toEqual(32)->and($r['result_cost_month'])->toEqual(800)->and($r['emailed'])->toBeTrue();
    Mail::assertSent(SiteRequestMail::class, fn ($m) => $m->hasTo('calc@prueba.test'));
});
