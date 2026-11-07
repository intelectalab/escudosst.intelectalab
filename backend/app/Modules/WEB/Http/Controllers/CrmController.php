<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use App\Modules\WEB\Services\CrmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conexión con el CRM y cola de envíos (SCR-WEB-63/64; RN-WEB-78 a 81). Todo es solo del Dueño de la plataforma:
 * Soporte no ve la conexión. El secreto se guarda como referencia y nunca se muestra.
 */
class CrmController extends Controller
{
    private const MESSAGES = [
        'required' => 'Completa este campo.',
        'in' => 'Elige una opción de la lista.',
        'regex' => 'Formato no válido.',
        'max' => 'Es muy largo.',
    ];

    public function __construct(private readonly CrmService $svc) {}

    private function conn(): object
    {
        return $this->svc->db()->selectOne('select * from crm_connections where deleted_at is null order by created_at limit 1')
            ?? throw ApiProblem::notFound('No hay una conexión configurada.');
    }

    public function index(Request $request): JsonResponse
    {
        $c = $this->conn();
        $status = in_array($request->query('status'), ['pending', 'sent', 'failed', 'skipped'], true) ? $request->query('status') : null;
        $events = $this->svc->db()->select(
            'select id, entity_type, entity_id, action, status, attempts, next_attempt_at, external_id, response_code, last_error, created_at, updated_at
               from crm_sync_events where connection_id = ?'.($status ? ' and status = ?' : '').' order by created_at desc limit 200',
            $status ? [$c->id, $status] : [$c->id]
        );
        $counts = $this->svc->db()->select('select status, count(*)::int as n from crm_sync_events where connection_id = ? group by status', [$c->id]);

        return response()->json(['data' => [
            'connection' => [
                'id' => $c->id, 'provider' => $c->provider, 'name' => $c->name, 'base_url' => $c->base_url, 'status' => $c->status,
                'secret_ref' => $c->secret_ref ? preg_replace('~^(env://).*$~', '$1•••', $c->secret_ref) : null,   // nunca el nombre completo ni el valor
                'secret_ready' => (bool) $this->svc->secret($c->secret_ref),
                'entities' => json_decode($c->entities_json, true), 'mapping' => json_decode($c->field_mapping_json, true) ?: new \stdClass,
                'last_sync_at' => $c->last_sync_at, 'last_error' => $c->last_error,
            ],
            'events' => $events,
            'counts' => collect($counts)->mapWithKeys(fn ($r) => [$r->status => $r->n]),
        ], 'entities' => CrmService::ENTITIES, 'fields' => CrmService::FIELDS, 'max_attempts' => CrmService::MAX_ATTEMPTS]);
    }

    public function update(Request $request, AccountsService $accounts): JsonResponse
    {
        $c = $this->conn();
        $d = $request->validate([
            'provider' => ['required', 'in:kallpa,generic_webhook,fidelia'],
            'name' => ['required', 'string', 'min:3', 'max:80'],
            'base_url' => ['nullable', 'string', 'max:300', 'regex:~^https://~'],
            'secret_ref' => ['nullable', 'string', 'regex:~^env://[A-Z][A-Z0-9_]{2,63}$~'],
            'entities' => ['array'],
            'entities.*' => ['in:'.implode(',', array_keys(CrmService::ENTITIES))],
            'mapping' => ['array'],
        ], self::MESSAGES + ['base_url.regex' => 'La dirección debe empezar con https://', 'secret_ref.regex' => 'Escribe env:// y el nombre de la variable en MAYÚSCULAS, por ejemplo env://CRM_WEBHOOK_SECRET.']);
        $mapping = [];
        foreach ($d['mapping'] ?? [] as $entity => $fields) {
            if (! isset(CrmService::FIELDS[$entity]) || ! is_array($fields)) {
                continue;
            }
            foreach ($fields as $src => $dst) {
                if (in_array($src, CrmService::FIELDS[$entity], true) && is_string($dst) && preg_match('/^[A-Za-z][A-Za-z0-9_.]{0,63}$/', $dst) && $dst !== $src) {
                    $mapping[$entity][$src] = $dst;
                }
            }
        }
        $this->svc->db()->update(
            'update crm_connections set provider = ?, name = ?, base_url = ?, secret_ref = coalesce(?, secret_ref), entities_json = ?::jsonb, field_mapping_json = ?::jsonb, updated_by = ?, updated_at = now() where id = ?',
            [$d['provider'], trim($d['name']), $d['base_url'] ?? null, $d['secret_ref'] ?? null, json_encode(array_values(array_unique($d['entities'] ?? []))), json_encode((object) $mapping), $request->user()->id, $c->id]
        );
        $accounts->audit($request->user()->id, 'crm_connection_updated', 'crm_connections', $c->id, ['provider' => $d['provider'], 'entities' => $d['entities'] ?? []]);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function test(Request $request, AccountsService $accounts): JsonResponse
    {
        $c = $this->conn();
        [$ok, $code, , $err] = $this->svc->ping($c);
        $r = json_decode($this->svc->db()->selectOne('select web_crm_connection_tested(?, ?, ?) as r', [$c->id, $ok, $err])->r, true);
        $accounts->audit($request->user()->id, 'crm_connection_tested', 'crm_connections', $c->id, ['ok' => $ok, 'code' => $code]);

        return response()->json(['data' => ['ok' => $ok, 'code' => $code, 'error' => $err, 'status' => $r['status']]]);
    }

    public function setStatus(Request $request, string $action, AccountsService $accounts): JsonResponse
    {
        $c = $this->conn();
        if ($action === 'activate') {
            if (! $c->base_url || ! $c->secret_ref) {
                throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Para activarla, completa la dirección y el secreto.', ['errors' => ['base_url' => ['Para activarla, completa la dirección y el secreto.']]]);
            }
            if (! $this->svc->secret($c->secret_ref)) {
                throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'El secreto no está cargado en el servidor (variable de entorno vacía).', ['errors' => ['secret_ref' => ['El secreto no está cargado en el servidor (variable de entorno vacía).']]]);
            }
        }
        $this->svc->db()->update('update crm_connections set status = ?, updated_by = ? where id = ?', [$action === 'activate' ? 'active' : 'paused', $request->user()->id, $c->id]);
        $accounts->audit($request->user()->id, "crm_connection_{$action}", 'crm_connections', $c->id, []);

        return response()->json(['data' => ['status' => $action === 'activate' ? 'active' : 'paused']]);
    }

    /** Reintentar uno o varios fallidos (misma clave de idempotencia) o descartarlos con motivo. */
    public function events(Request $request, string $action, AccountsService $accounts): JsonResponse
    {
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['uuid'], 'reason' => [$action === 'skip' ? 'required' : 'nullable', 'string', 'max:300']], self::MESSAGES + ['reason.required' => 'Escribe por qué lo descartas.']);
        $n = 0;
        foreach ($d['ids'] as $id) {
            if ($action === 'retry') {
                $n += $this->svc->db()->update("update crm_sync_events set status = 'pending', next_attempt_at = now() where id = ? and status in ('failed', 'skipped')", [$id]);
            } else {
                $n += $this->svc->db()->update("update crm_sync_events set status = 'skipped', last_error = ? where id = ? and status in ('pending', 'failed')", ['Descartado: '.($d['reason'] ?? ''), $id]);
            }
        }
        $accounts->audit($request->user()->id, "crm_events_{$action}", 'crm_sync_events', null, ['count' => $n]);
        if ($action === 'retry') {
            $this->svc->processDue();
        }

        return response()->json(['data' => ['count' => $n]]);
    }
}
