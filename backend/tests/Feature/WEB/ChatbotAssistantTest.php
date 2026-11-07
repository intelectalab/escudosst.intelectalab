<?php

// Asistente del sitio que aprende (PROPUESTA-ASISTENTE): intérprete por reglas (2+ frases por intención y casos
// ambiguos), solo contenido publicado, datos sensibles fuera, y aprendizaje con aprobación (3 conversaciones o el Dueño).

use App\Modules\WEB\Services\ChatbotAssistant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Cache::flush();
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

dataset('frases', [
    ['hola', 'ayuda'], ['¿Qué puedes hacer?', 'ayuda'],
    ['Quiero hacer un reclamo', 'reclamo'], ['tengo una queja', 'reclamo'],
    ['¿Está caído el sistema?', 'estado'], ['no carga la página', 'estado'],
    ['¿Cuánto es la multa?', 'multa'], ['¿Me pueden multar por no tener comité?', 'multa'],
    ['¿Cuánto cuesta el plan Profesional?', 'precios'], ['hola, quiero saber el precio', 'precios'],
    ['Quiero una demo el jueves', 'demo'], ['agendar una reunión', 'demo'],
    ['prueba gratis', 'prueba'], ['quiero probar el sistema', 'prueba'],
    ['diagnóstico de la ley 29783', 'autodiagnostico'], ['¿Estoy cumpliendo?', 'autodiagnostico'],
    ['¿Cómo invito a mi equipo?', 'como'], ['cómo cambio mi contraseña', 'como'],
    ['Plantilla de IPERC', 'recursos'], ['formato de capacitación', 'recursos'],
    ['casos de construcción', 'casos'], ['¿Quiénes lo usan?', 'casos'],
    ['¿Tienen módulo de EPP?', 'modulos'], ['tienen app para celular', 'modulos'],
    ['soy consultora', 'partner'], ['programa de referidos', 'partner'],
    ['suscribirme al boletín', 'boletin'], ['novedades normativas', 'boletin'],
    ['¿Mis datos están seguros?', 'seguridad'], ['ley 29733', 'seguridad'],
    ['hablar con una persona', 'contacto'], ['su número de WhatsApp', 'contacto'],
    ['receta de ceviche', 'buscar'], ['xyz', 'buscar'],
    // ambiguos
    ['¿Cuánto cuesta?', 'precios'], ['¿Cuánto es la multa por no capacitar?', 'multa'],
    ['¿Cómo invito a mi equipo?', 'como'], ['¿Cómo van los planes?', 'precios'],
]);

it('interpreta la frase', function (string $frase, string $intencion) {
    expect(app(ChatbotAssistant::class)->interpret($frase)['intent'])->toBe($intencion);
})->with('frases');

it('quita correos, teléfonos, DNI y RUC antes de interpretar', function () {
    $t = ChatbotAssistant::term('mi correo es ana@empresa.pe, mi RUC 20100047218, DNI 45678912 y cel +51 999 888 777: ¿precio?');
    expect($t)->not->toContain('ana@')->not->toContain('20100047218')->not->toContain('45678912')->not->toContain('999')->toContain('precio');
});

it('responde solo con contenido publicado (ayuda y preguntas frecuentes)', function () {
    $db = DB::connection('platform');
    $cat = $db->table('help_categories')->insertGetId(['name' => 'Usuarios', 'slug' => 'usuarios', 'status' => 'active']);
    $db->table('help_articles')->insert(['category_id' => $cat, 'title' => 'Cómo invitar a tu equipo', 'slug' => 'invitar-equipo', 'status' => 'published', 'published_at' => now(),
        'body' => "Desde Usuarios.\n\n- Entra a Usuarios\n- Pulsa «Invitar»\n- Escribe el correo"]);
    $db->table('help_articles')->insert(['category_id' => $cat, 'title' => 'Cómo borrar la empresa', 'slug' => 'borrar-empresa', 'status' => 'draft', 'body' => 'Borrador que no debe salir nunca.']);
    $db->table('site_faqs')->insert(['question' => '¿Funciona en el celular?', 'answer' => 'Sí, se usa desde el navegador del celular.', 'status' => 'published', 'sort_order' => 1]);
    $db->table('site_faqs')->insert(['question' => '¿Tienen descuento secreto?', 'answer' => 'Texto oculto.', 'status' => 'archived', 'sort_order' => 2]);
    $a = app(ChatbotAssistant::class);

    $r = $a->answer('¿Cómo invito a mi equipo?');
    expect($r['card']['type'])->toBe('guide')->and($r['card']['steps'])->toBe(['Entra a Usuarios', 'Pulsa «Invitar»', 'Escribe el correo'])->and($r['card']['href'])->toBe('/ayuda/invitar-equipo');
    expect(json_encode($a->answer('cómo borrar la empresa')))->not->toContain('Borrador que no debe salir');
    expect(json_encode($a->answer('descuento secreto')))->not->toContain('Texto oculto');
    expect($a->answer('¿funciona en el celular de mi supervisor?')['reply'])->toContain('navegador');
});

