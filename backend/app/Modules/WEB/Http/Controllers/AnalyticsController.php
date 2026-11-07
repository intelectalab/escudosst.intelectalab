<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Métricas propias del sitio (SCR-WEB-19 resumen y SCR-WEB-50 detalle; RN-WEB-65 a 67).
 *  Público: POST /public/events     un evento; el sitio solo lo manda si la persona aceptó cookies analíticas.
 *  Panel:   GET  /internal/site/analytics?days=30&device=   resumen agregado
 *           GET  /internal/site/analytics/export?days=30     CSV (solo Dueño, queda en auditoría)
 *
 * No se guarda la IP, el navegador completo ni una huella: la «sesión» es un hash con una sal que cambia cada día y
 * se descarta, así no se puede seguir a nadie de un día a otro. Fuente y dispositivo se calculan aquí.
 */
class AnalyticsController extends Controller
{
    public const TYPES = ['page_view', 'cta_click', 'form_view', 'form_submit', 'whatsapp_click', 'phone_click', 'demo_booked', 'calculator_used',
        'assessment_completed', 'resource_download', 'chatbot_open', 'chatbot_lead', 'popup_view', 'popup_click', 'popup_close',
        'webinar_registered', 'savings_calculated', 'review_click', 'campaign_view'];

    /** Eventos que cuentan como conversión (alguien dejó datos o pidió algo). */
    public const CONVERSIONS = ['form_submit', 'demo_booked', 'calculator_used', 'assessment_completed', 'resource_download', 'chatbot_lead', 'webinar_registered', 'savings_calculated'];

    private const SEARCH = ['google.', 'bing.', 'yahoo.', 'duckduckgo.', 'ecosia.', 'yandex.', 'baidu.'];

    private const SOCIAL = ['facebook.', 'fb.', 'instagram.', 'linkedin.', 'lnkd.in', 't.co', 'twitter.', 'x.com', 'tiktok.', 'youtube.', 'youtu.be', 'whatsapp.', 'pinterest.'];

    private function db()
    {
        return DB::connection('platform');
    }

    // ---------------------------------------------------------------- Público

    public function store(Request $request): Response
    {
        $d = $request->validate([
            'type' => ['required', 'in:'.implode(',', self::TYPES)],
            'path' => ['nullable', 'string', 'max:300', 'regex:~^/~'],
            'key' => ['nullable', 'string', 'max:80'],
            'referrer' => ['nullable', 'string', 'max:500'],
            'utm_source' => ['nullable', 'string', 'max:100'],
            'utm_medium' => ['nullable', 'string', 'max:100'],
            'utm_campaign' => ['nullable', 'string', 'max:100'],
        ]);
        if ($this->isBot((string) $request->userAgent())) {
            return response()->noContent();
        }
        $path = isset($d['path']) ? (strtok($d['path'], '?#') ?: '/') : null;
        if ($path && $this->isPrivate($path)) {
            return response()->noContent();   // panel, aplicación y enlaces personales no se miden
        }
        $this->db()->insert(
            'insert into site_events (event_type, path, element_key, utm_source, utm_medium, utm_campaign, source, device, session_hash) values (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $d['type'], $path, isset($d['key']) ? Str::limit(preg_replace('/[^\w\-.:\/]/u', '', $d['key']), 80, '') : null,
                $d['utm_source'] ?? null, $d['utm_medium'] ?? null, $d['utm_campaign'] ?? null,
                $this->source($d, (string) $request->getHost()), $this->device((string) $request->userAgent()), $this->session($request),
            ]
        );

