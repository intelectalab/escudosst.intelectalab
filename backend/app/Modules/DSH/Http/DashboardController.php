<?php

namespace App\Modules\DSH\Http;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Core\Tenancy\DbContext;
use App\Http\Controllers\Controller;
use App\Modules\DSH\Services\KpiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tablero principal de la empresa (SCR-DSH-01):
 *
 *  GET /dsh/overview?year=2026&site_id=   indicadores con semáforo, alertas para actuar y conteos base
 *  GET /dsh/group?year=2026                 tablero consolidado (SCR-DSH-04): empresas × indicadores del usuario
 *
 * El consolidado incluye solo las empresas donde el usuario tiene una membresía activa con permiso de ver el
 * tablero y el módulo DSH habilitado; cada empresa se calcula con su propio contexto RLS (nunca se mezclan datos).
 * Si el rol es consultor, solo cuentan las empresas con asignación vigente (RN-DSH-07).
 */
class DashboardController extends Controller
{
    public function __construct(private KpiService $kpis) {}

    public function overview(Request $request): JsonResponse
    {
        $current = (int) DB::selectOne("select extract(year from now() at time zone 'America/Lima')::int as y")->y;
        $year = (int) ($request->query('year') ?: $current);
        if ($year < 2000 || $year > $current) {
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

        $kpis = $this->kpis->overview($year, $site);

        return response()->json(['data' => [
            'year' => $year,
            'site_id' => $site,
            'kpis' => $kpis,
            'alerts' => $year === $current ? $this->kpis->alerts() : [],
            'counts' => $this->kpis->counts(),
            'sites' => DB::select("select id, name from sites where deleted_at is null and status = 'active' order by is_main desc, name"),
        ]]);
    }

    public function group(Request $request): JsonResponse
    {
        $current = (int) DB::selectOne("select extract(year from now() at time zone 'America/Lima')::int as y")->y;
        $year = (int) ($request->query('year') ?: $current);
        if ($year < 2000 || $year > $current) {
            throw ApiProblem::badRequest('Elige un año entre 2000 y el actual.');
        }
        $active = app(CompanyContext::class)->companyId;
        $group = DB::selectOne('select a.name, a.account_type from companies c join accounts a on a.id = c.account_id where c.id = ?', [$active]);
        $mine = DB::select('select membership_id, company_id, company_name, ruc, role_code from app_my_memberships() order by company_name');

        $rows = [];
        try {
            foreach ($mine as $m) {
                DbContext::setCompany($m->company_id);
                $can = DB::selectOne(
                    "select 1 as ok from memberships ms
                       join role_permissions rp on rp.role_id = ms.role_id
                       join permissions p on p.id = rp.permission_id and p.deleted_at is null and p.code = 'dsh.dashboards.read'
                       join feature_flags f on f.module_code = 'DSH' and f.deleted_at is null and f.mode in ('enabled','read_only')
                      where ms.id = ?", [$m->membership_id]);
                if (! $can) {
                    continue;
                }
                if ($m->role_code === 'consultant' && ! DB::selectOne(
                    "select 1 as ok from consultant_assignments where consultant_user_id = app_user_id() and status = 'active' and deleted_at is null
                        and starts_on <= current_date and (ends_on is null or ends_on >= current_date)")) {
                    continue;
                }
                $svc = new KpiService;
                $kpis = array_values(array_map(fn ($k) => array_intersect_key($k, array_flip(['code', 'name', 'module', 'locked', 'value', 'label', 'basis', 'color', 'link'])),
                    $svc->overview($year, null)));
                $alerts = $year === $current ? $svc->alerts() : [];
                $red = count(array_filter($kpis, fn ($k) => ($k['color'] ?? null) === 'red'));
                $amber = count(array_filter($kpis, fn ($k) => ($k['color'] ?? null) === 'amber'));
                $rows[] = [
                    'company_id' => $m->company_id, 'name' => $m->company_name, 'ruc' => trim($m->ruc), 'role' => $m->role_code, 'is_active' => $m->company_id === $active,
                    'workers' => $svc->counts()['workers'], 'red' => $red, 'amber' => $amber,
                    'alerts' => array_sum(array_column($alerts, 'count')), 'alert_items' => $alerts, 'kpis' => $kpis,
                ];
            }
        } finally {
            DbContext::setCompany($active);
        }
        // Orden por riesgo: más indicadores en rojo, luego más alertas, luego ámbar.
        usort($rows, fn ($a, $b) => [$b['red'], $b['alerts'], $b['amber']] <=> [$a['red'], $a['alerts'], $a['amber']]);

        // Columnas: los indicadores que tiene al menos una empresa, en el orden del tablero.
        $columns = [];
        foreach ($rows as $r) {
            foreach ($r['kpis'] as $k) {
                if (! $k['locked'] && ! isset($columns[$k['code']])) {
                    $columns[$k['code']] = ['code' => $k['code'], 'name' => $k['name']];
                }
            }
        }

        return response()->json(['data' => [
            'year' => $year,
            'group' => ['name' => $group?->name, 'type' => $group?->account_type],
            'columns' => array_values($columns),
            'companies' => $rows,
        ]]);
    }
}