it('aprende con aprobación: sugerencia, 3 conversaciones, enseñanza del Dueño y desactivar', function () {
    $db = DB::connection('platform');
    $db->table('site_faqs')->insert(['id' => $faq = (string) \Illuminate\Support\Str::uuid7(), 'question' => '¿Puedo exportar a Excel?', 'answer' => 'Sí, los reportes se descargan en Excel.', 'status' => 'published', 'sort_order' => 1]);
    $a = app(ChatbotAssistant::class);
    $convs = array_map(fn () => $db->table('chatbot_conversations')->insertGetId(['session_hash' => hash('sha256', random_bytes(8))]), range(1, 3));

    expect($a->learn('sacar reportes en hoja de cálculo', 'faq', $faq, $convs[0]))->not->toBeNull();
    $a->learn('sacar reportes en hoja de cálculo', 'faq', $faq, $convs[0]);   // misma conversación: no cuenta doble
    expect($db->selectOne('select status, uses from chatbot_aliases')->status)->toBe('suggested');
    expect($a->answer('sacar reportes en hoja de cálculo')['reply'])->not->toContain('Excel');   // aún no se usa
    $a->learn('sacar reportes en hoja de cálculo', 'faq', $faq, $convs[1]);
    $a->learn('sacar reportes en hoja de cálculo', 'faq', $faq, $convs[2]);
    expect($db->selectOne('select status, uses from chatbot_aliases'))->status->toBe('active')->uses->toBe(3);
    expect($a->answer('Sacar reportes en hoja de cálculo')['reply'])->toContain('Excel');
    expect($a->learn('exportar', 'faq', $faq, $convs[0]))->toBeNull();   // el título ya lo contiene: nada que aprender

    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $this->postJson('/api/v1/internal/site/chatbot/aliases', ['term' => 'cuanto me cobran', 'target_type' => 'intent', 'target_key' => 'precios'], $h)->assertCreated();
    $this->postJson('/api/v1/internal/site/chatbot/aliases', ['term' => 'algo', 'target_type' => 'faq', 'target_key' => 'no-existe'], $h)->assertStatus(422);
    $list = $this->getJson('/api/v1/internal/site/chatbot/aliases', $h)->assertOk()->json('data');
    expect(collect($list)->firstWhere('term', 'cuanto cobran')['status'])->toBe('active');

    $id = collect($list)->firstWhere('target_type', 'faq')['id'];
    $this->patchJson("/api/v1/internal/site/chatbot/aliases/{$id}", ['status' => 'disabled'], $h)->assertNoContent();
    expect($a->answer('sacar reportes en hoja de cálculo')['reply'])->not->toContain('Excel');
});

it('el visitante enseña desde el chat y el registro es silencioso', function () {
    $db = DB::connection('platform');
    $db->table('site_faqs')->insert(['id' => $faq = (string) \Illuminate\Support\Str::uuid7(), 'question' => '¿Puedo exportar a Excel?', 'answer' => 'Sí, en Excel.', 'status' => 'published', 'sort_order' => 1]);
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $this->postJson('/api/v1/internal/site/chatbot/intents', ['name' => 'Saludo', 'trigger_phrases' => ['saludo'], 'answer' => 'Hola, aquí estamos.', 'cta_type' => 'none'], $h)->assertCreated();
    $db->update("update chatbot_intents set status = 'published' where name = 'Saludo'");
    $this->patchJson('/api/v1/internal/site/chatbot/settings', ['enabled' => true, 'greeting' => 'Hola, ¿en qué te ayudo?', 'handoff_whatsapp' => false, 'ask_lead_after_messages' => 5], $h)->assertNoContent();

    $secret = bin2hex(random_bytes(32));
    $conv = $this->postJson('/api/v1/public/chatbot/start', ['session' => $secret])->json('data.conversation_id');
    $r = $this->postJson('/api/v1/public/chatbot/message', ['conversation_id' => $conv, 'session' => $secret, 'body' => '¿cuánto cuesta?'])->assertOk()->json('data');
    expect($r['card']['type'])->toBeIn(['plans', 'buttons']);
    $this->postJson('/api/v1/public/chatbot/learn', ['conversation_id' => $conv, 'session' => $secret, 'term' => 'planilla de calculo', 'target_type' => 'faq', 'target_key' => $faq])->assertNoContent();
    $this->postJson('/api/v1/public/chatbot/learn', ['conversation_id' => $conv, 'session' => $secret, 'term' => 'x', 'target_type' => 'faq', 'target_key' => 'inventado'])->assertNoContent();
    expect($db->selectOne('select count(*) as n from chatbot_aliases')->n)->toBe(1);
    $kinds = collect($db->select("select answer_kind from chatbot_messages where conversation_id = ? and sender = 'bot'", [$conv]))->pluck('answer_kind');
    expect($kinds->all())->toBe(['assistant:precios']);
});
