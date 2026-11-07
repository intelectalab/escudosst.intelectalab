<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use App\Modules\WEB\Mail\NewsletterIssueMail;
use App\Modules\WEB\Mail\SiteRequestMail;
use App\Modules\WEB\Services\NewsletterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Centro de confianza y estado del servicio (SCR-WEB-37/38 públicas, SCR-WEB-48/49 panel; RN-WEB-52 a 54).
 *  Público: GET  /public/trust                  subencargados activos y políticas vigentes
 *           GET  /public/status                 estado general (el peor componente), componentes e incidentes publicados
 *           POST /public/status/subscribe       avisos por correo (doble confirmación, sin crear interesado)
 *  Panel:   subencargados (CRUD y «avisar a los administradores»), componentes (estado manual), incidentes
 *           (crear, actualizar, publicar, resolver). Soporte registra incidentes; solo el Dueño publica.
 *
 * Nunca muestra datos de clientes. Al publicar o actualizar un incidente publicado se avisa a los suscriptores.
 */
class TrustStatusController extends Controller
{
    public const COMPONENT_STATUS = ['operational' => 'Operativo', 'degraded' => 'Degradado', 'partial_outage' => 'Caída parcial', 'major_outage' => 'Caída total', 'maintenance' => 'Mantenimiento'];

    public const IMPACT = ['minor' => 'Menor', 'major' => 'Mayor', 'critical' => 'Crítico', 'maintenance' => 'Mantenimiento'];

    public const INCIDENT_STATUS = ['investigating' => 'Investigando', 'identified' => 'Causa identificada', 'monitoring' => 'En observación', 'resolved' => 'Resuelto'];

    private const MESSAGES = [
        'required' => 'Completa este campo.',
        'email' => 'Escribe un correo válido.',
        'max' => 'Es muy largo.',
        'min' => 'Es muy corto.',
        'in' => 'Elige una opción de la lista.',
        'accepted' => 'Acepta la política de privacidad.',
        'regex' => 'Formato no válido.',
        'date' => 'Fecha no válida.',
        'size' => 'Usa el código de 2 letras del país (ej. US, DE).',
    ];

    public function __construct(private readonly NewsletterService $nl) {}

    private function db()
    {
        return $this->nl->db();
    }

    // ---------------------------------------------------------------- Público

    public function trust(): JsonResponse
    {
        $subs = json_decode($this->db()->selectOne('select public_subprocessors() as r')->r, true)['subprocessors'] ?? [];
        $docs = $this->db()->select(
            "select d.slug, d.title, v.version_label as version, v.published_at from legal_documents d
               join legal_document_versions v on v.id = d.current_version_id and v.status = 'published'
              where d.is_active and d.deleted_at is null order by d.title"
        );

        return response()->json(['data' => ['subprocessors' => $subs, 'documents' => $docs]])->header('Cache-Control', 'public, max-age=300');
    }

    public function status(): JsonResponse
    {
        $r = json_decode($this->db()->selectOne('select public_service_status() as r')->r, true);
        unset($r['result']);

        return response()->json(['data' => $r, 'labels' => ['status' => self::COMPONENT_STATUS, 'impact' => self::IMPACT, 'incident' => self::INCIDENT_STATUS]])
            ->header('Cache-Control', 'public, max-age=30');
    }

