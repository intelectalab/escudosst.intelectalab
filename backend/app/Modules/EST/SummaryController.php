<?php

namespace App\Modules\EST;

use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /est/summary?year=AAAA — tabla mensual de la empresa (SCR-EST-02): trabajadores, horas-hombre, accidentes con
 * baja (mortales + incapacitantes), días perdidos e índices IF, IG e IA de cada mes del año, más el acumulado.
 * Si el mes ya se calculó (monthly_statistics, total de la empresa) se usa ese valor; si no, se calcula al vuelo con
 * las horas-hombre y los eventos registrados, sin guardarlo (RN-EST-01, RN-EST-04, RN-EST-05).
 */
class SummaryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $now = DB::selectOne("select extract(year from now() at time zone 'America/Lima')::int as y, extract(month from now() at time zone 'America/Lima')::int as m");
        $year = (int) ($request->query('year') ?: $now->y);
        if ($year < 2000 || $year > $now->y) {
            throw ApiProblem::badRequest('Elige un año entre 2000 y el actual.');
        }
        $last = $year === (int) $now->y ? (int) $now->m : 12;
        $ff = LegalParams::number('frequency_index_factor', 1000000);
        $sf = LegalParams::number('severity_index_factor', 1000000);
        $div = LegalParams::number('accident_rate_divisor', 1000);

        $stored = [];
        foreach (DB::select('select * from monthly_statistics where period_year = ? and site_id is null and deleted_at is null', [$year]) as $m) {
            $stored[(int) $m->period_month] = $m;
        }
        $hours = [];
        foreach (DB::select('select period_month, sum(hours_worked) as h, sum(worker_count) as w from man_hours where deleted_at is null and period_year = ? group by 1', [$year]) as $h) {
            $hours[(int) $h->period_month] = $h;
        }
        $events = [];
        foreach (DB::select(
            "select extract(month from occurred_at at time zone 'America/Lima')::int as m,
                    count(*) filter (where event_type in ('fatal_accident','disabling_accident')) as lost, coalesce(sum(lost_days), 0) as days
               from sst_events where deleted_at is null and status <> 'discarded' and extract(year from occurred_at at time zone 'America/Lima') = ? group by 1", [$year]) as $e) {
            $events[(int) $e->m] = $e;
        }

        $months = [];
        $tot = ['hours' => 0.0, 'accidents' => 0, 'days' => 0];
        for ($m = 1; $m <= $last; $m++) {
            $s = $stored[$m] ?? null;
            if ($s) {
                $row = ['month' => $m, 'workers' => $s->worker_count === null ? null : (int) $s->worker_count, 'hours' => (float) $s->hours_worked,
                    'accidents' => (int) $s->accidents_fatal + (int) $s->accidents_disabling, 'lost_days' => (int) $s->lost_days,
                    'fi' => $s->frequency_index === null ? null : (float) $s->frequency_index, 'si' => $s->severity_index === null ? null : (float) $s->severity_index,
                    'ai' => $s->accident_rate_index === null ? null : (float) $s->accident_rate_index, 'source' => $s->is_closed ? 'closed' : 'computed', 'id' => $s->id];
            } else {
                $h = (float) ($hours[$m]->h ?? 0);
                $acc = (int) ($events[$m]->lost ?? 0);
                $days = (int) ($events[$m]->days ?? 0);
                $fi = $h > 0 ? round($acc * $ff / $h, 2) : null;
                $si = $h > 0 ? round($days * $sf / $h, 2) : null;
                $row = ['month' => $m, 'workers' => isset($hours[$m]) ? (int) $hours[$m]->w : null, 'hours' => $h, 'accidents' => $acc, 'lost_days' => $days,
                    'fi' => $fi, 'si' => $si, 'ai' => $fi !== null ? round($fi * $si / $div, 2) : null, 'source' => 'live', 'id' => null];
            }
            $tot['hours'] += $row['hours'];
            $tot['accidents'] += $row['accidents'];
            $tot['days'] += $row['lost_days'];
            $months[] = $row;
        }
        $fi = $tot['hours'] > 0 ? round($tot['accidents'] * $ff / $tot['hours'], 2) : null;
        $si = $tot['hours'] > 0 ? round($tot['days'] * $sf / $tot['hours'], 2) : null;

        return response()->json(['data' => [
            'year' => $year,
            'factors' => ['frequency' => $ff, 'severity' => $sf, 'accident_rate_divisor' => $div],
            'months' => $months,
            'total' => ['hours' => $tot['hours'], 'accidents' => $tot['accidents'], 'lost_days' => $tot['days'], 'fi' => $fi, 'si' => $si,
                'ai' => $fi !== null ? round($fi * $si / $div, 2) : null],
        ]]);
    }
}
