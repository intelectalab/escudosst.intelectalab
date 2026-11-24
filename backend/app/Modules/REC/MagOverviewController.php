<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * M-MAG:
 *  GET    /mag/points            SCR-MAG-01: puntos por sede con su último resultado por agente (semáforo) y sus coordenadas
 *  GET    /mag/calendar?year=    SCR-MAG-02: campañas del año por mes y puntos que vencen (RN-MAG-07, monitoring_frequency_months)
 *  GET    /mag/unit-conversions  RN-MAG-04: tabla de conversión de unidades (de la empresa y generales)
 *  POST   /mag/unit-conversions  registra una conversión de la empresa {agent_type_id?, from_unit, to_unit, factor, source}
 *  DELETE /mag/unit-conversions/{id}
 */
final class MagOverviewController
{
    private const RESULT = ['within_limit' => 'green', 'action_level' => 'amber', 'exceeds_limit' => 'red'];

    public function points(): JsonResponse
    {
        $this->can('mag.monitoring_points.read');
        $points = DB::select(
            "select p.id, p.code, p.name, p.latitude, p.longitude, p.site_id, s.name as site, a.name as area
               from monitoring_points p left join sites s on s.id = p.site_id left join areas a on a.id = p.area_id
              where p.deleted_at is null and coalesce(p.status, 'active') = 'active' order by s.name nulls last, p.code");
        $last = DB::select(
            "select distinct on (m.monitoring_point_id, m.agent_type_id) m.monitoring_point_id, t.name as agent, m.value, m.unit, m.result, m.ratio_to_limit, m.measured_at
               from measurements m join agent_types t on t.id = m.agent_type_id
              where m.deleted_at is null and m.status = 'approved' and m.monitoring_point_id is not null
              order by m.monitoring_point_id, m.agent_type_id, m.measured_at desc");
        $by = [];
        foreach ($last as $m) {
            $by[$m->monitoring_point_id][] = ['agent' => $m->agent, 'value' => (float) $m->value, 'unit' => $m->unit, 'result' => $m->result,
                'color' => self::RESULT[$m->result] ?? null, 'measured_at' => $m->measured_at];
        }
        $rank = ['red' => 3, 'amber' => 2, 'green' => 1];
        $out = array_map(function ($p) use ($by, $rank) {
            $ms = $by[$p->id] ?? [];
            $worst = null;
            foreach ($ms as $m) {
                if ($m['color'] && ($rank[$m['color']] > ($rank[$worst] ?? 0))) {
                    $worst = $m['color'];
                }
            }

            return ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'site_id' => $p->site_id, 'site' => $p->site, 'area' => $p->area,
                'lat' => $p->latitude !== null ? (float) $p->latitude : null, 'lng' => $p->longitude !== null ? (float) $p->longitude : null, 'color' => $worst, 'measurements' => $ms];
        }, $points);

        return response()->json(['data' => $out]);
    }

    public function calendar(Request $request): JsonResponse
    {
        $this->can('mag.monitoring_campaigns.read');
        $year = (int) ($request->query('year') ?: now('America/Lima')->format('Y'));
        if ($year < 2000 || $year > 2100) {
            throw ApiProblem::badRequest('Elige un año válido.');
        }
        $campaigns = DB::select(
            "select id, title, provider_name, starts_on, ends_on, status, report_file_id is not null as has_report from monitoring_campaigns
              where deleted_at is null and (extract(year from coalesce(starts_on, ends_on)) = ? or extract(year from ends_on) = ? or year = ?) order by starts_on nulls last", [$year, $year, $year]);
        // RN-MAG-07: cada punto con agente debe medirse cada monitoring_frequency_months; vence a partir de su última medición aprobada.
        $freq = (int) \App\Core\Support\LegalParams::number('monitoring_frequency_months', 12);
        $due = DB::select(
            "select p.id, p.code, p.name, s.name as site, max(m.measured_at)::date as last_on,
                    (coalesce(max(m.measured_at)::date, current_date) + make_interval(months => ?))::date as due_on, max(m.measured_at) is null as never
               from monitoring_points p left join sites s on s.id = p.site_id
               left join measurements m on m.monitoring_point_id = p.id and m.deleted_at is null and m.status = 'approved'
              where p.deleted_at is null and coalesce(p.status, 'active') = 'active'
              group by p.id, p.code, p.name, s.name order by due_on", [$freq]);
        $months = array_fill(1, 12, ['campaigns' => [], 'due' => []]);
        foreach ($campaigns as $c) {
            $from = $c->starts_on ? (int) date('n', strtotime($c->starts_on)) : ($c->ends_on ? (int) date('n', strtotime($c->ends_on)) : null);
            $to = $c->ends_on ? (int) date('n', strtotime($c->ends_on)) : $from;
            if ($c->starts_on && (int) date('Y', strtotime($c->starts_on)) < $year) {
                $from = 1;
            }
            if ($c->ends_on && (int) date('Y', strtotime($c->ends_on)) > $year) {
                $to = 12;
            }
            for ($m = $from ?? 1; $from && $m <= $to; $m++) {
                $months[$m]['campaigns'][] = ['id' => $c->id, 'title' => $c->title, 'status' => $c->status];
            }
        }
        foreach ($due as $d) {
            // Sin medición o ya vencido: es brecha y se lista en «Puntos sin monitoreo al día» (/mag/gaps).
            if (! $d->never && (int) substr($d->due_on, 0, 4) === $year) {
                $months[(int) substr($d->due_on, 5, 2)]['due'][] = ['id' => $d->id, 'code' => $d->code, 'name' => $d->name, 'site' => $d->site, 'due_on' => $d->due_on];
            }
        }

        return response()->json(['data' => ['year' => $year, 'frequency_months' => $freq, 'months' => array_map(fn ($m, $i) => ['month' => $i] + $m, $months, array_keys($months))]]);
    }

    public function conversions(): JsonResponse
    {
        $this->can('mag.measurements.read');

        return response()->json(['data' => DB::select(
            "select u.id, u.from_unit, u.to_unit, u.factor, u.source, u.company_id is null as global, t.name as agent
               from unit_conversions u left join agent_types t on t.id = u.agent_type_id where u.deleted_at is null order by t.name nulls first, u.from_unit, u.to_unit")]);
    }

    public function storeConversion(Request $request): JsonResponse
    {
        $this->can('mag.exposure_limits.create');
        $d = $request->validate([
            'agent_type_id' => ['nullable', 'uuid'], 'from_unit' => ['required', 'string', 'max:30'], 'to_unit' => ['required', 'string', 'max:30', 'different:from_unit'],
            'factor' => ['required', 'numeric', 'gt:0'], 'source' => ['required', 'string', 'max:300'],
        ], ['from_unit.required' => 'Escribe la unidad de origen.', 'to_unit.required' => 'Escribe la unidad de destino.', 'to_unit.different' => 'Las unidades deben ser distintas.',
            'factor.gt' => 'El factor debe ser mayor que cero.', 'source.required' => 'Indica la fuente del factor (norma, ficha técnica o laboratorio).']);
        if (! empty($d['agent_type_id']) && ! DB::selectOne('select 1 as ok from agent_types where id = ? and deleted_at is null', [$d['agent_type_id']])) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'El agente no existe.', ['errors' => ['agent_type_id' => ['El agente no existe.']]]);
        }
        if ($this->conversionExists($d['agent_type_id'] ?? null, $d['from_unit'], $d['to_unit'])) {
            throw new ApiProblem(409, 'conflict', 'Ya existe', 'Ya hay una conversión entre esas unidades para ese agente.');
        }
        $uid = (string) auth()->id();
        $id = DB::selectOne('insert into unit_conversions (company_id, agent_type_id, from_unit, to_unit, factor, source, created_by, updated_by) values (?, ?, ?, ?, ?, ?, ?, ?) returning id',
            [app(CompanyContext::class)->companyId, $d['agent_type_id'] ?? null, trim($d['from_unit']), trim($d['to_unit']), $d['factor'], trim($d['source']), $uid, $uid])->id;

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function deleteConversion(string $id): JsonResponse
    {
        $this->can('mag.exposure_limits.delete');
        if (! Str::isUuid($id) || ! DB::update('update unit_conversions set deleted_at = now(), updated_by = ? where id = ? and company_id is not null and deleted_at is null', [(string) auth()->id(), $id])) {
            throw ApiProblem::notFound('La conversión no existe o es general (la administra la plataforma).');
        }

        return response()->json(['data' => ['id' => $id]]);
    }

    private function conversionExists(?string $agent, string $from, string $to): bool
    {
        return (bool) DB::selectOne(
            'select 1 as ok from unit_conversions where deleted_at is null and lower(trim(from_unit)) = lower(trim(?)) and lower(trim(to_unit)) = lower(trim(?))
                and agent_type_id is not distinct from ?::uuid and company_id is not distinct from ?::uuid',
            [$from, $to, $agent, app(CompanyContext::class)->companyId]);
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden();
        }
    }
}