    public function subscribe(Request $request): JsonResponse
    {
        if (filled($request->input('website_hp'))) {
            return response()->json(['data' => ['ok' => true]], 201);
        }
        $d = $request->validate(['email' => ['required', 'email:rfc', 'max:160'], 'privacy_consent' => ['accepted']], self::MESSAGES);
        $this->nl->subscribe(mb_strtolower(trim($d['email'])), [NewsletterService::STATUS_TOPIC], null, hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')));

        return response()->json(['data' => ['ok' => true]], 201);
    }

    // ---------------------------------------------------------------- Panel

    public function index(): JsonResponse
    {
        $db = $this->db();
        $incidents = $db->select(
            "select i.id, i.title, i.impact, i.status, i.started_at, i.resolved_at, i.component_ids_json, i.published_at,
                    coalesce((select json_agg(json_build_object('status', u.status, 'message', u.message, 'published_at', u.published_at) order by u.published_at desc)
                                from status_incident_updates u where u.incident_id = i.id), '[]') as updates
               from status_incidents i where i.deleted_at is null order by (i.status <> 'resolved') desc, i.started_at desc limit 100"
        );
        foreach ($incidents as $i) {
            $i->component_ids_json = json_decode($i->component_ids_json, true);
            $i->updates = json_decode($i->updates, true);
        }

        return response()->json(['data' => [
            'subprocessors' => $db->select('select * from subprocessors where deleted_at is null order by sort_order, name'),
            'components' => $db->select('select id, name, code, description, current_status, sort_order, is_visible, updated_at from status_components where deleted_at is null order by sort_order, name'),
            'incidents' => $incidents,
            'status_subscribers' => (int) $db->selectOne("select count(*) as n from newsletter_subscribers where status = 'confirmed' and deleted_at is null and topics_json @> '[\"status\"]'::jsonb")->n,
        ], 'labels' => ['status' => self::COMPONENT_STATUS, 'impact' => self::IMPACT, 'incident' => self::INCIDENT_STATUS]]);
    }

    // Subencargados

    public function saveSubprocessor(Request $request, AccountsService $accounts, ?string $id = null): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'purpose' => ['required', 'string', 'min:5', 'max:300'],
            'country' => ['required', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'data_categories' => ['required', 'string', 'min:5', 'max:400'],
            'transfer_basis' => ['required', 'string', 'min:5', 'max:300'],
            'website' => ['nullable', 'string', 'max:300', 'regex:~^https://~'],
            'to_validate' => ['boolean'],
            'status' => ['required', 'in:active,inactive'],
            'sort_order' => ['integer', 'between:0,999'],
        ], self::MESSAGES + ['website.regex' => 'Escribe la dirección completa, con https://']);
        $row = [
            'name' => trim($d['name']), 'purpose' => trim($d['purpose']), 'country' => strtoupper($d['country']), 'data_categories' => trim($d['data_categories']),
            'transfer_basis' => trim($d['transfer_basis']), 'website' => $d['website'] ?? null, 'to_validate' => (bool) ($d['to_validate'] ?? false),
            'status' => $d['status'], 'sort_order' => $d['sort_order'] ?? 0, 'updated_by' => $request->user()->id,
        ];
        if ($id) {
            $this->db()->selectOne('select 1 from subprocessors where id = ? and deleted_at is null', [$id]) ?? throw ApiProblem::notFound('El subencargado no existe.');
            $this->db()->table('subprocessors')->where('id', $id)->update($row + ['updated_at' => now()]);
        } else {
            $id = $this->db()->table('subprocessors')->insertGetId($row + ['created_by' => $request->user()->id]);
        }
        $accounts->audit($request->user()->id, 'subprocessor_saved', 'subprocessors', $id, ['name' => $row['name'], 'status' => $row['status']]);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function deleteSubprocessor(Request $request, string $id, AccountsService $accounts): Response
    {
        $this->db()->update('update subprocessors set deleted_at = now(), updated_by = ? where id = ? and deleted_at is null', [$request->user()->id, $id]);
        $accounts->audit($request->user()->id, 'subprocessor_deleted', 'subprocessors', $id, []);

        return response()->noContent();
    }

    /** RN-WEB-52: avisa a los administradores de cuenta que la lista de subencargados cambió. */
    public function notifySubprocessors(Request $request, AccountsService $accounts): JsonResponse
    {
        $d = $request->validate(['summary' => ['required', 'string', 'min:10', 'max:600']], self::MESSAGES);
        $emails = [];
        foreach ($this->db()->select("select id from companies where deleted_at is null and status = 'active'") as $c) {
            foreach ($accounts->companyAdmins($c->id) as $a) {
                if ($a['status'] === 'active') {
                    $emails[mb_strtolower($a['email'])] = $a['full_name'];
                }
            }
        }
        $sent = 0;
        foreach ($emails as $email => $name) {
            $sent += $this->nl->mail($email, new SiteRequestMail(
                explode(' ', trim((string) $name))[0] ?: 'Hola', 'Actualizamos la lista de subencargados',
                'Te avisamos que cambió la lista de proveedores que tratan datos por encargo de EscudoSST.',
                [], strip_tags($d['summary']), 'Ver el centro de confianza', $this->nl->site().'/confianza'
            )) ? 1 : 0;
        }
        $accounts->audit($request->user()->id, 'subprocessors_notified', 'subprocessors', null, ['recipients' => $sent]);

        return response()->json(['data' => ['sent' => $sent]]);
    }

    // Componentes

