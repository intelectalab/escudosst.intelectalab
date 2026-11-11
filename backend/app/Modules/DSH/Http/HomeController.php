<?php

namespace App\Modules\DSH\Http;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\DSH\Services\KpiService;
use App\Modules\EST\SummaryController;
use App\Modules\REC\Registry;
use App\Modules\SUN\DiagnosisController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bloques del panel de inicio (SCR-DSH-01) con los registros reales de la empresa activa:
 *
 *  GET /dsh/home?year=&site_id=&view=management
 *    passt      avance del Programa Anual SST del año (anillo) y por tipo de actividad (barras)
 *    monthly    IF / IG / IA por mes del año (y del anterior, para comparar) — mismo cálculo que /est/summary
 *    actions    acciones correctivas abiertas más próximas a vencer
 *    heatmap    3 × 3 de probabilidad × severidad de las filas IPERC vigentes (residual si existe)
 *    activity   últimos cambios de la bitácora de la empresa (sin módulos médicos ni detalle de cambios)
 *    management (solo con view=management) cumplimiento del SG-SST, accidentes y días perdidos vs el año anterior,
 *               puntaje del autodiagnóstico SUNAFIL, pendientes de aprobación, cumplimiento por sede y riesgos críticos
 * Cada bloque viene en null si su módulo no está en el plan.
 */
class HomeController extends Controller
{
    /** Módulos con datos de salud: su actividad no se muestra en el inicio (RN-DSH-05). */
    private const MEDICAL = ['EMO', 'VMD', 'VUL', 'DES', 'PSI'];

    private const BARS = [
        'training' => 'Capacitaciones', 'inspection' => 'Inspecciones', 'emo' => 'EMO programados', 'drill' => 'Simulacros', 'meeting' => 'Reuniones del Comité',
    ];

    private array $enabled = [];

    public function __invoke(Request $request): JsonResponse
    {
        $now = DB::selectOne("select extract(year from now() at time zone 'America/Lima')::int as y, (now() at time zone 'America/Lima')::date as d");
        $year = (int) ($request->query('year') ?: $now->y);
        if ($year < 2000 || $year > (int) $now->y) {
            throw ApiProblem::badRequest('Elige un año entre 2000 y el actual.');
        }
        $site = $request->query('site_id');
        if ($site !== null && $site !== '') {
            if (! Str::isUuid($site) || ! DB::selectOne('select 1 as ok from sites where id = ? and deleted_at is null', [$site])) {
                throw ApiProblem::notFound('La sede no existe.');
            }
        } else {
            $site = null;
        }
        $this->enabled = array_column(DB::select("select module_code from feature_flags where mode in ('enabled', 'read_only') and deleted_at is null"), 'module_code');

        $monthly = $this->on('EST') || $this->on('ACC') ? [
            'current' => $this->summary($year),
            'previous' => $year > 2000 ? $this->summary($year - 1) : null,
        ] : null;

        $data = [
            'year' => $year,
            'passt' => $this->on('PAS') ? $this->passt($year) : null,
            'monthly' => $monthly,
            'actions' => $this->on('ACR') ? $this->actions($site) : null,
            'heatmap' => $this->on('IPC') ? $this->heatmap($site) : null,
            'activity' => $this->activity(),
        ];
        if ($request->query('view') === 'management') {
            $data['management'] = $this->management($year, $site, $monthly, $now->d);
        }

        return response()->json(['data' => $data]);
    }

    private function on(string $module): bool
    {
        return in_array($module, $this->enabled, true);
    }

    // ── Programa anual ───────────────────────────────────────────────────────

