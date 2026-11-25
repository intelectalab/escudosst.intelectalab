<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Auth\Scope;
use App\Core\Http\ApiProblem;
use App\Core\Support\Xlsx;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * M-ACT §11: ranking de condiciones recurrentes. Agrupa los reportes de condiciones subestándar del periodo por tipo de
 * condición (la clasificación del peligro que se le dio al revisarlo), sede, área y lugar, y los ordena por cantidad.
 * Un grupo es «recurrente» cuando tiene al menos `min` reportes en el periodo (2 por defecto: la ficha no da el umbral).
 * Se muestra la forma de escribir la condición y el lugar más usada en el grupo. También cuenta cuántos se reportaron después de que se cerró el primero del grupo (la condición volvió a aparecer).
 *  GET /act/recurrent-conditions?from=&to=&min=       — en pantalla (JSON).
 *  GET /act/recurrent-conditions.xlsx?from=&to=&min=  — en Excel.
 * Sin datos de quien reporta; respeta el alcance por sede y área del usuario.
 */
class RecurrentConditionsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->build($request)]);
    }

    public function xlsx(Request $request): Response
    {
        $d = $this->build($request);
        $out = [['Ranking de condiciones recurrentes'], ["Del {$d['from']} al {$d['to']}. Recurrente: {$d['min']} o más reportes de la misma condición en el periodo."], []];
        $out[] = ['Por tipo de condición, sede, área y lugar'];
        $out[] = ['Puesto', 'Tipo de condición', 'Sede', 'Área', 'Lugar', 'Reportes', 'Meses con reportes', 'Abiertos', 'Reaparecieron tras un cierre', 'Primer reporte', 'Último reporte', 'Recurrente'];
        foreach ($d['groups'] as $k => $g) {
            $out[] = [$k + 1, $g['condition'] ?? 'Sin clasificar', $g['site'] ?? 'Sin dato', $g['area'] ?? 'Sin dato', $g['location'] ?? 'Sin dato', $g['count'], $g['months'], $g['open'],
                $g['after_close'], $g['first_on'], $g['last_on'], $g['recurrent'] ? 'Sí' : 'No'];
        }
        if ($d['groups'] === []) {
            $out[] = ['', 'Sin condiciones reportadas en el periodo'];
        }
        foreach ([['Por tipo de condición', 'by_condition', 'Tipo de condición'], ['Por área', 'by_area', 'Área']] as [$title, $key, $col]) {
            $out[] = [];
            $out[] = [$title];
            $out[] = ['Puesto', $col, 'Reportes', 'Lugares distintos', 'Abiertos', 'Recurrente'];
            foreach ($d[$key] as $k => $g) {
                $out[] = [$k + 1, $g['name'] ?? ($key === 'by_area' ? 'Sin dato' : 'Sin clasificar'), $g['count'], $g['places'], $g['open'], $g['recurrent'] ? 'Sí' : 'No'];
            }
        }

        return response(Xlsx::build('Recurrentes', $out, 5), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"condiciones-recurrentes-{$d['from']}-{$d['to']}.xlsx\"",
        ]);
    }

    private function build(Request $request): array
    {
        if (! in_array('act.substandard_reports.read', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no ve los reportes de actos y condiciones.');
        }
        $date = fn ($v, string $def) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $def;
        $from = $date($request->query('from'), now('America/Lima')->startOfYear()->toDateString());
        $to = $date($request->query('to'), now('America/Lima')->toDateString());
        if ($from > $to) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'La fecha inicial es posterior a la final.', ['errors' => ['from' => ['La fecha inicial es posterior a la final.']]]);
        }
        $min = max(2, min(100, (int) ($request->query('min') ?: 2)));
        $base = "from substandard_reports r
                 where r.deleted_at is null and r.report_type = 'unsafe_condition' and r.status <> 'rejected'
                   and (coalesce(r.occurred_at, r.reported_at, r.created_at) at time zone 'America/Lima')::date between ? and ?".Scope::andTable('substandard_reports', 'r');
        $cond = "nullif(lower(btrim(r.classification)), '')";
        $place = "nullif(lower(btrim(r.location_text)), '')";
        $groups = DB::select(
            "select mode() within group (order by btrim(r.classification)) as condition, r.site_id, r.area_id, mode() within group (order by btrim(r.location_text)) as location,
                    (select s.name from sites s where s.id = r.site_id) as site, (select a.name from areas a where a.id = r.area_id) as area,
                    count(*) as n, count(distinct to_char(coalesce(r.occurred_at, r.reported_at, r.created_at) at time zone 'America/Lima', 'YYYY-MM')) as months,
                    count(*) filter (where r.status not in ('closed', 'escalated')) as open,
                    min(coalesce(r.occurred_at, r.reported_at, r.created_at) at time zone 'America/Lima')::date as first_on, max(coalesce(r.occurred_at, r.reported_at, r.created_at) at time zone 'America/Lima')::date as last_on,
                    count(*) filter (where coalesce(r.occurred_at, r.reported_at, r.created_at) > (select min(x.closed_at) from substandard_reports x
                        where x.deleted_at is null and x.report_type = 'unsafe_condition' and x.closed_at is not null and x.site_id is not distinct from r.site_id
                          and x.area_id is not distinct from r.area_id and nullif(lower(btrim(x.classification)), '') is not distinct from {$cond}
                          and nullif(lower(btrim(x.location_text)), '') is not distinct from {$place})) as after_close
             {$base}
              group by {$cond}, r.site_id, r.area_id, {$place}
              order by n desc, last_on desc limit 200", [$from, $to]);
        $rank = fn (string $expr, string $name) => array_map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->n, 'places' => (int) $r->places, 'open' => (int) $r->open,
            'recurrent' => (int) $r->n >= $min], DB::select(
                "select {$name} as name, count(*) as n, count(distinct coalesce({$place}, '')) as places,
                        count(*) filter (where r.status not in ('closed', 'escalated')) as open
                 {$base} group by {$expr} order by n desc limit 50", [$from, $to]));

        return [
            'from' => $from, 'to' => $to, 'min' => $min,
            'groups' => array_map(fn ($g) => ['condition' => $g->condition, 'site_id' => $g->site_id, 'site' => $g->site, 'area_id' => $g->area_id, 'area' => $g->area,
                'location' => $g->location, 'count' => (int) $g->n, 'months' => (int) $g->months, 'open' => (int) $g->open, 'after_close' => (int) $g->after_close,
                'first_on' => $g->first_on, 'last_on' => $g->last_on, 'recurrent' => (int) $g->n >= $min], $groups),
            'by_condition' => $rank($cond, 'mode() within group (order by btrim(r.classification))'),
            'by_area' => $rank('r.area_id', '(select a.name from areas a where a.id = r.area_id)'),
        ];
    }
}
