<?php

namespace App\Modules\WEB\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Envíos al CRM (SCR-WEB-63/64; RN-WEB-78 a 81). La base encola un crm_sync_events por cada alta o cambio de las
 * entidades habilitadas (con clave de idempotencia). Aquí se arma el cuerpo, se firma y se envía; los fallos se
 * reintentan con espera creciente (la calcula la base) hasta MAX_ATTEMPTS y luego quedan «fallidos» con aviso.
 *
 *  - Webhook genérico: cabecera X-Escudo-Signature: t=<marca de tiempo>,v1=HMAC-SHA256(secreto, "<t>.<cuerpo>").
 *  - Kallpa CRM: misma forma con Authorization: Bearer <credencial> (los endpoints exactos de Kallpa están por confirmar).
 *  - Fidelia (CRM de Intelectalab): POST <url>/api/v1/ext/leads con Authorization: Bearer fcrm_… y el formato de lead
 *    de Fidelia. Solo recibe altas de leads: los cambios y las demás entidades se marcan «descartado» con el motivo.
 *  - El secreto se guarda como referencia (env://NOMBRE_VARIABLE); nunca se muestra ni se guarda en la base.
 *  - Nunca se envían reclamos ni datos médicos: las entidades posibles son solo las cinco de abajo.
 */
class CrmService
{
    public const ENTITIES = [
        'marketing_lead' => 'Interesados',
        'demo_request' => 'Solicitudes de demo y contacto',
        'demo_meeting' => 'Reuniones de demo',
        'partner_application' => 'Solicitudes de partner',
        'newsletter_subscriber' => 'Suscriptores del boletín',
    ];

    /** Campos que puede llevar cada entidad (y que el mapeo puede renombrar). */
    public const FIELDS = [
        'marketing_lead' => ['email', 'full_name', 'company_name', 'ruc', 'phone', 'sector', 'workers_range', 'source', 'status', 'marketing_consent', 'utm_source', 'utm_medium', 'utm_campaign', 'created_at'],
        'demo_request' => ['request_type', 'full_name', 'company_name', 'ruc', 'job_title', 'email', 'phone', 'headcount', 'economic_sector', 'interest_plan_code', 'message', 'status', 'marketing_consent', 'source_page', 'created_at'],
        'demo_meeting' => ['demo_request_id', 'starts_at', 'ends_at', 'status', 'meeting_provider', 'outcome', 'outcome_notes', 'recording_consent'],
        'partner_application' => ['partner_type', 'company_name', 'ruc', 'website', 'status', 'created_at'],
        'newsletter_subscriber' => ['email', 'topics_json', 'status', 'confirmed_at', 'unsubscribed_at'],
    ];

    private const TABLES = ['marketing_lead' => 'marketing_leads', 'demo_request' => 'demo_requests', 'demo_meeting' => 'demo_meetings', 'partner_application' => 'partner_applications', 'newsletter_subscriber' => 'newsletter_subscribers'];

    public const MAX_ATTEMPTS = 6;

    public function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    /** Resuelve la referencia del secreto. Hoy solo env://VARIABLE (el gestor de secretos se conecta al desplegar). */
    public function secret(?string $ref): ?string
    {
        if ($ref && preg_match('~^env://([A-Z][A-Z0-9_]{2,63})$~', $ref, $m)) {
            // getenv/$_ENV y no env(): con la configuración en caché (producción) env() devuelve null fuera de config/.
            $v = getenv($m[1]) ?: ($_ENV[$m[1]] ?? $_SERVER[$m[1]] ?? null);

            return is_string($v) && $v !== '' ? $v : null;
        }

        return null;
    }

    public function payload(object $e, object $conn): ?array
    {
        $row = $this->db()->selectOne('select * from '.self::TABLES[$e->entity_type].' where id = ?', [$e->entity_id]);
        if (! $row) {
            return null;
        }
        $map = json_decode($conn->field_mapping_json, true)[$e->entity_type] ?? [];
        $data = [];
        foreach (self::FIELDS[$e->entity_type] as $f) {
            if (property_exists($row, $f)) {
                $v = $row->{$f};
                $data[$map[$f] ?? $f] = $f === 'topics_json' ? json_decode((string) $v, true) : $v;
            }
        }
        // Para unir con la web: id del interesado y sus UTM (RN-WEB-67).
        if ($e->entity_type === 'demo_request') {
            $data['lead_id'] = $this->db()->selectOne('select id from marketing_leads where demo_request_id = ?', [$e->entity_id])?->id;
        }

        return ['event' => $e->entity_type.'.'.$e->action, 'id' => $e->entity_id, 'idempotency_key' => $e->idempotency_key, 'occurred_at' => now()->toIso8601String(), 'data' => $data];
    }

    /** Fidelia: un lead por cada interesado nuevo (o solicitud de demo o partner nuevo, si se eligieron). */
    private function sendFidelia(object $e, object $conn): void
    {
        $skip = fn (string $why) => $this->db()->update("update crm_sync_events set status = 'skipped', last_error = ? where id = ?", [$why, $e->id]);
        if ($e->action !== 'create') {
            $skip('Fidelia solo recibe registros nuevos (no cambios).');

            return;
        }
        $lead = $this->fideliaLead($e);
        if ($lead === null) {
            $skip('Fidelia no recibe este tipo de registro.');

            return;
        }
        [$ok, $code, $ext, $err] = $this->post($conn, $lead, $e->idempotency_key);
        // 409 con id: el lead ya existía en Fidelia con ese correo; queda enlazado, no es un error.
        if (! $ok && $code === 409 && $ext) {
            [$ok, $err] = [true, null];
        }
        $this->db()->selectOne('select web_crm_event_result(?, ?, ?, ?, ?)', [$e->id, $ok, $code, $ext, $err]);
    }

    public function fideliaLead(object $e): ?array
    {
        $src = ['marketing_lead' => 'marketing_leads', 'demo_request' => 'demo_requests', 'partner_application' => 'partner_applications'][$e->entity_type] ?? null;
        if (! $src) {
            return null;
        }
        $r = $this->db()->selectOne("select * from {$src} where id = ?", [$e->entity_id]);
        if (! $r) {
            return null;
        }
        if ($e->entity_type === 'partner_application') {
            $l = $this->db()->selectOne('select full_name, email, phone from marketing_leads where id = ?', [$r->lead_id]);
            $r = (object) ['full_name' => $l->full_name ?? null, 'email' => $l->email ?? null, 'phone' => $l->phone ?? null, 'company_name' => $r->company_name, 'source' => 'partner', 'note' => "Solicitud de partner ({$r->partner_type}). RUC: ".($r->ruc ?? '—').'. Web: '.($r->website ?? '—')];
        }
        $name = trim((string) ($r->full_name ?? '')) ?: (string) strtok((string) $r->email, '@');
        $parts = preg_split('/\s+/', $name, 2);
        $origin = ['fine_calculator' => 'calculadora de multas', 'self_assessment' => 'autodiagnóstico', 'resource' => 'recurso descargable', 'partner' => 'partners', 'newsletter' => 'boletín', 'demo' => 'demo', 'contact' => 'contacto'][$r->source ?? ''] ?? ($r->source ?? $e->entity_type);
        $note = array_filter([
            'Llegó desde el sitio de EscudoSST ('.$origin.').',
            isset($r->ruc) && $r->ruc ? 'RUC: '.$r->ruc : null,
            isset($r->sector) && $r->sector ? 'Rubro: '.$r->sector : (isset($r->economic_sector) && $r->economic_sector ? 'Rubro: '.$r->economic_sector : null),
            isset($r->headcount) && $r->headcount ? 'Trabajadores: '.$r->headcount : null,
            isset($r->request_type) ? 'Pidió: '.(['demo' => 'una demo', 'contact' => 'una consulta', 'quote' => 'una cotización'][$r->request_type] ?? $r->request_type) : null,
            isset($r->message) && $r->message ? 'Mensaje: '.$r->message : null,
            isset($r->note) ? $r->note : null,
            isset($r->utm_campaign) && $r->utm_campaign ? 'Campaña: '.$r->utm_campaign : null,
            'Acepta publicidad: '.(! empty($r->marketing_consent) ? 'sí' : 'no'),
        ]);

        return array_filter([
            'first_name' => mb_substr($parts[0], 0, 100), 'last_name' => isset($parts[1]) ? mb_substr($parts[1], 0, 100) : null,
            'company_name' => mb_substr(trim((string) ($r->company_name ?? '')) ?: 'Sin empresa ('.$name.')', 0, 200),
            'email' => $r->email, 'phone' => isset($r->phone) ? mb_substr((string) $r->phone, 0, 30) : null,
            'job_title' => isset($r->job_title) ? $r->job_title : null,
            'source' => 'EscudoSST web', 'note' => mb_substr(implode("\n", $note), 0, 5000),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** Prueba de conexión. En Fidelia lee la lista (no crea un lead de prueba); en los demás envía un «ping». */
    public function ping(object $conn): array
    {
        if ($conn->provider !== 'fidelia') {
            return $this->post($conn, ['event' => 'ping', 'occurred_at' => now()->toIso8601String(), 'data' => ['message' => 'Prueba de conexión desde EscudoSST']], 'ping-'.now()->timestamp);
        }
        $secret = $this->secret($conn->secret_ref);
        if (! $conn->base_url || ! $secret) {
            return [false, null, null, 'Falta la dirección o la llave de Fidelia.'];
        }
        try {
            $r = Http::withToken($secret)->acceptJson()->timeout(10)->get($conn->base_url, ['per_page' => 1]);
            $msg = $r->json('message');

            return $r->successful() ? [true, $r->status(), null, null] : [false, $r->status(), null, 'Fidelia respondió '.$r->status().': '.(is_string($msg) ? $msg : 'sin detalle')];
        } catch (\Throwable $e) {
            return [false, null, null, 'No se pudo conectar con Fidelia: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /** Envía y devuelve [ok, código, id externo, error]. */
    public function post(object $conn, array $body, string $idempotency): array
    {
        $secret = $this->secret($conn->secret_ref);
        if (! $conn->base_url || ! $secret) {
            return [false, null, null, 'Falta la dirección o el secreto de la conexión.'];
        }
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $t = (string) time();
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'Idempotency-Key' => $idempotency, 'User-Agent' => 'EscudoSST-CRM/1.0'];
        if (in_array($conn->provider, ['kallpa', 'fidelia'], true)) {
            $headers['Authorization'] = 'Bearer '.$secret;
        } else {
            $headers['X-Escudo-Signature'] = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$json, $secret);
        }
        try {
            $r = Http::withHeaders($headers)->timeout(10)->withBody($json, 'application/json')->post($conn->base_url);
            $ext = $r->json('id') ?? $r->json('data.id');
            $ext = is_scalar($ext) ? (string) $ext : null;
            $msg = $r->json('message');

            return $r->successful() ? [true, $r->status(), $ext, null] : [false, $r->status(), $ext, 'El CRM respondió '.$r->status().': '.mb_substr(is_string($msg) ? $msg : $r->body(), 0, 300)];
        } catch (\Throwable $e) {
            return [false, null, null, 'No se pudo conectar: '.mb_substr($e->getMessage(), 0, 300)];
        }
    }

    /** Procesa la cola: pendientes y fallidos cuyo próximo intento ya tocó (con tope de intentos). */
    public function processDue(int $limit = 50): int
    {
        $events = $this->db()->select(
            "select e.* from crm_sync_events e join crm_connections c on c.id = e.connection_id and c.status = 'active' and c.deleted_at is null
              where (e.status = 'pending' or (e.status = 'failed' and e.attempts < ?)) and coalesce(e.next_attempt_at, now()) <= now()
              order by e.created_at limit ?", [self::MAX_ATTEMPTS, $limit]
        );
        $n = 0;
        foreach ($events as $e) {
            $this->sendOne($e);
            $n++;
        }

        return $n;
    }

    public function sendOne(object $e): void
    {
        $conn = $this->db()->selectOne('select * from crm_connections where id = ?', [$e->connection_id]);
        if ($conn->provider === 'fidelia') {
            $this->sendFidelia($e, $conn);

            return;
        }
        $body = $this->payload($e, $conn);
        if ($body === null) {
            $this->db()->update("update crm_sync_events set status = 'skipped', last_error = 'El registro ya no existe.' where id = ?", [$e->id]);

            return;
        }
        [$ok, $code, $ext, $err] = $this->post($conn, $body, $e->idempotency_key);
        $this->db()->selectOne('select web_crm_event_result(?, ?, ?, ?, ?)', [$e->id, $ok, $code, $ext, $err]);
    }
}