    private function passt(int $year): array
    {
        $program = DB::selectOne('select id, title, status from annual_programs where year = ? and deleted_at is null order by created_at desc limit 1', [$year]);
        $rows = DB::select(
            "select a.activity_type, count(*) as total, count(*) filter (where a.status = 'done') as done
               from program_activities a join annual_programs p on p.id = a.program_id
              where a.deleted_at is null and p.deleted_at is null and a.status <> 'cancelled' and p.year = ? group by 1", [$year]);
        $by = collect($rows)->keyBy('activity_type');
        $total = (int) collect($rows)->sum('total');
        $done = (int) collect($rows)->sum('done');
        $pct = fn ($d, $t) => $t > 0 ? (int) round($d * 100 / $t) : null;

        return [
            'program_id' => $program?->id,
            'program_title' => $program?->title,
            'total' => $total,
            'done' => $done,
            'pct' => $pct($done, $total),
            'bars' => array_map(fn ($type, $label) => [
                'type' => $type, 'label' => $label, 'total' => (int) ($by[$type]->total ?? 0), 'done' => (int) ($by[$type]->done ?? 0),
                'pct' => $pct((int) ($by[$type]->done ?? 0), (int) ($by[$type]->total ?? 0)),
            ], array_keys(self::BARS), self::BARS),
        ];
    }

    // ── Índices mensuales (reutiliza /est/summary) ───────────────────────────

    private function summary(int $year): array
    {
        $r = (new SummaryController)(Request::create('/est/summary', 'GET', ['year' => $year]))->getData(true)['data'];

        return [
            'year' => $year,
            'months' => array_map(fn ($m) => array_intersect_key($m, array_flip(['month', 'accidents', 'lost_days', 'fi', 'si', 'ai'])), $r['months']),
            'total' => $r['total'],
        ];
    }

    // ── Acciones correctivas ────────────────────────────────────────────────

    private function actions(?string $site): array
    {
        $bind = $site ? [$site] : [];
        $where = "a.deleted_at is null and a.status in ('open','in_progress','overdue')".($site ? ' and a.site_id = ?' : '');
        $rows = DB::select(
            "select a.id, a.code, a.title, a.source_type, a.priority, a.status, a.due_on,
                    coalesce(u.full_name, w.full_name, coalesce(c.trade_name, c.legal_name)) as responsible
               from corrective_actions a
               left join users u on u.id = a.responsible_user_id
               left join workers w on w.id = a.responsible_worker_id
               left join contractors c on c.id = a.contractor_id
              where {$where} order by a.due_on, a.created_at limit 5", $bind);
        $count = (int) DB::selectOne("select count(*) as n from corrective_actions a where {$where}", $bind)->n;

        return ['open' => $count, 'items' => $rows];
    }

    // ── Mapa de calor de las matrices vigentes ──────────────────────────────

    /** Lleva probabilidad y severidad a 3 niveles según la escala de cada criterio (bajo, medio, alto). */
    private function heatmap(?string $site): array
    {
        $rows = DB::select(
            "select r.id, r.row_number, r.hazard, r.risk, r.probability_value, r.severity_value, r.residual_probability, r.residual_severity,
                    r.risk_level, r.residual_level, m.id as matrix_id, m.code as matrix_code, m.title as matrix_title, p.name as position_name,
                    c.probability_scale, c.severity_scale, c.method, c.level_bands, v.version_number
               from iperc_matrices m
               join iperc_versions v on v.id = m.current_version_id and v.deleted_at is null
               join iperc_rows r on r.iperc_version_id = v.id and r.deleted_at is null
               join risk_criteria c on c.id = m.risk_criteria_id
               left join positions p on p.id = coalesce(r.position_id, m.position_id)
              where m.deleted_at is null and m.status = 'active'".($site ? ' and m.site_id = ?' : '').' order by m.code, r.row_number', $site ? [$site] : []);

        $cells = [];
        foreach ($rows as $r) {
            $prob = $r->residual_probability ?? $r->probability_value;
            $sev = $r->residual_probability !== null ? ($r->residual_severity ?? $r->severity_value) : $r->severity_value;
            $level = $r->residual_level ?? $r->risk_level;
            $ps = array_column(json_decode($r->probability_scale, true) ?: [], 'value');
            $ss = array_column(json_decode($r->severity_scale, true) ?: [], 'value');
            $p = $this->tier((int) $prob, $ps);
            $s = $this->tier((int) $sev, $ss);
            $band = collect(json_decode($r->level_bands, true) ?: [])->firstWhere('code', $level);
            $key = "{$p}x{$s}";
            $cells[$key] ??= ['p' => $p, 's' => $s, 'count' => 0, 'rows' => []];
            $cells[$key]['count']++;
            if (count($cells[$key]['rows']) < 30) {
                $cells[$key]['rows'][] = [
                    'id' => $r->id, 'matrix_id' => $r->matrix_id, 'matrix' => "{$r->matrix_code} v{$r->version_number}", 'row_number' => (int) $r->row_number,
                    'position' => $r->position_name ?? $r->matrix_title, 'hazard' => $r->hazard, 'risk' => $r->risk,
                    'level' => $band['label'] ?? $level, 'color' => $band['color'] ?? null,
                ];
            }
        }

        return ['total' => count($rows), 'cells' => array_values($cells)];
    }

    /** 1 = bajo, 2 = medio, 3 = alto, según el lugar del valor dentro de la escala. */
    private function tier(int $value, array $scale): int
    {
        if ($scale === []) {
            return 2;
        }
        $min = min($scale);
        $max = max($scale);
        if ($max <= $min) {
            return 2;
        }

        return max(1, min(3, (int) floor(($value - $min) * 3 / ($max - $min + 1)) + 1));
    }

    // ── Actividad reciente ──────────────────────────────────────────────────

    private function activity(): array
    {
        $resources = Registry::data()['resources'];
        $modules = collect(DB::select('select code, name from modules'))->pluck('name', 'code');
        $rows = DB::select(
            "select a.id, a.occurred_at, a.action, a.module_code, a.entity_type, a.entity_id, a.changes, u.full_name as actor_name
               from audit_logs a left join users u on u.id = a.actor_user_id
              where a.company_id = (select nullif(current_setting('app.company_id', true), '')::uuid)
                and a.occurred_at > now() - interval '90 days'
                and (a.module_code is null or a.module_code not in ('".implode("','", self::MEDICAL)."'))
                and a.entity_type not in ('sessions', 'users', 'memberships', 'api_tokens', 'login')
              order by a.occurred_at desc limit 8");

        return array_values(array_filter(array_map(function ($r) use ($resources, $modules) {
            $res = $resources[$r->entity_type] ?? null;
            $module = $res['module'] ?? $r->module_code;
            if (in_array($module, self::MEDICAL, true)) {
                return null;
            }
            $changes = json_decode((string) $r->changes, true) ?: [];
            $name = null;
            foreach (['label', 'title', 'code', 'name'] as $k) {
                $v = $changes[$k] ?? null;
                $v = is_array($v) ? ($v['to'] ?? $v['new'] ?? $v[1] ?? null) : $v;
                if (is_string($v) && $v !== '') {
                    $name = mb_substr($v, 0, 80);
                    break;
                }
            }

            return [
                'id' => $r->id, 'at' => $r->occurred_at, 'actor' => $r->actor_name ?? 'Sistema',
                'action' => $r->action, 'verb' => $this->verb($r->action),
                'what' => $res['tab'] ?? Str::headline($r->entity_type), 'name' => $name,
                'module' => $module, 'module_name' => $module ? ($modules[$module] ?? $module) : null,
                'table' => $res ? $r->entity_type : null, 'entity_id' => $r->entity_id,
            ];
        }, $rows)));
    }

    private function verb(string $action): string
    {
        return match ($action) {
            'create' => 'registró', 'update' => 'actualizó', 'delete' => 'eliminó', 'submit' => 'envió a aprobación',
            'approve' => 'aprobó', 'return' => 'observó', 'close' => 'cerró', 'sign' => 'firmó', 'restore' => 'restauró',
            default => str_replace('_', ' ', $action),
        };
    }

    // ── Panel de Gerencia ───────────────────────────────────────────────────

    private function management(int $year, ?string $site, ?array $monthly, string $today): array
    {
        $kpis = new KpiService;
        $all = $kpis->overview($year, $site);

        // Accidentes con baja y días perdidos del año vs el mismo periodo del año anterior.
        $cut = substr($today, 5);  // MM-DD
        $events = fn (int $y) => DB::selectOne(
            "select count(*) filter (where event_type in ('disabling_accident','fatal_accident')) as accidents, coalesce(sum(lost_days), 0) as days
               from sst_events where deleted_at is null and status <> 'discarded'
                and (occurred_at at time zone 'America/Lima')::date between ? and ?".($site ? ' and site_id = ?' : ''),
            array_merge(["{$y}-01-01", $y === (int) substr($today, 0, 4) ? $today : "{$y}-{$cut}"], $site ? [$site] : []));
        $cur = $this->on('ACC') ? $events($year) : null;
        $prev = $this->on('ACC') ? $events($year - 1) : null;

        $sun = null;
        if ($this->on('SUN')) {
            $d = (new DiagnosisController)()->getData(true)['data'];
            $sun = ['score' => $d['score'], 'gaps' => count(array_filter($d['areas'], fn ($a) => $a['enabled'] && $a['pct'] !== null && $a['pct'] < 90))];
        }

        return [
            'compliance' => $this->average($all),
            'accidents' => $cur ? ['current' => (int) $cur->accidents, 'previous' => (int) $prev->accidents, 'days' => (int) $cur->days, 'previous_days' => (int) $prev->days] : null,
            'sunafil' => $sun,
            'accident_rate' => $monthly,
            'pending' => $this->pending(),
            'sites' => $this->sites($year),
            'critical' => $this->on('IPC') ? $this->critical($site) : null,
        ];
    }

    /** Promedio de los indicadores en % a favor que se pueden medir (cumplimiento del SG-SST). */
    private function average(array $kpis): ?array
    {
        $vals = array_values(array_filter($kpis, fn ($k) => ! $k['locked'] && $k['unit'] === '%' && $k['direction'] === 'higher_better' && $k['value'] !== null));
        if ($vals === []) {
            return null;
        }

        return ['pct' => (int) round(array_sum(array_column($vals, 'value')) / count($vals)), 'indicators' => count($vals)];
    }

    private function sites(int $year): array
    {
        $out = [];
        foreach (DB::select("select id, name from sites where deleted_at is null and status = 'active' order by is_main desc, name limit 8") as $s) {
            $avg = $this->average((new KpiService)->overview($year, $s->id));
            $out[] = ['id' => $s->id, 'name' => $s->name, 'pct' => $avg['pct'] ?? null];
        }

        return $out;
    }

    /** Documentos que esperan aprobación (en revisión) en los módulos del plan. */
    private function pending(): array
    {
        $checks = [
            ['POL', 'sst_policies', "select id, title as name, 'Política SST' as kind, updated_at from sst_policies where deleted_at is null and status = 'in_review'"],
            ['PLA', 'annual_plans', "select id, title as name, 'Plan anual '||year as kind, updated_at from annual_plans where deleted_at is null and status = 'in_review'"],
            ['PAS', 'annual_programs', "select id, title as name, 'Programa anual '||year as kind, updated_at from annual_programs where deleted_at is null and status = 'committee_review'"],
            ['PRE', 'budgets', "select id, title as name, 'Presupuesto '||year as kind, updated_at from budgets where deleted_at is null and status = 'in_review'"],
            ['RIS', 'risst_versions', "select id, title as name, 'Reglamento interno v'||version_number as kind, updated_at from risst_versions where deleted_at is null and status = 'in_review'"],
            ['IPC', 'iperc_versions', "select m.id, m.code||' · '||m.title as name, 'Matriz IPERC v'||v.version_number as kind, v.updated_at from iperc_versions v join iperc_matrices m on m.id = v.iperc_matrix_id where v.deleted_at is null and v.status = 'in_review'"],
            ['MAP', 'risk_maps', "select id, title as name, 'Mapa de riesgos' as kind, updated_at from risk_maps where deleted_at is null and status = 'in_review'"],
        ];
        $out = [];
        foreach ($checks as [$module, $table, $sql]) {
            if (! $this->on($module)) {
                continue;
            }
            foreach (DB::select($sql.' order by 4 desc limit 5') as $r) {
                $out[] = ['module' => $module, 'table' => $table, 'id' => $r->id, 'name' => $r->name, 'kind' => $r->kind, 'since' => $r->updated_at];
            }
        }
        usort($out, fn ($a, $b) => strcmp((string) $b['since'], (string) $a['since']));

        return array_slice($out, 0, 8);
    }

    /** Riesgos significativos de las matrices vigentes con controles aún propuestos (sin implementar). */
    private function critical(?string $site): array
    {
        return DB::select(
            "select r.id, r.hazard, r.risk, m.id as matrix_id, m.code as matrix_code, coalesce(a.name, s.name) as place, p.name as position_name,
                    count(c.id) filter (where c.status = 'proposed') as pending_controls
               from iperc_matrices m
               join iperc_rows r on r.iperc_version_id = m.current_version_id and r.deleted_at is null and r.is_significant
               left join iperc_controls c on c.iperc_row_id = r.id and c.deleted_at is null
               left join areas a on a.id = m.area_id left join sites s on s.id = m.site_id
               left join positions p on p.id = coalesce(r.position_id, m.position_id)
              where m.deleted_at is null and m.status = 'active'".($site ? ' and m.site_id = ?' : '')."
              group by r.id, m.id, a.name, s.name, p.name
             having count(c.id) filter (where c.status = 'proposed') > 0 or count(c.id) filter (where c.status in ('implemented','verified','existing')) = 0
              order by r.risk_score desc limit 5", $site ? [$site] : []);
    }
}
