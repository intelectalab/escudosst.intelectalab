<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Reportes del sitio que pide M-WEB §11 y RN-WEB-10, todos agregados y sin identificar personas:
 *  Público (sin sesión, límite por IP):
 *    POST /public/not-found         la página 404 del sitio avisa la ruta que no existe (solo la ruta y el dominio que enlazó)
 *    POST /public/web-vitals        LCP, INP y CLS medidos en el navegador; el sitio solo lo manda con consentimiento analítico
 *  Panel (equipo de la plataforma):
 *    GET  /internal/site/reports/links              redirecciones con más uso y enlaces rotos (404 registrados)
 *    POST /internal/site/reports/links/{id}/resolve marcar un enlace roto como resuelto (Dueño)
 *    GET  /internal/site/reports/cookie-consents    consentimientos de cookies por categoría y acción (última elección por visitante)
 *    GET  /internal/site/reports/media              biblioteca de medios: sin uso, imágenes sin texto alternativo y tamaño total
 *    GET  /internal/site/reports/web-vitals         percentil 75 por página y dispositivo frente a cwv_*_target
 */
class SiteReportsController extends Controller
{
    private const METRICS = ['LCP' => 'web.cwv_lcp_target', 'INP' => 'web.cwv_inp_target', 'CLS' => 'web.cwv_cls_target'];

    /** Respaldo si el parámetro no está cargado (referencia «bueno» de web.dev; los vigentes son parámetros a confirmar). */
    private const DEFAULT_TARGETS = ['LCP' => 2500, 'INP' => 200, 'CLS' => 0.1];

    private function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    // ---------------------------------------------------------------- Público

    public function notFound(Request $request): Response
    {
        $d = $request->validate([
            'path' => ['required', 'string', 'max:300', 'regex:~^/~'],
            'referrer' => ['nullable', 'string', 'max:500'],
        ]);
        $path = mb_strtolower(strtok($d['path'], '?#') ?: '/');
        $path = strlen($path) > 1 ? rtrim($path, '/') : $path;
        if (AnalyticsController::botAgent((string) $request->userAgent()) || AnalyticsController::privatePath($path) || ! preg_match('~^/[^\s]*$~', $path)) {
            return response()->noContent();
        }
        $host = mb_strtolower((string) parse_url((string) ($d['referrer'] ?? ''), PHP_URL_HOST));
        $host = preg_match('/^[a-z0-9.-]{1,200}$/', $host) ? $host : null;
        $own = mb_strtolower($request->getHost());
        $internal = $host !== null && ($host === $own || str_ends_with($host, '.'.$own) || $host === 'localhost');
        $this->db()->insert(
            'insert into site_not_found_paths (path, hit_count, last_referrer_host, internal_referrer) values (?, 1, ?, ?)
             on conflict (path) do update set hit_count = site_not_found_paths.hit_count + 1, last_seen_at = now(),
                    last_referrer_host = coalesce(excluded.last_referrer_host, site_not_found_paths.last_referrer_host),
                    internal_referrer = site_not_found_paths.internal_referrer or excluded.internal_referrer,
                    resolved_at = null, resolved_by = null',
            [$path, $host, $internal]
        );

        return response()->noContent();
    }

    public function webVitals(Request $request): Response
    {
        $d = $request->validate([
            'path' => ['required', 'string', 'max:300', 'regex:~^/~'],
            'metrics' => ['required', 'array', 'min:1', 'max:3'],
            'metrics.*.name' => ['required', 'in:LCP,INP,CLS'],
            'metrics.*.value' => ['required', 'numeric', 'min:0', 'max:600000'],
        ]);
        $path = strtok($d['path'], '?#') ?: '/';
        if (AnalyticsController::botAgent((string) $request->userAgent()) || AnalyticsController::privatePath($path)) {
            return response()->noContent();
        }
        $device = AnalyticsController::deviceOf((string) $request->userAgent());
        $seen = [];
        foreach ($d['metrics'] as $m) {
            if (isset($seen[$m['name']])) {
                continue;
            }
            $seen[$m['name']] = true;
            $value = $m['name'] === 'CLS' ? min((float) $m['value'], 100) : min((float) $m['value'], 600000);
            $this->db()->insert('insert into site_web_vitals (path, device, metric, value) values (?, ?, ?, ?)', [mb_substr($path, 0, 300), $device, $m['name'], round($value, 4)]);
        }

        return response()->noContent();
    }

    // ---------------------------------------------------------------- Panel

    public function links(): JsonResponse
    {
        return response()->json(['data' => [
            'redirects' => $this->db()->select(
                'select id, source_path, target_url, status_code, is_active, hit_count, last_hit_at from site_redirects
                  where deleted_at is null and hit_count > 0 order by hit_count desc, last_hit_at desc nulls last limit 20'
            ),
            'broken' => $this->db()->select(
                'select n.id, n.path, n.hit_count, n.first_seen_at, n.last_seen_at, n.last_referrer_host, n.internal_referrer,
                        exists (select 1 from site_redirects r where r.source_path = n.path and r.is_active and r.deleted_at is null) as has_redirect
                   from site_not_found_paths n where n.resolved_at is null order by n.internal_referrer desc, n.hit_count desc, n.last_seen_at desc limit 100'
            ),
            'totals' => $this->db()->selectOne(
                'select count(*) filter (where resolved_at is null)::int as open, coalesce(sum(hit_count) filter (where resolved_at is null), 0)::int as hits,
                        count(*) filter (where resolved_at is null and internal_referrer)::int as internal from site_not_found_paths'
            ),
        ]]);
    }