    public function updateComponent(Request $request, string $id, AccountsService $accounts): Response
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'description' => ['nullable', 'string', 'max:200'],
            'current_status' => ['required', 'in:'.implode(',', array_keys(self::COMPONENT_STATUS))],
            'is_visible' => ['boolean'],
            'sort_order' => ['integer', 'between:0,999'],
        ], self::MESSAGES);
        $n = $this->db()->update(
            'update status_components set name = ?, description = ?, current_status = ?, is_visible = ?, sort_order = ?, updated_by = ? where id = ? and deleted_at is null',
            [trim($d['name']), $d['description'] ?? null, $d['current_status'], (bool) ($d['is_visible'] ?? true), $d['sort_order'] ?? 0, $request->user()->id, $id]
        );
        if (! $n) {
            throw ApiProblem::notFound('El componente no existe.');
        }
        $accounts->audit($request->user()->id, 'status_component_updated', 'status_components', $id, ['status' => $d['current_status']]);

        return response()->noContent();
    }

    // Incidentes

    public function storeIncident(Request $request, AccountsService $accounts): JsonResponse
    {
        $d = $this->incidentData($request);
        $id = $this->db()->table('status_incidents')->insertGetId([
            'title' => $d['title'], 'impact' => $d['impact'], 'status' => 'investigating',
            'started_at' => $d['started_at'] ?? DB::raw('now()'), 'component_ids_json' => json_encode($d['components']),
            'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
        ]);
        $accounts->audit($request->user()->id, 'status_incident_created', 'status_incidents', $id, ['impact' => $d['impact']]);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function updateIncident(Request $request, string $id, AccountsService $accounts): Response
    {
        $i = $this->findIncident($id);
        if ($i->published_at) {
            $this->fail('title', 'Ya está publicado: agrega una actualización en lugar de editarlo.');
        }
        $d = $this->incidentData($request);
        $this->db()->update(
            'update status_incidents set title = ?, impact = ?, started_at = ?, component_ids_json = ?::jsonb, updated_by = ? where id = ?',
            [$d['title'], $d['impact'], $d['started_at'] ?? $i->started_at, json_encode($d['components']), $request->user()->id, $id]
        );
        $accounts->audit($request->user()->id, 'status_incident_updated', 'status_incidents', $id, []);

        return response()->noContent();
    }

    /** Publica (solo Dueño): primer mensaje visible, estado de los componentes afectados y aviso a suscriptores. */
    public function publishIncident(Request $request, string $id, AccountsService $accounts): JsonResponse
    {
        $i = $this->findIncident($id);
        if ($i->published_at) {
            $this->fail('message', 'Ya está publicado.');
        }
        $d = $this->updateData($request, false);
        $db = $this->db();
        $db->transaction(function () use ($db, $id, $d, $request, $i) {
            $db->select("select set_config('app.user_id', ?, true)", [$request->user()->id]);
            $db->selectOne('select web_publish_incident(?, ?)', [$id, $d['message']]);
            $this->applyComponents($i, $d['component_status'] ?? $this->defaultComponentStatus($i->impact), $request->user()->id);
        });
        $accounts->audit($request->user()->id, 'status_incident_published', 'status_incidents', $id, []);
        $notified = $this->notify($this->findIncident($id), $d['message']);

        return response()->json(['data' => ['notified' => $notified]]);
    }

    /** Agrega una actualización (inalterable). En uno publicado solo la agrega el Dueño y se avisa a los suscriptores. */
    public function addUpdate(Request $request, string $id, AccountsService $accounts): JsonResponse
    {
        $i = $this->findIncident($id);
        if ($i->status === 'resolved') {
            $this->fail('status', 'El incidente ya está resuelto.');
        }
        if ($request->input('status') === 'resolved' && strtotime($i->started_at) > time()) {
            $this->fail('status', 'Aún no empieza: no se puede marcar como resuelto antes de su hora de inicio.');
        }
        if ($i->published_at && $request->user()->platform_role === 'support') {
            throw new ApiProblem(403, 'forbidden', 'Sin permiso', 'Solo el Dueño agrega actualizaciones a un incidente publicado.');
        }
        $d = $this->updateData($request, true);
        $db = $this->db();
        $db->transaction(function () use ($db, $id, $d, $request, $i) {
            $db->selectOne('select web_add_incident_update(?, ?, ?)', [$id, $d['status'], $d['message']]);
            if ($i->published_at) {
                $this->applyComponents($i, $d['status'] === 'resolved' ? 'operational' : ($d['component_status'] ?? null), $request->user()->id);
            }
        });
        $accounts->audit($request->user()->id, 'status_incident_update', 'status_incidents', $id, ['status' => $d['status']]);
        $notified = $i->published_at ? $this->notify($this->findIncident($id), $d['message']) : 0;

        return response()->json(['data' => ['notified' => $notified]]);
    }

    public function deleteIncident(Request $request, string $id, AccountsService $accounts): Response
    {
        $i = $this->findIncident($id);
        if ($i->published_at) {
            $this->fail('id', 'Un incidente publicado no se elimina: resuélvelo.');
        }
        $this->db()->update('update status_incidents set deleted_at = now(), updated_by = ? where id = ?', [$request->user()->id, $id]);
        $accounts->audit($request->user()->id, 'status_incident_deleted', 'status_incidents', $id, []);

        return response()->noContent();
    }

    // ---------------------------------------------------------------- Apoyo

    private function incidentData(Request $request): array
    {
        $d = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:160'],
            'impact' => ['required', 'in:'.implode(',', array_keys(self::IMPACT))],
            'components' => ['required', 'array', 'min:1'],
            'components.*' => ['uuid'],
            'started_at' => ['nullable', 'date'],
        ], self::MESSAGES + ['components.required' => 'Elige al menos un componente afectado.', 'components.min' => 'Elige al menos un componente afectado.']);
        $ids = array_values(array_unique($d['components']));
        $n = (int) $this->db()->selectOne('select count(*) as n from status_components where id = any(?::uuid[]) and deleted_at is null', ['{'.implode(',', $ids).'}'])->n;
        if ($n !== count($ids)) {
            $this->fail('components', 'Uno de los componentes ya no existe.');
        }

        return ['title' => trim($d['title']), 'impact' => $d['impact'], 'components' => $ids, 'started_at' => $d['started_at'] ?? null];
    }

    private function updateData(Request $request, bool $withStatus): array
    {
        return $request->validate(array_filter([
            'status' => $withStatus ? ['required', 'in:'.implode(',', array_keys(self::INCIDENT_STATUS))] : null,
            'message' => ['required', 'string', 'min:5', 'max:2000'],
            'component_status' => ['nullable', 'in:'.implode(',', array_keys(self::COMPONENT_STATUS))],
        ]), self::MESSAGES);
    }

    private function defaultComponentStatus(string $impact): string
    {
        return ['minor' => 'degraded', 'major' => 'partial_outage', 'critical' => 'major_outage', 'maintenance' => 'maintenance'][$impact];
    }

    /** Cambia el estado de los componentes del incidente. Al resolver, solo vuelven a «operativo» los que no tengan otro incidente abierto. */
    private function applyComponents(object $i, ?string $status, string $actor): void
    {
        if (! $status) {
            return;
        }
        $ids = json_decode($i->component_ids_json, true) ?: [];
        foreach ($ids as $cid) {
            if ($status === 'operational' && $this->db()->selectOne(
                "select 1 from status_incidents where id <> ? and status <> 'resolved' and published_at is not null and deleted_at is null and component_ids_json @> jsonb_build_array(?::text)",
                [$i->id, $cid]
            )) {
                continue;
            }
            $this->db()->update('update status_components set current_status = ?, updated_by = ? where id = ?', [$status, $actor, $cid]);
        }
    }

    /** Aviso a los suscriptores confirmados de «estado del servicio». */
    private function notify(object $i, string $message): int
    {
        $subs = $this->db()->select("select email from newsletter_subscribers where status = 'confirmed' and deleted_at is null and topics_json @> '[\"status\"]'::jsonb");
        $title = ($i->status === 'resolved' ? 'Resuelto: ' : ($i->impact === 'maintenance' ? 'Mantenimiento: ' : 'Incidente: ')).$i->title;
        $blocks = [['t' => 'h', 'text' => self::INCIDENT_STATUS[$i->status]], ['t' => 'p', 'text' => $message]];
        $sent = 0;
        foreach ($subs as $s) {
            $token = $this->nl->unsubscribeToken($s->email);
            $sent += $this->nl->mail($s->email, new NewsletterIssueMail(
                $title, $message, $blocks, $this->nl->site().'/estado',
                $this->nl->site().'/boletin/baja/'.$token,
                rtrim((string) config('app.url'), '/').'/api/v1/public/newsletter/unsubscribe/'.$token,
                false, 'ESTADO DEL SERVICIO', 'Recibes este aviso porque pediste avisos del estado de EscudoSST y confirmaste tu correo.', false,
            )) ? 1 : 0;
        }

        return $sent;
    }

    private function findIncident(string $id): object
    {
        return $this->db()->selectOne('select * from status_incidents where id = ? and deleted_at is null', [$id]) ?? throw ApiProblem::notFound('El incidente no existe.');
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}
