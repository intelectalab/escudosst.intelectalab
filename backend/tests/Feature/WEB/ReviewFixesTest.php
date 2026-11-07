<?php

// Correcciones de la revisión E1–E7: aprendizaje con personas distintas, datos ocultos en el chat, índice al día al
// publicar, horas ocupadas de Google fuera de la agenda y límites anti-abuso con contador propio y red interna.

use App\Modules\WEB\Services\ChatbotAssistant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Cache::flush();
    Mail::fake();
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

it('E1: una sola persona con 3 conversaciones no activa un sinónimo; 3 personas sí', function () {
    $db = DB::connection('platform');
    $db->table('site_faqs')->insert(['id' => $faq = (string) Str::uuid7(), 'question' => '¿Puedo exportar a Excel?', 'answer' => 'Sí, en Excel.', 'status' => 'published', 'sort_order' => 1]);
    $a = app(ChatbotAssistant::class);
    $conv = fn () => $db->table('chatbot_conversations')->insertGetId(['session_hash' => hash('sha256', random_bytes(8))]);
    foreach (range(1, 3) as $_) {
        $a->learn('planilla de calculo', 'faq', $faq, $conv(), 'misma-persona');
    }
    expect($db->selectOne('select status from chatbot_aliases')->status)->toBe('suggested');
    $a->learn('planilla de calculo', 'faq', $faq, $conv(), 'persona-2');
    $a->learn('planilla de calculo', 'faq', $faq, $conv(), 'persona-3');
    expect($db->selectOne('select status from chatbot_aliases')->status)->toBe('active');
});

it('E2: correos, teléfonos y RUC escritos en el chat se guardan ocultos', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $this->patchJson('/api/v1/internal/site/chatbot/settings', ['enabled' => true, 'greeting' => 'Hola, ¿en qué te ayudo?', 'handoff_whatsapp' => false, 'ask_lead_after_messages' => 5], $h)->assertNoContent();
    $secret = bin2hex(random_bytes(32));
    $conv = $this->postJson('/api/v1/public/chatbot/start', ['session' => $secret])->json('data.conversation_id');
    $this->postJson('/api/v1/public/chatbot/message', ['conversation_id' => $conv, 'session' => $secret, 'body' => 'Soy ana@empresa.pe, cel 999 888 777, RUC 20100047218: ¿precio?'])->assertOk();
    $body = DB::connection('platform')->selectOne("select body from chatbot_messages where conversation_id = ? and sender = 'visitor'", [$conv])->body;
    expect($body)->not->toContain('ana@empresa.pe')->not->toContain('999 888 777')->not->toContain('20100047218')->toContain('[dato oculto]')->toContain('precio');
});

it('E3: al guardar en el panel, el asistente ve lo nuevo al instante', function () {
    $a = app(ChatbotAssistant::class);
    expect(collect($a->index())->where('type', 'faq')->pluck('title'))->not->toContain('¿Hacen capacitaciones presenciales?');
    $this->postJson('/api/v1/internal/site/faqs', ['topic' => 'product', 'question' => '¿Hacen capacitaciones presenciales?', 'answer' => 'El sistema registra capacitaciones presenciales y virtuales.', 'status' => 'published', 'sort_order' => 1], authHeaders(tokenFor(ADMIN_EMAIL)))->assertSuccessful();
    expect(collect($a->index())->where('type', 'faq')->pluck('title'))->toContain('¿Hacen capacitaciones presenciales?');
});

it('E4: lo ocupado en el Google Calendar del anfitrión no se ofrece ni se puede reservar', function () {
    config(['escudo.google.client_id' => 'id', 'escudo.google.client_secret' => 'secreto']);
    $host = demoSetup();
    DB::connection('platform')->insert("insert into demo_calendar_tokens (host_id, provider, refresh_token_encrypted, access_token_encrypted, access_expires_at) values (?, 'google', ?, ?, now() + interval '1 hour')",
        [$host, \Illuminate\Support\Facades\Crypt::encryptString('r'), \Illuminate\Support\Facades\Crypt::encryptString('a')]);
    $day = now('America/Lima')->addDays(3)->toDateString();
    $first = \Illuminate\Support\Carbon::parse($day.' 09:00', 'America/Lima');
    Http::fake(['www.googleapis.com/calendar/v3/freeBusy' => Http::response(['calendars' => ['primary' => ['busy' => [['start' => $first->toRfc3339String(), 'end' => $first->copy()->addMinutes(30)->toRfc3339String()]]]]])]);

    $slots = collect($this->getJson("/api/v1/public/demo/slots?from={$day}&to={$day}")->json('data.slots'))->pluck('starts_at');
    expect($slots)->toHaveCount(3)->and($slots->map(fn ($s) => \Illuminate\Support\Carbon::parse($s)->timestamp))->not->toContain($first->timestamp);
    $this->postJson('/api/v1/public/demo/book', bookingForm($first->toIso8601String()))->assertStatus(409);
});

it('E7: cada límite tiene su contador y la red interna no cuenta solo si se activa', function () {
    $hit = fn () => $this->postJson('/api/v1/public/newsletter', ['email' => 'x@limite.test', 'topics' => ['regulations']]);
    foreach (range(1, 6) as $_) {
        $hit()->assertStatus(422);
    }
    $hit()->assertStatus(429);
    $this->getJson('/api/v1/public/faqs')->assertOk();   // leer el sitio no se bloquea por los formularios

    Cache::flush();
    config(['escudo.throttle_trust_internal' => true]);   // producción: 127.0.0.1 es la red interna (servidor de Next)
    foreach (range(1, 8) as $_) {
        $hit()->assertStatus(422);
    }
});