    public function resolveLink(Request $request, string $id, AccountsService $accounts): Response
    {
        $n = $this->db()->update('update site_not_found_paths set resolved_at = now(), resolved_by = ? where id = ? and resolved_at is null', [$request->user()->id, $id]);
        if (! $n) {
            throw ApiProblem::notFound('Ese enlace roto no existe o ya estaba resuelto.');
        }
        $accounts->audit($request->user()->id, 'site_broken_link_resolved', 'site_not_found_paths', $id, []);

        return response()->noContent();
    }

    public function cookieConsents(Request $request): JsonResponse
    {
        $days = in_array((int) $request->query('days', 30), [7, 30, 90, 365], true) ? (int) $request->query('days', 30) : 30;
        // La última elección de cada visitante en el periodo (cada cambio es una fila nueva): se cuentan elecciones, no personas.
        $last = "select distinct on (visitor_id) visitor_id, action, categories, policy_version_label from cookie_consents
                  where consented_at >= now() - make_interval(days => ?) order by visitor_id, consented_at desc";
        $t = $this->db()->selectOne(
            "select count(*)::int as visitors,
                    count(*) filter (where action = 'accept_all')::int as accept_all,
                    count(*) filter (where action = 'reject_all')::int as reject_all,
                    count(*) filter (where action = 'custom')::int as custom,
                    count(*) filter (where action = 'withdraw')::int as withdraw,
                    count(*) filter (where (categories->>'preferences')::boolean)::int as preferences,
                    count(*) filter (where (categories->>'analytics')::boolean)::int as analytics,
                    count(*) filter (where (categories->>'marketing')::boolean)::int as marketing
               from ({$last}) x", [$days]
        );
        $versions = $this->db()->select("select policy_version_label as version, count(*)::int as n from ({$last}) x group by 1 order by 2 desc", [$days]);
        $changes = (int) $this->db()->selectOne('select count(*) as n from cookie_consents where consented_at >= now() - make_interval(days => ?)', [$days])->n;

        return response()->json(['data' => ['days' => $days, 'totals' => $t, 'choices' => $changes, 'versions' => $versions]]);
    }

    public function media(): JsonResponse
    {
        $rows = $this->db()->select(
            "select m.id, m.kind, m.title, m.alt_text, coalesce(m.size_bytes, a.size_bytes, 0)::bigint as size_bytes, m.folder, m.created_at
               from site_media m join site_assets a on a.id = m.file_id where m.deleted_at is null order by m.created_at desc"
        );
        $unused = [];
        $noAlt = [];
        $byKind = [];
        foreach ($rows as $r) {
            $byKind[$r->kind] ??= ['kind' => $r->kind, 'files' => 0, 'size_bytes' => 0];
            $byKind[$r->kind]['files']++;
            $byKind[$r->kind]['size_bytes'] += (int) $r->size_bytes;
            if (in_array($r->kind, ['image', 'icon'], true) && trim((string) $r->alt_text) === '') {
                $noAlt[] = ['id' => $r->id, 'kind' => $r->kind, 'title' => $r->title];
            }
            $uses = (int) (json_decode($this->db()->selectOne('select web_media_usages(?) as r', [$r->id])->r, true)['usage_count'] ?? 0);
            if ($uses === 0) {
                $unused[] = ['id' => $r->id, 'kind' => $r->kind, 'title' => $r->title, 'folder' => $r->folder, 'size_bytes' => (int) $r->size_bytes, 'created_at' => $r->created_at];
            }
        }

        return response()->json(['data' => [
            'files' => count($rows),
            'size_bytes' => array_sum(array_column($byKind, 'size_bytes')),
            'by_kind' => array_values($byKind),
            'unused' => $unused,
            'unused_size_bytes' => array_sum(array_column($unused, 'size_bytes')),
            'missing_alt' => $noAlt,
        ]]);
    }

    public function webVitalsReport(Request $request): JsonResponse
    {
        $days = in_array((int) $request->query('days', 30), [7, 30, 90], true) ? (int) $request->query('days', 30) : 30;
        $device = in_array($request->query('device'), ['desktop', 'mobile', 'tablet'], true) ? $request->query('device') : 'mobile';
        $targets = [];
        foreach (self::METRICS as $m => $key) {
            $targets[$m] = LegalParams::number($key, self::DEFAULT_TARGETS[$m]);
        }
        $rows = $this->db()->select(
            "select path, metric, count(*)::int as samples, percentile_cont(0.75) within group (order by value) as p75
               from site_web_vitals where occurred_at >= now() - make_interval(days => ?) and device = ?
              group by path, metric order by path, metric", [$days, $device]
        );
        $pages = [];
        foreach ($rows as $r) {
            $pages[$r->path] ??= ['path' => $r->path, 'metrics' => [], 'passes' => true];
            $p75 = round((float) $r->p75, $r->metric === 'CLS' ? 3 : 0);
            $ok = $p75 <= $targets[$r->metric];
            $pages[$r->path]['metrics'][$r->metric] = ['p75' => $p75, 'samples' => $r->samples, 'passes' => $ok];
            $pages[$r->path]['passes'] = $pages[$r->path]['passes'] && $ok;
        }
        $pages = array_values($pages);
        usort($pages, fn ($a, $b) => [(int) $a['passes'], -array_sum(array_column($a['metrics'], 'samples'))] <=> [(int) $b['passes'], -array_sum(array_column($b['metrics'], 'samples'))]);

        return response()->json(['data' => [
            'days' => $days, 'device' => $device, 'targets' => $targets, 'pages' => $pages,
            'failing' => count(array_filter($pages, fn ($p) => ! $p['passes'])),
        ]]);
    }
}