        return response()->noContent();
    }

    private function session(Request $request): string
    {
        $day = now('America/Lima')->toDateString();
        $salt = Cache::remember("analytics-salt:{$day}", now()->addHours(26), fn () => bin2hex(random_bytes(32)));

        return hash('sha256', $salt.'|'.$request->ip().'|'.$request->userAgent());
    }

    private function source(array $d, string $ownHost): string
    {
        $medium = mb_strtolower((string) ($d['utm_medium'] ?? ''));
        if ($medium !== '' && in_array($medium, ['email', 'correo', 'newsletter', 'boletin'], true)) {
            return 'email';
        }
        if (filled($d['utm_source'] ?? null) || filled($d['utm_campaign'] ?? null)) {
            return in_array($medium, ['social', 'social-media', 'redes'], true) ? 'social' : 'campaign';
        }
        $host = mb_strtolower((string) parse_url((string) ($d['referrer'] ?? ''), PHP_URL_HOST));
        if ($host === '' || $host === $ownHost || str_ends_with($host, '.'.$ownHost) || $host === 'localhost') {
            return 'direct';
        }
        foreach (self::SEARCH as $s) {
            if (str_contains($host, $s)) {
                return 'organic';
            }
        }
        foreach (self::SOCIAL as $s) {
            if (str_contains($host, $s) || $host === rtrim($s, '.')) {
                return 'social';
            }
        }
        if (str_contains($host, 'mail.')) {
            return 'email';
        }

        return 'referral';
    }

    private function device(string $ua): string
    {
        $ua = mb_strtolower($ua);
        if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet') || (str_contains($ua, 'android') && ! str_contains($ua, 'mobile'))) {
            return 'tablet';
        }

        return preg_match('/mobile|iphone|ipod|android|blackberry|opera mini|iemobile/', $ua) ? 'mobile' : 'desktop';
    }

    private function isBot(string $ua): bool
    {
        return $ua === '' || (bool) preg_match('/bot|crawl|spider|slurp|preview|headless|lighthouse|pingdom|uptime/i', $ua);
    }

    private function isPrivate(string $path): bool
    {
        foreach (['/plataforma', '/inicio', '/m/', '/cuenta', '/soporte', '/select-company', '/login', '/invitacion', '/recuperar', '/restablecer',
            '/confirmar-correo', '/casos/compartir/', '/casos/retirar/', '/boletin/confirmar/', '/boletin/baja/', '/demo/gestionar/',
            '/webinars/gestionar/', '/cotizacion/'] as $p) {
            if (str_starts_with($path, $p)) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------- Panel

    public function summary(Request $request): JsonResponse
    {
        [$from, $to, $device] = $this->range($request);
        $db = $this->db();
        $db->selectOne('select web_rollup_site_analytics(?::date)', [now('America/Lima')->toDateString()]);   // hoy, al momento
        $dev = $device ? ' and device = ?' : '';
        $args = fn (array $a) => $device ? [...$a, $device] : $a;

        $evFrom = $from.' 00:00:00 America/Lima';
        $evTo = date('Y-m-d', strtotime($to.' +1 day')).' 00:00:00 America/Lima';
        // Visitantes: personas distintas por día (la sesión cambia cada día). Sumar el resumen por página contaría
        // dos veces a quien vio dos páginas.
        $vis = collect($db->select(
            "select (occurred_at at time zone 'America/Lima')::date as day, count(distinct session_hash)::int as n from site_events
              where event_type = 'page_view' and occurred_at >= ?::timestamptz and occurred_at < ?::timestamptz{$dev} group by 1", $args([$evFrom, $evTo])
        ))->mapWithKeys(fn ($r) => [(string) $r->day => $r->n]);
        $days = $db->select("select day, sum(visits)::int as visits, sum(unique_visitors)::int as visitors from site_analytics_daily where day between ? and ?{$dev} group by day order by day", $args([$from, $to]));
        foreach ($days as $x) {
            $x->visitors = $vis[(string) $x->day] ?? min($x->visitors, $x->visits);
        }
        $tot = $db->selectOne("select coalesce(sum(visits), 0)::int as visits, coalesce(sum(unique_visitors), 0)::int as visitors, coalesce(sum(bounces), 0)::int as bounces from site_analytics_daily where day between ? and ?{$dev}", $args([$from, $to]));
        $tot->visitors = (int) collect($days)->sum('visitors');
        $sources = $db->select("select source, sum(visits)::int as visits from site_analytics_daily where day between ? and ?{$dev} group by source order by 2 desc", $args([$from, $to]));
        $devices = $db->select('select device, sum(visits)::int as visits from site_analytics_daily where day between ? and ? group by device order by 2 desc', [$from, $to]);
        $pages = $db->select("select path, sum(visits)::int as visits, sum(unique_visitors)::int as visitors, sum(bounces)::int as bounces from site_analytics_daily where day between ? and ?{$dev} group by path order by 2 desc limit 15", $args([$from, $to]));

        $conv = $db->select(
            "select event_type, coalesce(element_key, '') as element_key, count(*)::int as n from site_events
              where occurred_at >= ?::timestamptz and occurred_at < ?::timestamptz and event_type <> 'page_view'{$dev}
              group by 1, 2 order by 3 desc limit 40", $args([$evFrom, $evTo])
        );
        $campaigns = $db->select(
            "select utm_campaign as campaign, max(utm_source) as source, count(distinct session_hash) filter (where event_type in ('page_view', 'campaign_view'))::int as visitors,
                    count(*) filter (where event_type in ('".implode("','", self::CONVERSIONS)."'))::int as conversions
               from site_events where occurred_at >= ?::timestamptz and occurred_at < ?::timestamptz and utm_campaign is not null{$dev}
              group by utm_campaign order by visitors desc limit 20", $args([$evFrom, $evTo])
        );
        $funnel = $db->selectOne(
            "select count(distinct session_hash) filter (where event_type = 'page_view')::int as visit,
                    count(distinct session_hash) filter (where event_type in ('cta_click', 'whatsapp_click', 'phone_click', 'chatbot_open'))::int as cta,
                    count(distinct session_hash) filter (where event_type = 'form_view')::int as form,
                    count(distinct session_hash) filter (where event_type in ('".implode("','", self::CONVERSIONS)."'))::int as lead
               from site_events where occurred_at >= ?::timestamptz and occurred_at < ?::timestamptz{$dev}", $args([$evFrom, $evTo])
        );
        $leads = (int) $db->selectOne("select count(*) as n from marketing_leads where deleted_at is null and created_at >= date_trunc('month', now() at time zone 'America/Lima') at time zone 'America/Lima'")->n;
        $demos = (int) $db->selectOne("select count(*) as n from demo_requests where status = 'new' and deleted_at is null")->n;
        $s = $db->selectOne('select analytics_ids from site_settings where singleton');

        return response()->json(['data' => [
            'from' => $from, 'to' => $to, 'totals' => $tot, 'days' => $days, 'sources' => $sources, 'devices' => $devices, 'pages' => $pages,
            'conversions' => $conv, 'campaigns' => $campaigns, 'funnel' => $funnel, 'leads_month' => $leads, 'demos_pending' => $demos,
            'conversion_rate' => $funnel->visit ? round($funnel->lead * 100 / $funnel->visit, 1) : 0,
            'ids' => json_decode($s->analytics_ids ?? '{}', true) ?: new \stdClass,
        ]]);
    }

    public function export(Request $request, AccountsService $accounts): StreamedResponse
    {
        [$from, $to] = $this->range($request);
        $rows = $this->db()->select(
            'select day, path, source, device, visits, unique_visitors, bounces from site_analytics_daily where day between ? and ? order by day, path, source, device', [$from, $to]
        );
        $accounts->audit($request->user()->id, 'site_analytics_exported', 'site_analytics_daily', null, ['from' => $from, 'to' => $to, 'rows' => count($rows)]);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // Excel reconoce las tildes
            fputcsv($out, ['Día', 'Página', 'Fuente', 'Dispositivo', 'Visitas', 'Visitantes', 'Rebotes'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [$r->day, $r->path, $r->source, $r->device, $r->visits, $r->unique_visitors, $r->bounces], ';');
            }
            fclose($out);
        }, "metricas-sitio-{$from}-a-{$to}.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    private function range(Request $request): array
    {
        $days = (int) $request->query('days', 30);
        $days = in_array($days, [7, 30, 90, 365], true) ? $days : 30;
        $to = now('America/Lima')->toDateString();
        $from = now('America/Lima')->subDays($days - 1)->toDateString();
        $device = in_array($request->query('device'), ['desktop', 'mobile', 'tablet'], true) ? $request->query('device') : null;

        return [$from, $to, $device];
    }

    /**
     * Tarea diaria: resume el día anterior en site_analytics_daily. Los eventos sueltos son inalterables (no se borran
     * fila a fila): su conservación se maneja por particiones mensuales con el rol dueño de la base.
     */
    public static function dailyMaintenance(): string
    {
        $yesterday = now('America/Lima')->subDay()->toDateString();
        $n = DB::connection('platform')->selectOne('select web_rollup_site_analytics(?::date) as n', [$yesterday])->n;

        return "Resumen {$yesterday}: {$n} filas";
    }
}
