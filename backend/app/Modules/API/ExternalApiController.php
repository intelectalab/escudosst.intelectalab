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
 *  recursos: workers, events, actions, inspections, trainings
 * Cabeceras: X-Company-Id (ID de la empresa), X-Api-Key (ck_…) y X-Api-Secret (sk_…), que se crean en
 * «API e integraciones › Clientes». El cliente debe estar activo, sin vencer, con el alcance «read» y, si tiene IPs
 * permitidas, llamar desde una de ellas. Nunca se devuelven datos de salud ni documentos completos.
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
    ];

    public function index(Request $request, string $resource): JsonResponse
    {
        [$sql, $col] = self::RESOURCES[$resource] ?? throw ApiProblem::notFound('Recurso desconocido. Disponibles: '.implode(', ', array_keys(self::RESOURCES)).'.');

        return $this->authenticated($request, function () use ($request, $sql, $col) {
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

    private function authenticated(Request $request, callable $fn): JsonResponse
    {
        $company = (string) $request->header('X-Company-Id');
        $key = (string) $request->header('X-Api-Key');
        $secret = (string) $request->header('X-Api-Secret');
        if (! Str::isUuid($company) || $key === '' || $secret === '') {
            throw new ApiProblem(401, 'unauthenticated', 'Faltan credenciales', 'Envía X-Company-Id, X-Api-Key y X-Api-Secret.');
        }
        if (RateLimiter::tooManyAttempts('ext-api:'.$key, 120)) {
            throw new ApiProblem(429, 'too_many_requests', 'Demasiadas consultas', 'Espera un minuto antes de seguir consultando.');
        }
        RateLimiter::hit('ext-api:'.$key, 60);

        return DB::transaction(function () use ($company, $key, $secret, $request, $fn) {
            DbContext::setCompany($company);
            $c = DB::selectOne('select * from api_clients where client_key = ? and deleted_at is null', [$key]);
            if (! $c || ! hash_equals((string) $c->secret_hash, hash('sha256', $secret))) {
                throw new ApiProblem(401, 'unauthenticated', 'Credenciales no válidas', 'La clave o el secreto no son correctos para esta empresa.');
            }
            if ($c->status !== 'active' || ($c->expires_at && strtotime($c->expires_at) < time())) {
                throw new ApiProblem(401, 'unauthenticated', 'Cliente inactivo', 'El cliente de la API está revocado o vencido.');
            }
            $scopes = array_filter(explode(',', trim((string) $c->scopes, '{}')));
            if (! in_array('read', $scopes, true) && ! in_array('*', $scopes, true)) {
                throw new ApiProblem(403, 'forbidden', 'Sin permiso', 'El cliente no tiene el alcance «read».');
            }
            $ips = array_filter(explode(',', trim((string) $c->allowed_ips, '{}')));
            if ($ips && ! in_array($request->ip(), $ips, true)) {
                throw new ApiProblem(403, 'forbidden', 'IP no permitida', 'Esta IP no está en la lista de IPs permitidas del cliente.');
            }
            DB::update('update api_clients set last_used_at = now() where id = ?', [$c->id]);
            $module = DB::selectOne("select mode from feature_flags where module_code = 'API' and deleted_at is null")?->mode;
            if ($module !== 'enabled') {
                throw new ApiProblem(403, 'module_not_in_plan', 'API no activa', 'El complemento API no está activo para esta empresa.');
            }

            return $fn();
        });
    }
}
