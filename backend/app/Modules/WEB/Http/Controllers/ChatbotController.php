<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use App\Modules\WEB\Mail\SiteRequestMail;
use App\Modules\WEB\Services\ChatbotAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Chatbot del sitio (SCR-WEB-59 a 62; RN-WEB-75 a 77). Modo guiado: responde con las intenciones publicadas por
 * sus frases. El modo IA queda apagado mientras no haya un proveedor elegido (no se inventan respuestas).
 *  Público: GET  /public/chatbot                    configuración e intenciones publicadas
 *           POST /public/chatbot/start | message | lead | rate | handoff
 *  Panel:   GET  /internal/site/chatbot             ajustes, intenciones, conversaciones y preguntas sin respuesta
 *           PATCH ajustes (Dueño), CRUD de intenciones, publicar/archivar (Dueño), probar una frase, ver una conversación
 *
 * La conversación se protege con un secreto que solo tiene el navegador del visitante: en la base queda su hash.
 */
class ChatbotController extends Controller
{
    public const CTAS = ['none' => 'Sin botón', 'demo' => 'Agendar demo', 'whatsapp' => 'WhatsApp', 'calculator' => 'Calculadora de multas', 'assessment' => 'Autodiagnóstico', 'link' => 'Enlace'];

    private const MESSAGES = [
        'required' => 'Completa este campo.',
        'email' => 'Escribe un correo válido.',
        'max' => 'Es muy largo.',
        'min' => 'Es muy corto.',
        'in' => 'Elige una opción de la lista.',
        'accepted' => 'Acepta la política de privacidad.',
        'regex' => 'Formato no válido.',
    ];

    public function __construct(private readonly ChatbotAssistant $assistant) {}

    private function db()
    {
        return DB::connection('platform');
    }

    private function call(string $sql, array $args): array
    {
        return json_decode($this->db()->selectOne("select {$sql} as r", $args)->r, true);
    }

    /** El navegador guarda un secreto de 64 caracteres; la base solo ve su hash. */
    private function session(Request $request): string
    {
        $s = (string) $request->input('session', '');
        if (! preg_match('/^[a-f0-9]{64}$/', $s)) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Sesión no válida.', ['errors' => ['session' => ['Sesión no válida.']]]);
        }

