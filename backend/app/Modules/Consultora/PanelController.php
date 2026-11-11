<?php

namespace App\Modules\Consultora;

use App\Core\Tenancy\CompanyContext;
use App\Core\Tenancy\DbContext;
use App\Http\Controllers\Controller;
use App\Modules\DSH\Services\KpiService;
use App\Modules\SUN\DiagnosisController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Panel de la consultora (M-CON SCR-CON-01/02, docs/07 §5.1):
 *  GET /con/panel   empresas cliente con asignación vigente: trabajadores, plan, figura SST, cumplimiento (autodiagnóstico
 *                   SUNAFIL), alertas para actuar; agenda de los próximos 30 días y alertas críticas por empresa.
 *
 * Cada empresa se calcula con su propio contexto RLS (nunca se mezclan datos) y solo agrega indicadores, sin datos
 * personales (RN-CON-07). Un consultor solo ve las empresas con asignación vigente (RN-CON-01).
 */
class PanelController extends Controller
{
    private const AGENDA_DAYS = 30;

    private const SECTORS = ['construction' => 'Construcción', 'mining' => 'Minería', 'agroindustry' => 'Agroindustria', 'manufacturing' => 'Manufactura',
        'retail' => 'Comercio', 'services' => 'Servicios', 'office' => 'Oficinas', 'health' => 'Salud', 'education' => 'Educación', 'transport' => 'Transporte',
        'energy' => 'Energía', 'other' => 'Otro'];

