<?php

namespace App\Modules\EST;

use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Estadísticas mensuales (docs/modulos/M-EST.md §8):
 *  POST /est/months            calcular (o recalcular) un mes {year, month}: una fila por sede y el total de la empresa
 *  POST /est/months/{id}/close cerrar el mes (queda inmutable)
 *
 * - RN-EST-01: IF = accidentes con tiempo perdido × factor / horas-hombre; IG = días perdidos × factor / horas-hombre; IA = IF × IG / divisor.
 * - RN-EST-02: los factores usados quedan guardados en el mes (parameters_snapshot).
 * - RN-EST-03: no se cierra sin horas-hombre de todas las sedes activas.
 * - RN-EST-04: los eventos se cuentan por mes de ocurrencia.
 * - RN-EST-05: sin horas-hombre los índices quedan vacíos («No aplica»).
 * - RN-EST-08: un mes cerrado no se recalcula.
 */
class MonthlyStatsController extends Controller
{
    public function compute(Request $request): JsonResponse
    {
        $year = (int) $request->input('year');
        $month = (int) $request->input('month');
        $now = DB::selectOne("select extract(year from now() at time zone 'America/Lima')::int as y, extract(month from now() at time zone 'America/Lima')::int as m");
        if ($year < 2000 || $month < 1 || $month > 12 || $year * 100 + $month > $now->y * 100 + $now->m) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Elige un mes válido que ya haya empezado.', ['errors' => ['month' => ['Mes no válido.']]]);
        }
        $ff = LegalParams::number('frequency_index_factor', 1000000);
        $sf = LegalParams::number('severity_index_factor', 1000000);
        $div = LegalParams::number('accident_rate_divisor', 1000);
        $snapshot = json_encode(['frequency_index_factor' => $ff, 'severity_index_factor' => $sf, 'accident_rate_divisor' => $div], JSON_UNESCAPED_UNICODE);
        $company = app(CompanyContext::class)->companyId;
        $user = (string) auth()->id();

        $sites = array_column(DB::select("select id from sites where deleted_at is null and status = 'active'"), 'id');
        $rows = [];
        foreach ([...$sites, null] as $site) {
            $closed = DB::selectOne('select is_closed from monthly_statistics where period_year = ? and period_month = ? and site_id is not distinct from ? and deleted_at is null',
                [$year, $month, $site]);
            if ($closed?->is_closed) {
                throw new ApiProblem(409, 'conflict', 'Mes cerrado', 'Ese mes ya está cerrado: no se recalcula.');
            }
            $siteSql = $site ? ' and site_id = ?' : '';
            $bind = $site ? [$year, $month, $site] : [$year, $month];
            $hours = DB::selectOne('select coalesce(sum(hours_worked), 0) as h, coalesce(sum(worker_count), 0) as w from man_hours
                                     where deleted_at is null and period_year = ? and period_month = ?'.$siteSql, $bind);
            $ev = DB::selectOne(
                "select count(*) filter (where event_type = 'fatal_accident') as fatal, count(*) filter (where event_type = 'disabling_accident') as disabling,
                        count(*) filter (where event_type = 'minor_accident') as minor, count(*) filter (where event_type = 'dangerous_incident') as dangerous,
                        count(*) filter (where event_type = 'incident') as incidents, count(*) filter (where event_type = 'occupational_disease') as diseases,
                        coalesce(sum(lost_days), 0) as days
                   from sst_events where deleted_at is null and status <> 'discarded'
                    and extract(year from occurred_at at time zone 'America/Lima') = ? and extract(month from occurred_at at time zone 'America/Lima') = ?".$siteSql,
                $bind
            );
            $h = (float) $hours->h;
            $lost = (int) $ev->fatal + (int) $ev->disabling;
            $fi = $h > 0 ? round($lost * $ff / $h, 2) : null;
            $si = $h > 0 ? round($ev->days * $sf / $h, 2) : null;
            $ai = $h > 0 ? round($fi * $si / $div, 2) : null;
            $values = [(int) $hours->w, $h, (int) $ev->fatal, (int) $ev->disabling, (int) $ev->minor, (int) $ev->dangerous, (int) $ev->incidents, (int) $ev->diseases,
                (int) $ev->days, $fi, $si, $ai, $ff, $sf, $div, $snapshot];
            $existing = DB::selectOne('select id from monthly_statistics where period_year = ? and period_month = ? and site_id is not distinct from ? and deleted_at is null', [$year, $month, $site]);
            if ($existing) {
                DB::update(
                    'update monthly_statistics set worker_count = ?, hours_worked = ?, accidents_fatal = ?, accidents_disabling = ?, accidents_minor = ?, dangerous_incidents = ?,
                        incidents = ?, occupational_diseases = ?, lost_days = ?, frequency_index = ?, severity_index = ?, accident_rate_index = ?, frequency_factor = ?,
                        severity_factor = ?, accident_rate_divisor = ?, parameters_snapshot = ?::jsonb, computed_at = now(), updated_by = ? where id = ?',
                    [...$values, $user, $existing->id]
                );
            } else {
                DB::insert(
                    'insert into monthly_statistics (company_id, period_year, period_month, site_id, worker_count, hours_worked, accidents_fatal, accidents_disabling,
                        accidents_minor, dangerous_incidents, incidents, occupational_diseases, lost_days, frequency_index, severity_index, accident_rate_index,
                        frequency_factor, severity_factor, accident_rate_divisor, parameters_snapshot, computed_at, is_closed, created_by, updated_by)
                     values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, now(), false, ?, ?)',
                    [$company, $year, $month, $site, ...$values, $user, $user]
                );
            }
            $rows[] = ['site_id' => $site, 'hours_worked' => $h, 'frequency_index' => $fi, 'severity_index' => $si, 'accident_rate_index' => $ai, 'lost_days' => (int) $ev->days];
        }

        return response()->json(['data' => $rows]);
    }

    public function close(string $id): JsonResponse
    {
        $m = DB::selectOne('select * from monthly_statistics where id = ? and deleted_at is null', [$id]);
        if (! $m) {
            throw ApiProblem::notFound('El mes no existe.');
        }
        if ($m->is_closed) {
            throw new ApiProblem(409, 'conflict', 'Mes cerrado', 'El mes ya está cerrado.');
        }
        $missing = DB::select(
            "select s.name from sites s where s.deleted_at is null and s.status = 'active'
                and not exists (select 1 from man_hours h where h.site_id = s.id and h.period_year = ? and h.period_month = ? and h.deleted_at is null and h.hours_worked > 0)",
            [$m->period_year, $m->period_month]
        );
        if ($missing) {
            throw new ApiProblem(422, 'validation_failed', 'Faltan horas-hombre', 'Registra las horas-hombre del mes de: '.implode(', ', array_column($missing, 'name')).'.');
        }
        DB::update('update monthly_statistics set is_closed = true, closed_by = ?, closed_at = now() where period_year = ? and period_month = ? and deleted_at is null',
            [(string) auth()->id(), $m->period_year, $m->period_month]);

        return response()->json(['data' => ['closed' => true]]);
    }
}