        return hash('sha256', $s);
    }

    private function conversationId(Request $request): string
    {
        return $request->validate(['conversation_id' => ['required', 'uuid']])['conversation_id'];
    }

    // ---------------------------------------------------------------- Público

    public function config(): JsonResponse
    {
        $r = $this->call('public_chatbot_config()', []);
        if (($r['result'] ?? '') === 'ok') {
            $r['mode'] = 'guided';   // sin proveedor de IA elegido, siempre guiado
        }

        return response()->json(['data' => $r])->header('Cache-Control', 'public, max-age=60');
    }

    public function start(Request $request): JsonResponse
    {
        $path = (string) $request->input('path', '/');
        $r = $this->call('public_chatbot_start(?, ?)', [$this->session($request), str_starts_with($path, '/') ? mb_substr(strtok($path, '?#') ?: '/', 0, 300) : '/']);

        return response()->json(['data' => $r], ($r['result'] ?? '') === 'ok' ? 201 : 409);
    }

    /**
     * Un mensaje del visitante: responde el asistente (alias aprendido, respuesta guiada del panel, intención o búsqueda)
     * y guarda la pregunta y la respuesta (inalterables) con cómo se respondió.
     */
    public function message(Request $request): JsonResponse
    {
        $d = $request->validate(['body' => ['required', 'string', 'min:1', 'max:500'], 'intent_id' => ['nullable', 'uuid'], 'ask' => ['nullable', 'string', 'max:60']], self::MESSAGES);
        $conv = $this->liveConversation($request);
        $s = $this->db()->selectOne('select enabled, ask_lead_after_messages, handoff_whatsapp from chatbot_settings where singleton');
        if (! $s->enabled) {
            return response()->json(['data' => ['result' => 'disabled']], 409);
        }
        if ($conv->message_count >= 200) {
            return response()->json(['data' => ['result' => 'limit_reached', 'reply' => 'Llegamos al límite de esta conversación. Déjanos tus datos o escríbenos por WhatsApp.']]);
        }
        $body = trim(strip_tags($d['body']));
        $r = null;
        if (! empty($d['intent_id'])) {   // botón de una respuesta guiada
            $i = $this->db()->selectOne("select id, answer, cta_type, cta_url from chatbot_intents where id = ? and status = 'published' and deleted_at is null", [$d['intent_id']]);
            $r = $i ? ['reply' => $i->answer, 'card' => null, 'kind' => 'guided', 'intent' => 'guided', 'intent_id' => $i->id, 'cta_type' => $i->cta_type, 'cta_url' => $i->cta_url] : null;
        }
        if (! $r && ! empty($d['ask']) && isset(ChatbotAssistant::INTENTS[$d['ask']])) {   // botón de atajo del asistente
            $r = $this->assistant->byTarget('intent', $d['ask']);
        }
        $r ??= $this->assistant->answer($body);
        $db = $this->db();
        $db->transaction(function () use ($db, $conv, $body, $r) {
            // Lo que escribió el visitante se guarda sin correos, teléfonos, DNI ni RUC (sus datos van solo por el formulario).
            $db->insert("insert into chatbot_messages (conversation_id, sender, body) values (?, 'visitor', ?)", [$conv->id, mb_substr(ChatbotAssistant::redactForStorage($body), 0, 2000)]);
            $db->insert("insert into chatbot_messages (conversation_id, sender, body, intent_id, answer_kind, occurred_at) values (?, 'bot', ?, ?, ?, now() + interval '1 millisecond')",
                [$conv->id, mb_substr($r['reply'], 0, 2000), $r['intent_id'] ?? null, $r['kind']]);
            $db->update('update chatbot_conversations set message_count = message_count + 2 where id = ?', [$conv->id]);
        });
        $visitor = (int) $db->selectOne("select count(*) as n from chatbot_messages where conversation_id = ? and sender = 'visitor'", [$conv->id])->n;

        return response()->json(['data' => [
            'result' => $r['kind'] === 'fallback' ? 'no_match' : 'ok', 'reply' => $r['reply'], 'card' => $r['card'] ?? null,
            'cta_type' => $r['cta_type'] ?? 'none', 'cta_url' => $r['cta_url'] ?? null, 'intent_id' => $r['intent_id'] ?? null, 'term' => $r['term'] ?? null,
            'ask_lead' => ! $conv->lead_id && ($visitor >= $s->ask_lead_after_messages || $r['kind'] === 'fallback'),
            'handoff_whatsapp' => (bool) $s->handoff_whatsapp,
        ]]);
    }

    /** El visitante eligió un resultado: se registra como sugerencia de sinónimo (silencioso si falla). */
    public function learn(Request $request): Response
    {
        $d = $request->validate(['term' => ['required', 'string', 'max:200'], 'target_type' => ['required', 'in:intent,faq,help_article,resource,story,page'], 'target_key' => ['required', 'string', 'max:300']]);
        $conv = $this->liveConversation($request);
        try {
            $voter = hash_hmac('sha256', 'chat-voter|'.$request->ip(), (string) config('app.key'));
            $this->assistant->learn($d['term'], $d['target_type'], $d['target_key'], $conv->id, $voter);
        } catch (\Throwable $e) {
            Log::info('No se registró el sinónimo del chatbot', ['error' => $e->getMessage()]);
        }

        return response()->noContent();
    }

    private function liveConversation(Request $request): object
    {
        return $this->db()->selectOne(
            'select id, message_count, lead_id from chatbot_conversations where id = ? and session_hash = ? and ended_at is null and deleted_at is null',
            [$this->conversationId($request), $this->session($request)]
        ) ?? throw ApiProblem::notFound('La conversación terminó. Empieza una nueva.');
    }

    public function lead(Request $request): JsonResponse
    {
        if (filled($request->input('website'))) {
            return response()->json(['data' => ['result' => 'ok']], 201);
        }
        $d = $request->validate([
            'full_name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:160'],
            'phone' => ['nullable', 'string', 'max:30'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'privacy_consent' => ['accepted'],
            'marketing_consent' => ['boolean'],
        ], self::MESSAGES);
        $data = $d + ['privacy_consent' => true, 'marketing_consent' => (bool) ($d['marketing_consent'] ?? false)];
        $r = $this->call("public_chatbot_lead(?, ?, ?::jsonb, decode(?, 'hex'))", [
            $this->conversationId($request), $this->session($request), json_encode($data, JSON_UNESCAPED_UNICODE),
            hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
        ]);
        if (($r['result'] ?? '') !== 'ok') {
            throw ApiProblem::notFound('La conversación terminó. Empieza una nueva.');
        }
        if (! ($r['replay'] ?? false)) {
            $this->notifyTeam($data, $this->conversationId($request));
        }

        return response()->json(['data' => ['result' => 'ok']], 201);
    }

    public function rate(Request $request): Response
    {
        $d = $request->validate(['rating' => ['required', 'integer', 'between:1,5']]);
        $this->db()->update(
            'update chatbot_conversations set rating = ?, ended_at = coalesce(ended_at, now()) where id = ? and session_hash = ? and deleted_at is null',
            [$d['rating'], $this->conversationId($request), $this->session($request)]
        );

        return response()->noContent();
    }

    public function handoff(Request $request): Response
    {
        $this->db()->update(
            "update chatbot_conversations set handoff = 'whatsapp' where id = ? and session_hash = ? and deleted_at is null and handoff = 'none'",
            [$this->conversationId($request), $this->session($request)]
        );

        return response()->noContent();
    }

    private function notifyTeam(array $d, string $conversationId): void
    {
        $s = json_decode($this->db()->selectOne('select public_site_settings() as s')->s, true);
        $to = ($s['sales_email'] ?? null) ?: ($s['contact_email'] ?? null);
        if (! $to) {
            return;
        }
        try {
            Mail::to($to)->send(new SiteRequestMail(
                'equipo', 'Nuevo contacto desde el chatbot: '.($d['company_name'] ?? $d['full_name']), 'Dejó sus datos en el chatbot del sitio. Ya está en Interesados.',
                array_values(array_filter([['Contacto', $d['full_name']], ['Correo', $d['email']], ! empty($d['phone']) ? ['Celular', $d['phone']] : null])),
                null, 'Ver la conversación', rtrim((string) config('escudo.frontend_url'), '/').'/plataforma/sitio/chatbot?c='.$conversationId
            ));
        } catch (\Throwable $e) {
            Log::warning('No se pudo avisar el contacto del chatbot', ['error' => $e->getMessage()]);
        }
    }

    // ---------------------------------------------------------------- Panel

    public function index(): JsonResponse
    {
        $db = $this->db();
        $settings = $db->selectOne('select enabled, mode, greeting, avatar_media_id, business_hours_json, offline_message, handoff_whatsapp, ask_lead_after_messages, (ai_provider_secret_ref is not null) as ai_ready from chatbot_settings where singleton');
        $settings->business_hours_json = json_decode($settings->business_hours_json, true) ?: new \stdClass;
        $intents = $db->select(
            "select i.id, i.name, i.trigger_phrases_json, i.answer, i.cta_type, i.cta_url, i.sort_order, i.status,
                    (select count(*) from chatbot_messages m where m.intent_id = i.id)::int as uses
               from chatbot_intents i where i.deleted_at is null order by i.sort_order, i.name"
        );
        foreach ($intents as $i) {
            $i->trigger_phrases_json = json_decode($i->trigger_phrases_json, true);
        }
        $convs = $db->select(
            "select c.id, c.started_at, c.path, c.message_count, c.handoff, c.rating, c.lead_id, l.full_name as lead_name, l.email as lead_email
               from chatbot_conversations c left join marketing_leads l on l.id = c.lead_id
              where c.deleted_at is null order by c.started_at desc limit 100"
        );
        // Preguntas sin respuesta: lo que escribió el visitante justo antes de una respuesta sin intención.
        $unanswered = $db->select(
            "select body, count(*)::int as n, max(occurred_at) as last_at from (
                select m.body, m.occurred_at, lead(m.sender) over w as next_sender, lead(m.intent_id) over w as next_intent, lead(m.used_ai) over w as next_ai, lead(m.answer_kind) over w as next_kind
                  from chatbot_messages m join chatbot_conversations c on c.id = m.conversation_id and c.deleted_at is null
                window w as (partition by m.conversation_id order by m.occurred_at, m.sender desc)
             ) x where x.next_sender = 'bot' and (x.next_kind = 'fallback' or (x.next_kind is null and x.next_intent is null and not coalesce(x.next_ai, false)))
               and x.body not in (select name from chatbot_intents)
             group by body order by n desc, last_at desc limit 30"
        );
        $stats = $db->selectOne(
            "select count(*)::int as total, count(*) filter (where lead_id is not null)::int as leads, count(*) filter (where handoff = 'whatsapp')::int as whatsapp,
                    round(avg(rating), 1) as rating from chatbot_conversations where deleted_at is null and started_at > now() - interval '30 days'"
        );

        return response()->json(['data' => compact('settings', 'intents', 'unanswered', 'stats') + ['conversations' => $convs], 'ctas' => self::CTAS]);
    }

    public function conversation(string $id): JsonResponse
    {
        $c = $this->db()->selectOne('select c.*, l.full_name as lead_name, l.email as lead_email from chatbot_conversations c left join marketing_leads l on l.id = c.lead_id where c.id = ? and c.deleted_at is null', [$id])
            ?? throw ApiProblem::notFound('La conversación no existe.');
        unset($c->session_hash);
        $msgs = $this->db()->select(
            'select m.sender, m.body, m.used_ai, m.occurred_at, m.answer_kind, i.name as intent from chatbot_messages m left join chatbot_intents i on i.id = m.intent_id where m.conversation_id = ? order by m.occurred_at, m.sender desc', [$id]
        );

        return response()->json(['data' => ['conversation' => $c, 'messages' => $msgs]]);
    }

    public function updateSettings(Request $request, AccountsService $accounts): Response
    {
        $d = $request->validate([
            'enabled' => ['required', 'boolean'],
            'greeting' => ['required', 'string', 'min:5', 'max:300'],
            'offline_message' => ['nullable', 'string', 'max:300'],
            'handoff_whatsapp' => ['required', 'boolean'],
            'ask_lead_after_messages' => ['required', 'integer', 'between:1,50'],
            'avatar_media_id' => ['nullable', 'uuid'],
            'business_hours' => ['nullable', 'array'],
            'business_hours.*' => ['array', 'max:3'],
            'business_hours.*.*' => ['array', 'size:2'],
            'business_hours.*.*.*' => ['date_format:H:i'],
        ], self::MESSAGES + ['business_hours.*.*.*.date_format' => 'Usa horas como 09:00.']);
        $hours = [];
        foreach ($d['business_hours'] ?? [] as $day => $ranges) {
            if (! in_array((string) $day, ['1', '2', '3', '4', '5', '6', '7'], true)) {
                continue;
            }
            foreach ($ranges as $r) {
                if ($r[1] <= $r[0]) {
                    throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'La hora de cierre debe ser después de la de apertura.', ['errors' => ['business_hours' => ['La hora de cierre debe ser después de la de apertura.']]]);
                }
                $hours[(string) $day][] = [$r[0], $r[1]];
            }
        }
        if (! empty($d['avatar_media_id']) && ! $this->db()->selectOne("select 1 from site_media where id = ? and kind = 'image' and deleted_at is null", [$d['avatar_media_id']])) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'El avatar debe ser una imagen de la biblioteca.', ['errors' => ['avatar_media_id' => ['El avatar debe ser una imagen de la biblioteca.']]]);
        }
        $this->db()->update(
            'update chatbot_settings set enabled = ?, greeting = ?, offline_message = ?, handoff_whatsapp = ?, ask_lead_after_messages = ?, avatar_media_id = ?, business_hours_json = ?::jsonb, updated_by = ?, updated_at = now() where singleton',
            [(bool) $d['enabled'], trim($d['greeting']), $d['offline_message'] ?? null, (bool) $d['handoff_whatsapp'], $d['ask_lead_after_messages'],
                $d['avatar_media_id'] ?? null, json_encode($hours ? $hours + ['timezone' => 'America/Lima'] : new \stdClass), $request->user()->id]
        );
        $accounts->audit($request->user()->id, 'chatbot_settings_updated', 'chatbot_settings', null, ['enabled' => (bool) $d['enabled']]);

        return response()->noContent();
    }

    public function saveIntent(Request $request, AccountsService $accounts, ?string $id = null): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:80'],
            'trigger_phrases' => ['required', 'array', 'min:1', 'max:20'],
            'trigger_phrases.*' => ['string', 'min:2', 'max:60'],
            'answer' => ['required', 'string', 'min:5', 'max:1000'],
            'cta_type' => ['required', 'in:'.implode(',', array_keys(self::CTAS))],
            'cta_url' => ['nullable', 'string', 'max:300', 'regex:~^(https://|/)~', 'required_if:cta_type,link'],
            'sort_order' => ['integer', 'between:0,999'],
        ], self::MESSAGES + ['trigger_phrases.required' => 'Agrega al menos una palabra o frase.', 'cta_url.required_if' => 'Escribe el enlace del botón.', 'cta_url.regex' => 'Un enlace del sitio (empieza con /) o https://']);
        $phrases = array_values(array_unique(array_filter(array_map(fn ($p) => mb_strtolower(trim($p)), $d['trigger_phrases']))));
        $row = [
            'name' => trim($d['name']), 'trigger_phrases_json' => json_encode($phrases, JSON_UNESCAPED_UNICODE), 'answer' => trim(strip_tags($d['answer'])),
            'cta_type' => $d['cta_type'], 'cta_url' => $d['cta_type'] === 'link' ? $d['cta_url'] : null, 'sort_order' => $d['sort_order'] ?? 0,
            'updated_by' => $request->user()->id,
        ];
        if ($id) {
            $this->db()->table('chatbot_intents')->where('id', $id)->whereNull('deleted_at')->update($row + ['updated_at' => now()]) ?: throw ApiProblem::notFound('La respuesta no existe.');
        } else {
            $id = $this->db()->table('chatbot_intents')->insertGetId($row + ['status' => 'draft', 'created_by' => $request->user()->id]);
        }
        $accounts->audit($request->user()->id, 'chatbot_intent_saved', 'chatbot_intents', $id, ['name' => $row['name']]);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function actIntent(Request $request, string $id, string $action, AccountsService $accounts): Response
    {
        if ($action === 'publish') {
            $this->db()->selectOne('select web_publish_chatbot_intent(?)', [$id]);
        } elseif ($action === 'archive') {
            $this->db()->update("update chatbot_intents set status = 'archived', updated_by = ? where id = ? and deleted_at is null", [$request->user()->id, $id]);
        } else {
            $this->db()->update("update chatbot_intents set deleted_at = now(), status = 'archived', updated_by = ? where id = ? and status <> 'published'", [$request->user()->id, $id]);
        }
        $accounts->audit($request->user()->id, "chatbot_intent_{$action}", 'chatbot_intents', $id, []);

        return response()->noContent();
    }

    // ---------------------------------------------------------------- Aprendizaje (panel)

    public function aliases(): JsonResponse
    {
        $rows = $this->db()->select(
            'select a.id, a.term, a.target_type, a.target_key, a.status, a.source, a.uses, a.updated_at,
                    (select count(*) from chatbot_alias_votes v where v.alias_id = a.id)::int as votes
               from chatbot_aliases a order by (a.status = \'suggested\') desc, a.updated_at desc limit 300'
        );
        $index = collect($this->assistant->index())->keyBy(fn ($i) => $i['type'].'|'.$i['key']);
        foreach ($rows as $r) {
            $r->target_title = $r->target_type === 'intent' ? (ChatbotAssistant::INTENTS[$r->target_key] ?? $r->target_key) : ($index[$r->target_type.'|'.$r->target_key]['title'] ?? 'Ya no está publicado');
        }

        return response()->json(['data' => $rows, 'intents' => collect(ChatbotAssistant::INTENTS)->except(['buscar', 'faq'])]);
    }

    /** Buscar destinos para enseñar: lo publicado del sitio. */
    public function targets(Request $request): JsonResponse
    {
        $q = ChatbotAssistant::term((string) $request->query('q', ''));
        $hits = $q === '' ? array_slice($this->assistant->index(), 0, 20) : $this->assistant->rank($q, null, 20);

        return response()->json(['data' => array_map(fn ($h) => ['type' => $h['type'], 'key' => $h['key'], 'title' => $h['title'], 'label' => $h['label']], $hits)]);
    }

    public function teach(Request $request, AccountsService $accounts): JsonResponse
    {
        $d = $request->validate(['term' => ['required', 'string', 'min:2', 'max:200'], 'target_type' => ['required', 'in:intent,faq,help_article,resource,story,page'], 'target_key' => ['required', 'string', 'max:300']], self::MESSAGES);
        $term = ChatbotAssistant::term($d['term']);
        if (mb_strlen($term) < 2) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Escribe una frase con palabras que signifiquen algo (no solo «hola» o «de»).', ['errors' => ['term' => ['Escribe una frase con palabras que signifiquen algo.']]]);
        }
        if (! $this->assistant->byTarget($d['target_type'], $d['target_key'])) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Esa respuesta no está publicada.', ['errors' => ['target_key' => ['Esa respuesta no está publicada.']]]);
        }
        $this->db()->insert(
            "insert into chatbot_aliases (term, target_type, target_key, status, source, created_by, updated_by) values (?, ?, ?, 'active', 'owner', ?, ?)
             on conflict (term, target_type, target_key) do update set status = 'active', updated_by = excluded.updated_by, updated_at = now()",
            [$term, $d['target_type'], $d['target_key'], $request->user()->id, $request->user()->id]
        );
        $accounts->audit($request->user()->id, 'chatbot_alias_taught', 'chatbot_aliases', null, ['term' => $term, 'target' => $d['target_type'].':'.$d['target_key']]);

        return response()->json(['data' => ['term' => $term]], 201);
    }

    public function setAlias(Request $request, string $id, AccountsService $accounts): Response
    {
        $d = $request->validate(['status' => ['required', 'in:active,disabled']], self::MESSAGES);
        $this->db()->update('update chatbot_aliases set status = ?, updated_by = ?, updated_at = now() where id = ?', [$d['status'], $request->user()->id, $id]) ?: throw ApiProblem::notFound('El sinónimo no existe.');
        $accounts->audit($request->user()->id, 'chatbot_alias_'.($d['status'] === 'active' ? 'approved' : 'disabled'), 'chatbot_aliases', $id, []);

        return response()->noContent();
    }

    /** Probar una frase: qué entiende el asistente y qué respondería, y si una respuesta guiada en borrador coincidiría. */
    public function test(Request $request): JsonResponse
    {
        $raw = (string) $request->validate(['phrase' => ['required', 'string', 'max:500']])['phrase'];
        $phrase = mb_strtolower(trim($raw));
        $understood = $this->assistant->interpret($raw);
        $answer = $this->assistant->answer($raw);
        $hit = $this->db()->selectOne(
            "select x.id, x.name, x.answer, x.status from chatbot_intents x
              where x.deleted_at is null and x.status in ('published', 'draft')
                and exists (select 1 from jsonb_array_elements_text(x.trigger_phrases_json) ph where length(trim(ph)) > 0 and ? like '%' || lower(trim(ph)) || '%')
              order by (x.status = 'published') desc, x.sort_order, x.name limit 1", [$phrase]
        );

        return response()->json(['data' => $hit, 'assistant' => [
            'intent' => $understood['intent'], 'intent_label' => ChatbotAssistant::INTENTS[$understood['intent']] ?? $understood['intent'],
            'term' => $understood['term'], 'kind' => $answer['kind'], 'reply' => $answer['reply'], 'card' => $answer['card'] ?? null,
        ]]);
    }
}