    public function __invoke(): JsonResponse
    {
        $con = Consultancy::resolve();
        $active = app(CompanyContext::class)->companyId;
        $year = (int) DB::selectOne("select extract(year from now() at time zone 'America/Lima')::int as y")->y;
        $today = DB::selectOne("select (now() at time zone 'America/Lima')::date as d")->d;
        $until = date('Y-m-d', strtotime($today.' +'.self::AGENDA_DAYS.' days'));

        $rows = [];
        $agenda = [];
        $critical = [];
        $consultantIds = [];
        try {
            foreach ($con->clients as $m) {
                DbContext::setCompany($m->company_id);
                $assigned = DB::select("select consultant_user_id from consultant_assignments where status = 'active' and deleted_at is null
                                          and starts_on <= current_date and (ends_on is null or ends_on >= current_date)");
                $assignedIds = array_column($assigned, 'consultant_user_id');
                if ($m->role_code === 'consultant' && ! in_array((string) auth()->id(), $assignedIds, true)) {
                    continue;
                }
                $consultantIds = [...$consultantIds, ...$assignedIds];
                $svc = new KpiService;
                $svc->overview($year, null);   // fija el periodo que usan las alertas
                $alerts = $svc->alerts();
                $score = null;
                try {
                    $score = app(DiagnosisController::class)()->getData(true)['data']['score'] ?? null;
                } catch (Throwable) {
                    $score = null;
                }
                $plan = DB::selectOne("select p.code, p.name, s.status from subscriptions s join plans p on p.id = s.plan_id
                                        where s.company_id = ? and s.status not in ('cancelled') order by s.created_at desc limit 1", [$m->company_id]);
                $name = $m->company_name;
                $rows[] = [
                    'company_id' => $m->company_id, 'name' => $name, 'ruc' => $m->ruc, 'role' => $m->role_code, 'is_active' => $m->company_id === $active,
                    'sector' => self::SECTORS[$m->economic_sector] ?? ($m->economic_sector ? ucfirst((string) $m->economic_sector) : null),
                    'workers' => $svc->counts()['workers'],
                    'plan' => $plan ? ['code' => $plan->code, 'name' => $plan->name, 'status' => $plan->status] : null,
                    'sst_model' => $m->sst_model,
                    'compliance' => $score === null ? null : (int) $score,
                    'alerts' => array_sum(array_column($alerts, 'count')),
                    'critical' => array_sum(array_column(array_filter($alerts, fn ($a) => $a['tone'] === 'bad'), 'count')),
                    'consultants' => $assignedIds,
                ];
                foreach ($alerts as $a) {
                    $critical[] = ['company_id' => $m->company_id, 'company' => $name, 'tone' => $a['tone'], 'count' => $a['count'], 'text' => $a['text'], 'module' => $a['module']];
                }
                foreach ($this->agenda($today, $until) as $e) {
                    $agenda[] = ['company_id' => $m->company_id, 'company' => $name] + $e;
                }
            }
        } finally {
            DbContext::setCompany($active);
        }

        // Orden por riesgo: más alertas críticas, luego menor cumplimiento, luego más alertas (docs/07 §5.1).
        usort($rows, fn ($a, $b) => [$b['critical'], $a['compliance'] ?? 101, $b['alerts']] <=> [$a['critical'], $b['compliance'] ?? 101, $a['alerts']]);
        usort($agenda, fn ($a, $b) => strcmp($a['date'], $b['date']));
        usort($critical, fn ($a, $b) => [$a['tone'] === 'bad' ? 0 : 1, -$a['count']] <=> [$b['tone'] === 'bad' ? 0 : 1, -$b['count']]);

        $names = [];
        $ids = array_values(array_unique($consultantIds));
        if ($ids) {
            foreach (DB::connection('platform')->select('select id, full_name from users where id = any(?::uuid[])', ['{'.implode(',', $ids).'}']) as $u) {
                $names[$u->id] = $u->full_name;
            }
        }
        $measured = array_filter(array_column($rows, 'compliance'), fn ($v) => $v !== null);

        return response()->json(['data' => [
            'consultancy' => ['name' => $con->name, 'is_admin' => $con->is_admin, 'home_company_id' => $con->own[0]->company_id ?? null],
            'kpis' => [
                'companies' => count($rows),
                'workers' => array_sum(array_column($rows, 'workers')),
                'compliance' => $measured ? (int) round(array_sum($measured) / count($measured)) : null,
                'alerts' => array_sum(array_column($rows, 'alerts')),
            ],
            'companies' => $rows,
            'consultants' => array_map(fn ($id) => ['id' => $id, 'name' => $names[$id] ?? 'Consultor'], $ids),
            'agenda' => array_slice($agenda, 0, 20),
            'critical' => array_slice(array_values(array_filter($critical, fn ($c) => $c['tone'] === 'bad')) ?: $critical, 0, 12),
            'agenda_days' => self::AGENDA_DAYS,
        ]]);
    }

    /** Visitas y actividades programadas de la empresa (inspecciones, reuniones del comité, capacitaciones). */
    private function agenda(string $from, string $to): array
    {
        $out = [];
        $q = fn (string $sql) => DB::select($sql, [$from, $to]);
        foreach ($q("select title as text, scheduled_on::text as date from inspections where deleted_at is null and status = 'scheduled' and scheduled_on between ? and ? order by scheduled_on limit 10") as $r) {
            $out[] = ['kind' => 'Inspección', 'module' => 'INS', 'text' => $r->text, 'date' => $r->date];
        }
        foreach ($q("select meeting_type, (scheduled_at at time zone 'America/Lima')::date::text as date from committee_meetings
                      where deleted_at is null and status = 'scheduled' and (scheduled_at at time zone 'America/Lima')::date between ? and ? order by scheduled_at limit 10") as $r) {
            $out[] = ['kind' => 'Reunión del Comité', 'module' => 'COM', 'text' => $r->meeting_type === 'extraordinary' ? 'Sesión extraordinaria' : ($r->meeting_type === 'installation' ? 'Instalación del comité' : 'Sesión ordinaria'), 'date' => $r->date];
        }
        foreach ($q("select t.title as text, (s.starts_at at time zone 'America/Lima')::date::text as date from training_sessions s join trainings t on t.id = s.training_id
                      where s.deleted_at is null and s.status = 'scheduled' and (s.starts_at at time zone 'America/Lima')::date between ? and ? order by s.starts_at limit 10") as $r) {
            $out[] = ['kind' => 'Capacitación', 'module' => 'CAP', 'text' => $r->text, 'date' => $r->date];
        }

        return $out;
    }
}
