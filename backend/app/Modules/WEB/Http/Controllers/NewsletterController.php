<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use App\Modules\WEB\Mail\NewsletterIssueMail;
use App\Modules\WEB\Services\NewsletterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Boletín normativo SST (SCR-WEB-36 pública /boletin, SCR-WEB-47 panel; RN-WEB-49 a 51).
 *  Público: POST /public/newsletter                         suscribirse (doble confirmación)
 *           POST /public/newsletter/confirm                 {token}
 *           POST /public/newsletter/unsubscribe/{token}     baja de un clic (también para List-Unsubscribe-Post)
 *           GET  /public/newsletter/issues[/{slug}]         archivo de ediciones enviadas
 *  Panel:   GET  /internal/site/newsletter                  suscriptores (resumen) y ediciones
 *           POST/PATCH/DELETE …/issues, POST …/issues/{id}/test (a tu correo), POST …/{send|cancel} (solo Dueño)
 */
class NewsletterController extends Controller
{
    private const MESSAGES = [
        'required' => 'Completa este campo.',
        'email' => 'Escribe un correo válido.',
        'max' => 'Es muy largo.',
        'min' => 'Es muy corto.',
        'in' => 'Elige una opción de la lista.',
        'accepted' => 'Para suscribirte, acepta la política de privacidad.',
        'regex' => 'Solo minúsculas, números y guiones.',
        'date' => 'Fecha no válida.',
        'after' => 'Elige una fecha y hora futura.',
        'topics.min' => 'Elige al menos un tema.',
    ];

    public function __construct(private readonly NewsletterService $svc) {}

    private function ipHash(Request $request): string
    {
        return hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
    }

    // ---------------------------------------------------------------- Público

    public function subscribe(Request $request): JsonResponse
    {
        if (filled($request->input('website_hp'))) {   // trampa para robots
            return response()->json(['data' => ['ok' => true]], 201);
        }
        $d = $request->validate([
            'email' => ['required', 'email:rfc', 'max:160'],
            'full_name' => ['nullable', 'string', 'max:120'],
            'topics' => ['required', 'array', 'min:1'],
            'topics.*' => ['in:'.implode(',', array_keys(NewsletterService::TOPICS))],
            'privacy_consent' => ['accepted'],
            'marketing_consent' => ['boolean'],
        ], self::MESSAGES);
        $this->svc->subscribe(mb_strtolower(trim($d['email'])), array_values(array_unique($d['topics'])), [
            'full_name' => $d['full_name'] ?? null, 'privacy_consent' => true, 'marketing_consent' => (bool) ($d['marketing_consent'] ?? false),
        ], $this->ipHash($request));

        // Misma respuesta si ya estaba suscrito: no revela quién está en la lista.
        return response()->json(['data' => ['ok' => true]], 201);
    }

    public function confirm(Request $request): JsonResponse
    {
        $token = (string) $request->input('token', '');
        $r = strlen($token) >= 32 && strlen($token) <= 128
            ? json_decode($this->svc->db()->selectOne('select public_newsletter_confirm(?) as r', [$token])->r, true)
            : ['result' => 'invalid'];

        return response()->json(['data' => ['result' => $r['result']]]);
    }

    public function unsubscribe(Request $request, string $token): JsonResponse
    {
        $r = json_decode($this->svc->db()->selectOne('select public_newsletter_unsubscribe(?) as r', [$token])->r, true);
        $issue = $request->query('e');
        if (($r['result'] ?? '') === 'unsubscribed' && is_string($issue) && Str::isUuid($issue)) {
            $this->svc->db()->update("update newsletter_issues set unsubscribes_count = unsubscribes_count + 1 where id = ? and status = 'sent'", [$issue]);
        }

        return response()->json(['data' => ['result' => $r['result']]]);
    }

