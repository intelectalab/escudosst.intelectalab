<?php

namespace App\Core\Http\Middleware;

use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key en los POST de la API (docs/08-api.md §8). Si la petición trae la cabecera:
 *  - primera vez: se ejecuta y, si termina bien (2xx), se guarda la respuesta;
 *  - misma clave, misma sesión, empresa y ruta, mismo cuerpo: se devuelve la respuesta original sin volver a ejecutar
 *    (cabecera Idempotent-Replay: true; código interno idempotency_replay, docs/02 §11);
 *  - misma clave con otro cuerpo: 409 conflict;
 *  - misma clave mientras la primera sigue en curso: 409 conflict (se puede reintentar en unos segundos).
 * Sin la cabecera la petición sigue como siempre (la clave es opcional salvo donde el módulo la exige).
 * El alcance es la sesión (token) o, sin sesión, la IP en hash: nadie puede leer la respuesta de otra persona con su clave.
 * Las rutas que ya resuelven su propia idempotencia (API externa, sincronización offline, reserva de demo) no pasan por aquí.
 */
class Idempotency
{
    /** Rutas con idempotencia propia (en su controlador o servicio). */
    private const OWN = ['api/v1/ext/*', 'api/v1/sync/*', 'api/v1/public/demo/book'];

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if (! $request->isMethod('POST') || $key === null || $request->is(...self::OWN)) {
            return $next($request);
        }
        $key = trim((string) $key);
        if ($key === '' || mb_strlen($key) > 100 || ! preg_match('/^[\x21-\x7E]+$/', $key)) {
            return ApiProblem::badRequest('Idempotency-Key admite hasta 100 caracteres visibles (se recomienda un UUID).')->toResponse();
        }

        $db = DB::connection('platform');
        $company = (string) $request->header('X-Company-Id', '');
        $route = 'POST /'.ltrim($request->path(), '/');
        $who = $request->bearerToken() ? 't:'.hash('sha256', (string) $request->bearerToken())
            : ($request->hasSession() && $request->session()->getId() ? 's:'.hash('sha256', $request->session()->getId()) : 'ip:'.hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')));
        $scope = hash('sha256', implode('|', [$key, $who, $company, $route]));
        $body = hash('sha256', $request->getContent().'|'.json_encode(array_map(fn ($f) => is_array($f) ? count($f) : [$f->getClientOriginalName(), $f->getSize(), hash_file('sha256', $f->getRealPath())], $request->allFiles())));
        $hours = max(1, (int) LegalParams::number('idempotency_key_hours', 24));

        $db->delete('delete from idempotency_keys where scope_hash = ? and expires_at < now()', [$scope]);
        $inserted = $db->affectingStatement(
            'insert into idempotency_keys (scope_hash, idempotency_key, company_id, method, route, request_hash, expires_at)
             values (?, ?, ?, ?, ?, ?, now() + make_interval(hours => ?)) on conflict (scope_hash) do nothing',
            [$scope, $key, preg_match('/^[0-9a-f-]{36}$/i', $company) ? $company : null, 'POST', mb_substr($route, 0, 300), $body, $hours]
        );
        if (! $inserted) {
            $prev = $db->selectOne('select request_hash, response_status, response_body, response_content_type from idempotency_keys where scope_hash = ?', [$scope]);
            if ($prev && $prev->request_hash !== $body) {
                return (new ApiProblem(409, 'conflict', 'Clave repetida', 'Esa Idempotency-Key ya se usó con otros datos. Usa una clave nueva para otra petición.'))->toResponse();
            }
            if ($prev && $prev->response_status !== null) {
                return response((string) $prev->response_body, (int) $prev->response_status, array_filter([
                    'Content-Type' => $prev->response_content_type ?: 'application/json',
                    'Idempotent-Replay' => 'true',
                ]));
            }

            return (new ApiProblem(409, 'conflict', 'Petición en curso', 'Esta misma petición todavía se está procesando. Vuelve a intentarlo en unos segundos.'))->toResponse();
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $db->delete('delete from idempotency_keys where scope_hash = ?', [$scope]);
            throw $e;
        }

        $status = $response->getStatusCode();
        $content = $response instanceof \Symfony\Component\HttpFoundation\StreamedResponse || $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
            ? null : $response->getContent();
        if ($status >= 200 && $status < 300 && is_string($content) && strlen($content) <= 1_000_000) {
            $db->update(
                'update idempotency_keys set response_status = ?, response_body = ?, response_content_type = ?, user_id = ?, completed_at = now() where scope_hash = ?',
                [$status, $content, mb_substr((string) $response->headers->get('Content-Type'), 0, 120), $request->user()?->getAuthIdentifier(), $scope]
            );
        } else {
            // Un error (o una descarga) no se guarda: la misma clave puede reintentarse.
            $db->delete('delete from idempotency_keys where scope_hash = ?', [$scope]);
        }

        return $response;
    }
}
