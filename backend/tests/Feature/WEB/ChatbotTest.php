<?php

// Chatbot (SCR-WEB-59 a 62, RN-WEB-75 a 77): guiado con intenciones publicadas, conversación protegida por el
// secreto del navegador, datos solo con privacidad, mensajes inalterables y preguntas sin respuesta en el panel.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Mail::fake();
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

function chatbotOn(): array
{
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $id = test()->postJson('/api/v1/internal/site/chatbot/intents', ['name' => 'Precios', 'trigger_phrases' => ['Precio', 'cuánto cuesta'], 'answer' => 'Revisa los planes y pide tu cotización.', 'cta_type' => 'link', 'cta_url' => '/planes'], $h)
        ->assertCreated()->json('data.id');
    test()->postJson("/api/v1/internal/site/chatbot/intents/{$id}/publish", [], $h)->assertNoContent();
    test()->patchJson('/api/v1/internal/site/chatbot/settings', ['enabled' => true, 'greeting' => 'Hola, ¿en qué te ayudo?', 'handoff_whatsapp' => true, 'ask_lead_after_messages' => 2], $h)->assertNoContent();

    return [$h, $id];
}

it('responde con la intención publicada, protege la conversación y pide datos después de N mensajes', function () {
    $this->getJson('/api/v1/public/chatbot')->assertJsonPath('data.result', 'disabled');
    [, $intent] = chatbotOn();
    $cfg = $this->getJson('/api/v1/public/chatbot')->assertOk()->json('data');
    expect($cfg['mode'])->toBe('guided')->and(collect($cfg['intents'])->pluck('name')->all())->toContain('Precios');

    $secret = bin2hex(random_bytes(32));
    $conv = $this->postJson('/api/v1/public/chatbot/start', ['session' => $secret, 'path' => '/planes?x=1'])->assertCreated()->json('data.conversation_id');
    $r = $this->postJson('/api/v1/public/chatbot/message', ['conversation_id' => $conv, 'session' => $secret, 'body' => '¿Cuál es el PRECIO?'])->assertOk()->json('data');
    expect($r['result'])->toBe('ok')->and($r['cta_url'])->toBe('/planes')->and($r['ask_lead'])->toBeFalse();
    $r = $this->postJson('/api/v1/public/chatbot/message', ['conversation_id' => $conv, 'session' => $secret, 'body' => 'receta de ceviche'])->json('data');
    expect($r['result'])->toBe('no_match')->and($r['ask_lead'])->toBeTrue();

    $this->postJson('/api/v1/public/chatbot/message', ['conversation_id' => $conv, 'session' => bin2hex(random_bytes(32)), 'body' => 'hola'])->assertNotFound();   // otro navegador
    $this->postJson('/api/v1/public/chatbot/lead', ['conversation_id' => $conv, 'session' => $secret, 'full_name' => 'Ana Chat', 'email' => 'ana@chat.test'])->assertStatus(422)->assertJsonValidationErrors('privacy_consent');
    $this->postJson('/api/v1/public/chatbot/lead', ['conversation_id' => $conv, 'session' => $secret, 'full_name' => 'Ana Chat', 'email' => 'ana@chat.test', 'privacy_consent' => true])->assertCreated();
    $this->postJson('/api/v1/public/chatbot/rate', ['conversation_id' => $conv, 'session' => $secret, 'rating' => 5])->assertNoContent();

    $db = DB::connection('platform');
    $c = $db->selectOne('select path, lead_id, rating, handoff, session_hash from chatbot_conversations where id = ?', [$conv]);
    expect($c->path)->toBe('/planes')->and($c->lead_id)->not->toBeNull()->and($c->rating)->toBe(5)->and($c->handoff)->toBe('form')->and($c->session_hash)->toBe(hash('sha256', $secret));
    expect(fn () => $db->update('update chatbot_messages set body = ? where conversation_id = ?', ['x', $conv]))->toThrow(Exception::class);
});

it('el panel muestra conversaciones, preguntas sin respuesta y prueba frases; Soporte no publica', function () {
    [$h, $intent] = chatbotOn();
    $secret = bin2hex(random_bytes(32));
    $conv = $this->postJson('/api/v1/public/chatbot/start', ['session' => $secret])->json('data.conversation_id');
    $this->postJson('/api/v1/public/chatbot/message', ['conversation_id' => $conv, 'session' => $secret, 'body' => 'receta de ceviche'])->assertOk();

    $d = $this->getJson('/api/v1/internal/site/chatbot', $h)->assertOk()->json('data');
    expect($d['stats']['total'])->toBe(1)->and(collect($d['unanswered'])->pluck('body'))->toContain('receta de ceviche');
    $this->getJson("/api/v1/internal/site/chatbot/conversations/{$conv}", $h)->assertOk()->assertJsonCount(2, 'data.messages')->assertJsonMissingPath('data.conversation.session_hash');
    $this->postJson('/api/v1/internal/site/chatbot/intents/test', ['phrase' => 'cuánto cuesta el plan'], $h)->assertJsonPath('data.name', 'Precios');
    $this->postJson('/api/v1/internal/site/chatbot/intents', ['name' => 'Sin frases', 'trigger_phrases' => [], 'answer' => 'Texto de prueba', 'cta_type' => 'none'], $h)->assertStatus(422);

    $admin = DB::connection('platform')->selectOne('select id from users where email = ?', [ADMIN_EMAIL])->id;
    DB::connection('pgsql')->select("select set_config('app.user_id', ?, false)", [$admin]);
    DB::connection('pgsql')->update("update users set platform_role = 'support' where id = ?", [$admin]);
    app('auth')->forgetGuards();
    $this->postJson("/api/v1/internal/site/chatbot/intents/{$intent}/archive", [], $h)->assertForbidden();
});