    public function issues(): JsonResponse
    {
        $rows = $this->svc->db()->select(
            "select slug, subject, preheader, topics_json, sent_at, left(body, 400) as excerpt from newsletter_issues
              where status = 'sent' and deleted_at is null order by sent_at desc limit 60"
        );
        foreach ($rows as $r) {
            $r->topics_json = json_decode($r->topics_json, true);
            $plain = trim(preg_replace(['/^#+\s*/m', '/^-\s+/m', '/\s+/'], ['', '', ' '], $r->excerpt));
            $r->excerpt = mb_strlen($plain) > 200 ? rtrim(mb_substr($plain, 0, 200)).'…' : $plain;
        }

        return response()->json(['data' => $rows, 'topics' => NewsletterService::TOPICS])->header('Cache-Control', 'public, max-age=60');
    }

    public function issue(string $slug): JsonResponse
    {
        $r = $this->svc->db()->selectOne(
            "select slug, subject, preheader, body, topics_json, sent_at from newsletter_issues where slug = ? and status = 'sent' and deleted_at is null", [$slug]
        ) ?? throw ApiProblem::notFound('Esta edición no existe o aún no se envía.');
        $r->topics_json = json_decode($r->topics_json, true);

        return response()->json(['data' => $r, 'topics' => NewsletterService::TOPICS])->header('Cache-Control', 'public, max-age=60');
    }

    // ---------------------------------------------------------------- Panel

