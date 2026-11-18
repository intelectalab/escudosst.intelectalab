<?php

namespace App\Modules\API;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\DbContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * API para integraciones de la empresa (complemento API), de solo lectura:
 *  GET /ext/v1/{resource}?updated_since=AAAA-MM-DDTHH:MM:SS&page=1
 *  recursos: workers, events, actions, inspections, trainings, credentials (fotochecks), access_events (ingresos en garita)
 * Cabeceras: X-Company-Id (ID de la empresa), X-Api-Key (ck_…) y X-Api-Secret (sk_…), que se crean en
 * «API e integraciones › Clientes». El cliente debe estar activo, sin vencer, con permiso para el recurso
 * («workers.read», «credentials.read»…; «read» o «*» dan lectura de todos) y, si tiene IPs permitidas, llamar desde una
 * de ellas. Nunca se devuelven datos de salud (RN-API-01) ni documentos completos. Cada consulta suma al uso del cliente
 * por día y recurso (§11).
 */
class ExternalApiController extends Controller
{
    private const RESOURCES = [
        'workers' => ["select id, full_name, document_last4, status, current_site_id as site_id, current_area_id as area_id, current_position_id as position_id, updated_at
                         from workers where deleted_at is null", 'updated_at'],
        'events' => ["select id, code, event_type, occurred_at, site_id, area_id, status, lost_days, injured_count, notification_deadline_at, updated_at
                        from sst_events where deleted_at is null", 'updated_at'],
        'actions' => ["select id, code, title, source_type, priority, status, due_on, effectiveness, completed_at, verified_at, updated_at
                         from corrective_actions where deleted_at is null", 'updated_at'],
        'inspections' => ["select id, code, title, inspection_type, site_id, scheduled_on, completed_at, score_pct, status, updated_at
                             from inspections where deleted_at is null", 'updated_at'],
        'trainings' => ["select id, code, title, training_type, modality, duration_minutes, status, updated_at from trainings where deleted_at is null", 'updated_at'],
        // M-FOT: estado del fotocheck (sin el token del QR).
        'credentials' => ["select id, credential_number, credential_type_id, worker_id, contractor_worker_id, valid_from, expires_on, status, blocking_reasons, updated_at
                             from credentials where deleted_at is null", 'updated_at'],
        // M-ING: ingresos y salidas registrados en garita.
        'access_events' => ["select id, access_point_id, occurred_at, direction, subject_type, worker_id, contractor_worker_id, credential_id, result, deny_codes, is_offline
                               from access_events where true", 'occurred_at'],
    ];

    /** RN-API-01: recursos que nunca salen por la API (datos de salud). */
    private const MEDICAL = ['fitness_status', 'medical_exams', 'medical_exam_orders', 'emo', 'fitness'];

    public function index(Request $request, string $resource): JsonResponse
    {
        if (in_array($resource, self::MEDICAL, true)) {
            throw ApiProblem::forbidden('Los datos de salud no se entregan por la API.');
        }
        [$sql, $col] = self::RESOURCES[$resource] ?? throw ApiProblem::notFound('Recurso desconocido. Disponibles: '.implode(', ', array_keys(self::RESOURCES)).'.');

        return $this->authenticated($request, $resource, function () use ($request, $sql, $col) {
            $bind = [];
            if ($since = $request->query('updated_since')) {
                if (! strtotime((string) $since)) {
                    throw ApiProblem::badRequest('updated_since debe ser una fecha y hora (ISO 8601).');
                }
                $sql .= " and {$col} >= ?";
                $bind[] = date(DATE_ATOM, strtotime((string) $since));
            }
            $page = max(1, (int) $request->query('page', 1));
            $rows = DB::select($sql." order by {$col}, id limit 101 offset ".(($page - 1) * 100), $bind);

            return response()->json(['data' => array_slice($rows, 0, 100), 'meta' => ['page' => $page, 'has_more' => count($rows) > 100]]);
        });
    }

    /**
     * Credenciales: «Authorization: Bearer at_…» (token OAuth2 de POST /ext/oauth/token) o X-Api-Key + X-Api-Secret; siempre con
     * X-Company-Id. $action read|write: leer pide «recurso.read», «read» o «*»; escribir pide «recurso.write», «write» o «*».
     */
    private function authenticated(Request $request, string $resource, callable $fn, string $action = 'read'): JsonResponse
    {
        $company = (string) $request->header('X-Company-Id');
        $bearer = (string) $request->bearerToken();
        $key = (string) $request->header('X-Api-Key');
        $secret = (string) $request->header('X-Api-Secret');
        $useToken = str_starts_with($bearer, 'at_');
        if (! Str::isUuid($company) || (! $useToken && ($key === '' || $secret === ''))) {
            throw new ApiProblem(401, 'unauthenticated', 'Faltan credenciales', 'Envía X-Company-Id y un token Bearer (POST /ext/oauth/token) o X-Api-Key y X-Api-Secret.');
        }
        $limiterKey = 'ext-api-any:'.($useToken ? substr(hash('sha256', $bearer), 0, 24) : $key);
        // Tope general por credencial antes de tocar la base (protege contra claves inventadas).
        if (RateLimiter::tooManyAttempts($limiterKey, 600)) {
            throw new ApiProblem(429, 'too_many_requests', 'Demasiadas consultas', 'Espera un minuto antes de seguir consultando.');
        }
        RateLimiter::hit($limiterKey, 60);

        $out = DB::transaction(function () use ($company, $key, $secret, $bearer, $useToken, $request, $fn, $resource, $action) {
            DbContext::setCompany($company);
            if ($useToken) {
                $t = DB::selectOne('select api_client_id from api_access_tokens where token_hash = ? and revoked_at is null and expires_at > now()', [hash('sha256', $bearer)]);
                $c = $t ? DB::selectOne('select * from api_clients where id = ? and deleted_at is null', [$t->api_client_id]) : null;
                if (! $c) {
                    throw new ApiProblem(401, 'unauthenticated', 'Token no válido', 'El token no existe, venció o es de otra empresa: pide uno nuevo.');
                }
            } else {
                $c = DB::selectOne('select * from api_clients where client_key = ? and deleted_at is null', [$key]);
                if (! $c || ! hash_equals((string) $c->secret_hash, hash('sha256', $secret))) {
                    throw new ApiProblem(401, 'unauthenticated', 'Credenciales no válidas', 'La clave o el secreto no son correctos para esta empresa.');
                }
            }
            if ($c->status !== 'active' || ($c->expires_at && strtotime($c->expires_at) < time())) {
                throw new ApiProblem(401, 'unauthenticated', 'Cliente inactivo', 'El cliente de la API está revocado o vencido.');
            }
            $scopes = self::scopesOf($c);
            $need = $action === 'write' ? ['write', '*', $resource.'.write'] : ['read', '*', $resource.'.read'];
            if (! array_intersect($need, $scopes)) {
                // Se registra el intento rechazado (se confirma la transacción) y luego se responde 403.
                $this->usage($c, $resource, true);

                return null;
            }
            $ips = array_filter(explode(',', trim((string) $c->allowed_ips, '{}')));
            if ($ips && ! in_array($request->ip(), $ips, true)) {
                throw new ApiProblem(403, 'forbidden', 'IP no permitida', 'Esta IP no está en la lista de IPs permitidas del cliente.');
            }
            // RN-API-05: límite de consultas por minuto de cada cliente (rate_limit_per_minute; 120 si no se indica).
            $limit = max(1, (int) ($c->rate_limit_per_minute ?: 120));
            if (RateLimiter::tooManyAttempts('ext-api:'.$c->id, $limit)) {
                throw new ApiProblem(429, 'too_many_requests', 'Demasiadas consultas', "Este cliente admite {$limit} consultas por minuto: espera un momento.",
                    ['retry_after' => RateLimiter::availableIn('ext-api:'.$c->id)]);
            }
            RateLimiter::hit('ext-api:'.$c->id, 60);
            DB::update('update api_clients set last_used_at = now() where id = ?', [$c->id]);
            $this->usage($c, $resource, false);
            $module = DB::selectOne("select mode from feature_flags where module_code = 'API' and deleted_at is null")?->mode;
            if ($module !== 'enabled') {
                throw new ApiProblem(403, 'module_not_in_plan', 'API no activa', 'El complemento API no está activo para esta empresa.');
            }

            return $fn($c);
        });

        $verb = $action === 'write' ? 'escribir en' : 'leer';

        return $out ?? throw new ApiProblem(403, 'forbidden', 'Sin permiso', "El cliente no tiene permiso para {$verb} «{$resource}» (alcance {$resource}.{$action}).");
    }

    private static function scopesOf(object $c): array
    {
        return array_map('trim', array_filter(explode(',', trim((string) $c->scopes, '{}'))));
    }

    /**
     * POST /ext/oauth/token — OAuth2 «client credentials» (M-API flujo 2): grant_type=client_credentials, client_id (la clave
     * esc_…) y client_secret, con X-Company-Id. Devuelve {access_token, token_type: Bearer, expires_in, scope}.
     */
    public function token(Request $request): JsonResponse
    {
        $company = (string) $request->header('X-Company-Id');
        $id = (string) ($request->input('client_id') ?? $request->getUser() ?? '');
        $secret = (string) ($request->input('client_secret') ?? $request->getPassword() ?? '');
        if ($request->input('grant_type') !== 'client_credentials') {
            return response()->json(['error' => 'unsupported_grant_type', 'error_description' => 'Solo se admite grant_type=client_credentials.'], 400);
        }
        if (! Str::isUuid($company) || $id === '' || $secret === '') {
            return response()->json(['error' => 'invalid_request', 'error_description' => 'Envía X-Company-Id, client_id y client_secret.'], 400);
        }
        if (RateLimiter::tooManyAttempts('ext-token:'.$id, 30)) {
            return response()->json(['error' => 'slow_down', 'error_description' => 'Demasiados intentos: espera un minuto.'], 429);
        }
        RateLimiter::hit('ext-token:'.$id, 60);

        return DB::transaction(function () use ($company, $id, $secret) {
            DbContext::setCompany($company);
            $c = DB::selectOne('select * from api_clients where client_key = ? and deleted_at is null', [$id]);
            if (! $c || ! hash_equals((string) $c->secret_hash, hash('sha256', $secret)) || $c->status !== 'active' || ($c->expires_at && strtotime($c->expires_at) < time())) {
                return response()->json(['error' => 'invalid_client', 'error_description' => 'Cliente, secreto o empresa no válidos, o cliente revocado.'], 401);
            }
            $ttl = max(5, (int) config('escudo.api_token_minutes', 60));
            $token = 'at_'.Str::random(48);
            $scopes = self::scopesOf($c);
            DB::insert('insert into api_access_tokens (company_id, api_client_id, token_hash, scopes, expires_at) values (?, ?, ?, ?::text[], now() + make_interval(mins => ?::int))',
                [$c->company_id, $c->id, hash('sha256', $token), '{'.implode(',', $scopes).'}', $ttl]);

            return response()->json(['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $ttl * 60, 'scope' => implode(' ', $scopes)]);
        });
    }

    /** Campos que un sistema externo (planillas, ERP) puede escribir en un trabajador. Nunca datos de salud. */
    private const WORKER_FIELDS = ['document_type', 'document_number', 'first_name', 'paternal_last_name', 'maternal_last_name', 'birth_on', 'sex', 'nationality',
        'phone_e164', 'personal_email', 'employee_code', 'hire_on', 'termination_on', 'termination_reason', 'employment_type', 'contract_type',
        'current_site_id', 'current_area_id', 'current_position_id', 'status'];

    /** POST /ext/v1/workers — alta de un trabajador (alcance workers.write); acepta Idempotency-Key (RN-API-06). */
    public function storeWorker(Request $request): JsonResponse
    {
        return $this->authenticated($request, 'workers', fn (object $c) => $this->idempotent($request, $c, function () use ($request, $c) {
            $row = $this->asClient($c, fn () => app(\App\Modules\REC\RecordsService::class)->create(\App\Modules\REC\Registry::resource('TRB', 'workers'),
                array_intersect_key($request->all(), array_flip(self::WORKER_FIELDS))));

            return [201, ['data' => ['id' => $row['id'], 'full_name' => $row['full_name'] ?? null, 'status' => $row['status'] ?? null]]];
        }), 'write');
    }

    /** PATCH /ext/v1/workers/{id} — cambio de datos laborales (cargo, área, sede, cese…), con las mismas reglas que la ficha. */
    public function updateWorker(Request $request, string $id): JsonResponse
    {
        return $this->authenticated($request, 'workers', fn (object $c) => $this->idempotent($request, $c, function () use ($request, $c, $id) {
            if (! Str::isUuid($id)) {
                throw ApiProblem::notFound('El trabajador no existe.');
            }
            $row = $this->asClient($c, fn () => app(\App\Modules\REC\RecordsService::class)->update(\App\Modules\REC\Registry::resource('TRB', 'workers'), $id,
                array_intersect_key($request->all(), array_flip(array_diff(self::WORKER_FIELDS, ['document_type', 'document_number'])))));

            return [200, ['data' => ['id' => $row['id'], 'full_name' => $row['full_name'] ?? null, 'status' => $row['status'] ?? null]]];
        }), 'write');
    }

    /**
     * RN-API-06: misma Idempotency-Key y mismo cuerpo → la respuesta original sin volver a escribir; misma llave con otro
     * cuerpo → 409. Sin llave, la escritura se hace normal.
     */
    private function idempotent(Request $request, object $c, callable $write): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key !== '' && mb_strlen($key) > 100) {
            throw ApiProblem::badRequest('Idempotency-Key admite hasta 100 caracteres.');
        }
        $hash = hash('sha256', $request->method().' '.$request->path().' '.json_encode($request->all()));
        if ($key !== '') {
            $prev = DB::selectOne('select request_hash, response_status, response_body from api_idempotency_keys where api_client_id = ? and idempotency_key = ?', [$c->id, $key]);
            if ($prev) {
                if ($prev->request_hash !== $hash) {
                    throw new ApiProblem(409, 'conflict', 'Llave repetida', 'Esa Idempotency-Key ya se usó con otros datos.');
                }

                return response()->json(json_decode($prev->response_body, true), (int) $prev->response_status)->header('Idempotent-Replay', 'true');
            }
        }
        [$status, $body] = $write();
        if ($key !== '') {
            DB::insert('insert into api_idempotency_keys (company_id, api_client_id, idempotency_key, request_hash, response_status, response_body) values (?, ?, ?, ?, ?, ?::jsonb)',
                [$c->company_id, $c->id, $key, $hash, $status, json_encode($body, JSON_UNESCAPED_UNICODE)]);
        }

        return response()->json($body, $status);
    }

    /**
     * La escritura corre con las mismas reglas que la ficha, a nombre de la persona que creó el cliente de la API (queda en la
     * bitácora con el cliente que la hizo).
     */
    private function asClient(object $c, callable $fn): array
    {
        $user = $c->created_by ? \App\Models\User::find($c->created_by) : null;
        $m = $user ? DB::selectOne("select m.id, coalesce(r.base_role_code, r.code) as role from memberships m join roles r on r.id = m.role_id
                                     where m.user_id = ? and m.company_id = ? and m.status = 'active' and m.deleted_at is null limit 1", [$user->id, $c->company_id]) : null;
        if (! $user || ! $m) {
            throw ApiProblem::forbidden('La persona que creó este cliente de la API ya no está activa en la empresa: crea un cliente nuevo.');
        }
        $co = DB::selectOne('select legal_name, ruc from companies where id = ?', [$c->company_id]);
        auth()->setUser($user);
        app()->instance(\App\Core\Tenancy\CompanyContext::class, new \App\Core\Tenancy\CompanyContext($c->company_id, $m->id, $m->role, $co->legal_name, trim((string) $co->ruc)));
        return $fn();
    }

    private function usage(object $c, string $resource, bool $denied): void
    {
        DB::insert("insert into api_usage (company_id, api_client_id, day, resource, calls, denied) values (?, ?, (now() at time zone 'America/Lima')::date, ?, ?, ?)
                    on conflict (api_client_id, day, resource) do update set calls = api_usage.calls + excluded.calls, denied = api_usage.denied + excluded.denied, updated_at = now()",
            [$c->company_id, $c->id, $resource, $denied ? 0 : 1, $denied ? 1 : 0]);
    }
}
