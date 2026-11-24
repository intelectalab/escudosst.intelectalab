<?php

namespace App\Core\Http\Middleware;

use App\Core\Tenancy\CompanyContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * M-AUD RN-AUD-05: «las exportaciones (Excel, PDF, expediente) se auditan con filtros y número de registros». Va al final del
 * grupo «company» (dentro de la transacción de la petición) y anota en audit_logs cada descarga correcta de:
 *  - un Excel, PDF o CSV (rutas que terminan en .xlsx, .pdf o .csv);
 *  - un reporte imprimible (/reports/…, …/report, …/print, …/pdf, …/legajo), el expediente (…/zip) y las plantillas de carga (…/template).
 * Acción «export» para Excel/CSV, expediente y plantillas, «print» para los imprimibles y PDF; con la ruta, el recurso, los filtros (la
 * consulta) y el número de filas cuando se conoce (Xlsx::build lo anota; en un reporte JSON, la lista que devuelve).
 * Si el controlador ya dejó su propia entrada de exportación (ExportAudit::$logged), no se repite.
 */
class AuditExports
{
    /** Filas del último Excel armado en la petición (lo fija App\Core\Support\Xlsx::build). */
    public static ?int $rows = null;

    /** El controlador ya anotó la exportación en audit_logs. */
    public static bool $logged = false;

    public static function markLogged(): void
    {
        self::$logged = true;
    }

    public function handle(Request $request, Closure $next): Response
    {
        self::$rows = null;
        self::$logged = false;
        $response = $next($request);
        $uri = (string) ($request->route()?->uri() ?? '');
        $kind = $request->isMethod('GET') ? self::kind($uri) : null;
        if ($kind === null || self::$logged || $response->getStatusCode() >= 300 || ! app()->bound(CompanyContext::class)) {
            return $response;
        }
        $ctx = app(CompanyContext::class);
        $path = preg_replace('#^api/v1/#', '', $uri);
        $module = strtoupper((string) (explode('/', $path)[0] ?? ''));
        if ($module === 'REPORTS') {
            $module = 'REC';
        }
        $params = array_filter((array) $request->route()?->parameters(), fn ($v) => is_scalar($v));
        $rows = self::$rows ?? self::jsonRows($response);
        DB::insert(
            "insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes, ip, user_agent)
             values (?, ?, ?, 'user', ?, ?, ?, ?, ?::jsonb, ?::inet, ?)",
            [$ctx->companyId, (string) auth()->id(), $ctx->roleCode, $kind, mb_substr($module, 0, 3), self::resource($path), self::entityId($params),
                json_encode(['route' => $path, 'params' => $params ?: null, 'filters' => $request->query() ?: null, 'rows' => $rows], JSON_UNESCAPED_UNICODE),
                $request->ip(), mb_substr((string) $request->userAgent(), 0, 300)]
        );

        return $response;
    }

    /** «export» (Excel, CSV, plantilla), «print» (imprimible, PDF) o null si la ruta no es una exportación. */
    public static function kind(string $uri): ?string
    {
        if (preg_match('#\.(xlsx|csv)$#', $uri) || preg_match('#/(template|zip)$#', $uri)) {
            return 'export';
        }
        if (preg_match('#\.pdf$#', $uri) || str_starts_with($uri, 'api/v1/reports/') || preg_match('#/(report|print|pdf|legajo)$#', $uri)) {
            return 'print';
        }

        return null;
    }

    /** Recurso exportado: el último tramo de la ruta sin parámetros ni extensión (p. ej. «reports.xlsx» → «reports»). */
    private static function resource(string $path): string
    {
        $parts = array_values(array_filter(explode('/', $path), fn ($p) => $p !== '' && ! str_starts_with($p, '{')));
        $last = preg_replace('#\.(xlsx|csv|pdf)$#', '', (string) end($parts));
        $base = count($parts) > 1 && in_array($last, ['report', 'reports', 'print', 'pdf', 'export', 'template', 'legajo', 'zip'], true) ? $parts[count($parts) - 2].'/'.$last : $last;

        return mb_substr($base, 0, 60);
    }

    private static function entityId(array $params): ?string
    {
        foreach ($params as $v) {
            if (is_string($v) && \Illuminate\Support\Str::isUuid($v)) {
                return $v;
            }
        }

        return null;
    }

    /** En un reporte JSON, el número de elementos de la lista que devuelve (data o data.rows/items), si la hay. */
    private static function jsonRows(Response $response): ?int
    {
        if (! $response instanceof JsonResponse) {
            return null;
        }
        $d = $response->getData(true)['data'] ?? null;
        if (is_array($d) && array_is_list($d)) {
            return count($d);
        }
        foreach (['rows', 'items'] as $k) {
            if (is_array($d[$k] ?? null) && array_is_list($d[$k])) {
                return count($d[$k]);
            }
        }

        return null;
    }
}