    public function index(): JsonResponse
    {
        $db = $this->svc->db();
        $by = $db->select("select status, count(*) as n from newsletter_subscribers where deleted_at is null and not (topics_json = '[\"status\"]'::jsonb) group by status");
        $topics = [];
        foreach (array_keys(NewsletterService::TOPICS) + [99 => NewsletterService::STATUS_TOPIC] as $t) {
            $topics[$t] = (int) $db->selectOne("select count(*) as n from newsletter_subscribers where status = 'confirmed' and deleted_at is null and topics_json @> jsonb_build_array(?::text)", [$t])->n;
        }
        // Solo se listan correos confirmados (los pendientes no dieron su consentimiento completo).
        $confirmed = $db->select(
            "select email, topics_json, confirmed_at from newsletter_subscribers where status = 'confirmed' and deleted_at is null order by confirmed_at desc limit 300"
        );
        foreach ($confirmed as $c) {
            $c->topics_json = json_decode($c->topics_json, true);
        }
        $month = $db->selectOne(
            "select count(*) filter (where confirmed_at >= date_trunc('month', now())) as altas,
                    count(*) filter (where unsubscribed_at >= date_trunc('month', now())) as bajas from newsletter_subscribers where deleted_at is null"
        );
        $issues = $db->select(
            'select id, subject, slug, preheader, body, topics_json, status, scheduled_at, sent_at, recipients_count, opens_count, clicks_count, unsubscribes_count, updated_at,
                    (select count(*) from newsletter_deliveries d where d.issue_id = i.id and d.status = \'failed\') as failed
               from newsletter_issues i where deleted_at is null order by (status = \'draft\') desc, coalesce(sent_at, scheduled_at, created_at) desc'
        );
        foreach ($issues as $i) {
            $i->topics_json = json_decode($i->topics_json, true);
        }

        return response()->json(['data' => [
            'subscribers' => collect($by)->mapWithKeys(fn ($r) => [$r->status => (int) $r->n]),
            'by_topic' => $topics, 'confirmed' => $confirmed, 'month' => $month, 'issues' => $issues,
        ], 'topics' => NewsletterService::TOPICS]);
    }

    public function store(Request $request, AccountsService $accounts): JsonResponse
    {
        $row = $this->issueData($request, null);
        $actor = $request->user()->id;
        $id = $this->svc->db()->table('newsletter_issues')->insertGetId($row + ['created_by' => $actor, 'updated_by' => $actor]);
        $accounts->audit($actor, 'newsletter_issue_created', 'newsletter_issues', $id, ['slug' => $row['slug']]);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function update(Request $request, string $id, AccountsService $accounts): Response
    {
        $current = $this->find($id);
        if ($current->status !== 'draft') {
            $this->fail('subject', $current->status === 'sent' ? 'Ya se envió: una edición enviada no se edita.' : 'Está programada: cancela el envío para editarla.');
        }
        $row = $this->issueData($request, $current);
        $this->svc->db()->table('newsletter_issues')->where('id', $id)->update($row + ['updated_by' => $request->user()->id, 'updated_at' => now()]);
        $accounts->audit($request->user()->id, 'newsletter_issue_updated', 'newsletter_issues', $id, ['slug' => $row['slug']]);

        return response()->noContent();
    }

    public function destroy(Request $request, string $id, AccountsService $accounts): Response
    {
        $i = $this->find($id);
        if ($i->status !== 'draft') {
            $this->fail('id', 'Solo se elimina un borrador.');
        }
        $this->svc->db()->update('update newsletter_issues set deleted_at = now(), updated_by = ? where id = ?', [$request->user()->id, $id]);
        $accounts->audit($request->user()->id, 'newsletter_issue_deleted', 'newsletter_issues', $id, []);

        return response()->noContent();
    }

    /** Envío de prueba al correo de quien está en el panel (no cuenta como envío). */
    public function test(Request $request, string $id): JsonResponse
    {
        $i = $this->find($id);
        $email = $request->user()->email;
        $ok = $this->svc->mail($email, $this->svc->issueMail($i, NewsletterIssueMail::blocks($i->body), $email, true));
        if (! $ok) {
            throw new ApiProblem(502, 'mail_failed', 'No se pudo enviar', 'El servidor de correo no respondió. Inténtalo de nuevo en un momento.');
        }

        return response()->json(['data' => ['email' => $email]]);
    }

    public function send(Request $request, string $id, AccountsService $accounts): JsonResponse
    {
        $d = $request->validate(['at' => ['nullable', 'date', 'after:now']], self::MESSAGES);
        $this->find($id);
        $db = $this->svc->db();
        $r = $db->transaction(function () use ($db, $id, $d, $request) {
            $db->select("select set_config('app.user_id', ?, true)", [$request->user()->id]);

            return json_decode($db->selectOne('select web_send_newsletter_issue(?, ?) as r', [$id, $d['at'] ?? null])->r, true);
        });
        $accounts->audit($request->user()->id, 'newsletter_issue_scheduled', 'newsletter_issues', $id, ['at' => $r['scheduled_at'], 'recipients' => $r['recipients_count']]);
        if (empty($d['at'])) {
            $r['sent'] = $this->svc->sendDue($id);   // «Enviar ahora»: sale en esta misma acción
            $r['status'] = 'sent';
        }

        return response()->json(['data' => $r]);
    }

    public function cancel(Request $request, string $id, AccountsService $accounts): Response
    {
        $i = $this->find($id);
        if ($i->status !== 'scheduled') {
            $this->fail('id', 'Solo se cancela un envío programado.');
        }
        $this->svc->db()->update("update newsletter_issues set status = 'draft', scheduled_at = null, updated_by = ? where id = ? and status = 'scheduled'", [$request->user()->id, $id]);
        $accounts->audit($request->user()->id, 'newsletter_issue_cancelled', 'newsletter_issues', $id, []);

        return response()->noContent();
    }

    // ---------------------------------------------------------------- Apoyo

    private function issueData(Request $request, ?object $current): array
    {
        $d = $request->validate([
            'subject' => ['required', 'string', 'min:5', 'max:140'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'preheader' => ['nullable', 'string', 'max:150'],
            'body' => ['required', 'string', 'min:20', 'max:30000'],
            'topics' => ['required', 'array', 'min:1'],
            'topics.*' => ['in:'.implode(',', array_keys(NewsletterService::TOPICS))],
        ], self::MESSAGES);
        $slug = ($d['slug'] ?? null) ?: Str::slug($d['subject']);
        if ($this->svc->db()->selectOne('select 1 from newsletter_issues where slug = ? and deleted_at is null and id is distinct from ?::uuid', [$slug, $current?->id])) {
            $this->fail('slug', 'Ya hay otra edición con esta dirección.');
        }

        return [
            'subject' => trim($d['subject']), 'slug' => $slug, 'preheader' => filled($d['preheader'] ?? null) ? trim($d['preheader']) : null,
            'body' => str_replace("\r\n", "\n", trim(strip_tags($d['body']))), 'topics_json' => json_encode(array_values(array_unique($d['topics']))),
        ];
    }

    private function find(string $id): object
    {
        return $this->svc->db()->selectOne('select * from newsletter_issues where id = ? and deleted_at is null', [$id]) ?? throw ApiProblem::notFound('La edición no existe.');
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}
