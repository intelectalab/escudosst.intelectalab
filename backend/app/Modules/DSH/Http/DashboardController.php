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
        $mine = DB::select('select membership_id, company_id, company_name, ruc, split_part(role_code, \':\', 1) as role_code from app_my_memberships() order by company_name');

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

    /**
     * GET /dsh/history?year=&site_id=&code=   historia mensual de los indicadores (kpi_snapshots): valor acumulado del año al cierre de
     * cada mes, por sede (sin sede = toda la empresa). Con «.xlsx» (GET /dsh/history.xlsx) devuelve las series en Excel.
     */
    public function history(Request $request)
    {
        $year = (int) ($request->query('year') ?: date('Y'));
        $site = $request->query('site_id');
        $site = $site && Str::isUuid((string) $site) ? $site : null;
        $code = $request->query('code');
        $rows = DB::select(
            "select k.code, k.name, k.unit, extract(month from s.period_start)::int as month, s.value, s.numerator, s.denominator, s.status_color, s.period_end
               from kpi_snapshots s join kpi_definitions k on k.id = s.kpi_definition_id
              where s.deleted_at is null and extract(year from s.period_start) = ? and s.site_id is not distinct from ?::uuid".($code ? ' and k.code = ?' : '').'
              order by k.name, month', $code ? [$year, $site, $code] : [$year, $site]);
        $series = [];
        foreach ($rows as $r) {
            $series[$r->code] ??= ['code' => $r->code, 'name' => $r->name, 'unit' => $r->unit, 'points' => []];
            $series[$r->code]['points'][] = ['month' => $r->month, 'value' => $r->value === null ? null : (float) $r->value, 'numerator' => $r->numerator, 'denominator' => $r->denominator,
                'color' => $r->status_color, 'as_of' => $r->period_end];
        }
        if (str_ends_with($request->path(), '.xlsx')) {
            $months = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Set', 'Oct', 'Nov', 'Dic'];
            $c = DB::selectOne('select legal_name, ruc from companies where id = ?', [app(\App\Core\Tenancy\CompanyContext::class)->companyId]);
            $sheet = [['INDICADORES SST '.$year.' (ACUMULADO DEL AÑO AL CIERRE DE CADA MES)'], ["{$c->legal_name} · RUC ".trim((string) $c->ruc)
                .($site ? ' · Sede: '.DB::selectOne('select name from sites where id = ?', [$site])?->name : ' · Toda la empresa')], [], array_merge(['Indicador', 'Unidad'], $months)];
            foreach ($series as $s) {
                $byMonth = array_column($s['points'], 'value', 'month');
                $sheet[] = array_merge([$s['name'], $s['unit']], array_map(fn ($m) => $byMonth[$m] ?? null, range(1, 12)));
            }

            return response(\App\Core\Support\Xlsx::build('Indicadores', $sheet, 4), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"indicadores-{$year}.xlsx\"",
            ]);
        }

        return response()->json(['data' => ['year' => $year, 'site_id' => $site, 'series' => array_values($series)]]);
    }

    /**
     * GET /dsh/boards — tableros de la empresa visibles para el rol (los de su rol y los generales), con sus indicadores en orden.
     * Los arma cada empresa con el editor (POST /dsh/boards); los de fábrica se crean al dar de alta la empresa.
     */
    public function boards(): JsonResponse
    {
        $role = app(\App\Core\Tenancy\CompanyContext::class)->roleCode;
        $edit = in_array('dsh.dashboards.update', \App\Core\Auth\Permissions::effective(), true);
        $boards = DB::select(
            'select id, name, audience_role, is_default, owner_user_id from dashboards where deleted_at is null'.($edit ? '' : ' and (audience_role is null or audience_role = ?)')
            .' order by is_default desc, name', $edit ? [] : [$role]);
        foreach ($boards as $b) {
            $b->kpis = array_column(DB::select(
                'select k.code from dashboard_widgets w join kpi_definitions k on k.id = w.kpi_definition_id where w.dashboard_id = ? and w.deleted_at is null order by w.position, w.created_at', [$b->id]), 'code');
            $b->mine = $b->audience_role === null || $b->audience_role === $role;
        }

        return response()->json(['data' => ['boards' => $boards, 'can_edit' => $edit, 'role' => $role,
            'kpis' => DB::select('select distinct on (code) code, name from kpi_definitions where deleted_at is null and is_active order by code, company_id nulls last')]]);
    }

    /** POST /dsh/boards {id?, name, audience_role?, kpi_codes[]} — editor de tableros: nombre, para qué rol y qué indicadores en qué orden. */
    public function saveBoard(Request $request): JsonResponse
    {
        if (! in_array('dsh.dashboards.update', \App\Core\Auth\Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no arma tableros.');
        }
        $d = $request->validate([
            'id' => ['nullable', 'uuid'], 'name' => ['required', 'string', 'max:120'], 'audience_role' => ['nullable', 'string', 'max:60'],
            'kpi_codes' => ['required', 'array', 'min:1', 'max:40'], 'kpi_codes.*' => ['string', 'max:80'],
        ], ['name.required' => 'Escribe el nombre del tablero.', 'kpi_codes.required' => 'Elige al menos un indicador.', 'kpi_codes.min' => 'Elige al menos un indicador.']);
        $company = app(\App\Core\Tenancy\CompanyContext::class)->companyId;
        $uid = (string) auth()->id();
        $id = DB::transaction(function () use ($d, $company, $uid) {
            if (! empty($d['id'])) {
                if (! DB::update('update dashboards set name = ?, audience_role = ?, updated_by = ?, updated_at = now() where id = ? and deleted_at is null', [$d['name'], $d['audience_role'] ?? null, $uid, $d['id']])) {
                    throw ApiProblem::notFound('El tablero no existe.');
                }
                DB::update('update dashboard_widgets set deleted_at = now() where dashboard_id = ? and deleted_at is null', [$d['id']]);
                $id = $d['id'];
            } else {
                $id = DB::selectOne('insert into dashboards (company_id, name, audience_role, owner_user_id, is_default, created_by, updated_by) values (?, ?, ?, ?, false, ?, ?) returning id',
                    [$company, $d['name'], $d['audience_role'] ?? null, $uid, $uid, $uid])->id;
            }
            foreach (array_values(array_unique($d['kpi_codes'])) as $pos => $code) {
                $k = DB::selectOne('select id, name from kpi_definitions where code = ? and deleted_at is null and is_active order by company_id nulls last limit 1', [$code]);
                if ($k) {
                    DB::insert("insert into dashboard_widgets (company_id, dashboard_id, kpi_definition_id, widget_type, title, position, created_by, updated_by) values (?, ?, ?, 'card', ?, ?, ?, ?)",
                        [$company, $id, $k->id, $k->name, $pos, $uid, $uid]);
                }
            }

            return $id;
        });

        return response()->json(['data' => ['id' => $id]], empty($d['id']) ? 201 : 200);
    }

    /** DELETE /dsh/boards/{id} — quita un tablero propio (los de fábrica no se borran). */
    public function deleteBoard(string $id): JsonResponse
    {
        if (! in_array('dsh.dashboards.delete', \App\Core\Auth\Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no quita tableros.');
        }
        if (! Str::isUuid($id) || ! DB::update('update dashboards set deleted_at = now() where id = ? and not is_default and deleted_at is null', [$id])) {
            throw ApiProblem::notFound('El tablero no existe o es de fábrica.');
        }

        return response()->json(['data' => ['id' => $id]]);
    }
}
