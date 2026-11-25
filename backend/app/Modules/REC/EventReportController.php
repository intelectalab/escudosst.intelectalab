<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\Xlsx;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /acc/report.xlsx?from=&to=  M-ACC §11: eventos del periodo agrupados por tipo, gravedad, sede, parte del cuerpo y agente
 * (estos dos, por persona lesionada), con días perdidos. Las etiquetas salen del catálogo de campos.
 */
class EventReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if (! in_array('acc.sst_events.read', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no ve el reporte de eventos.');
        }
        // El periodo lo elige el usuario (Desde / Hasta en la lista de eventos); sin fechas, del 1 de enero a hoy.
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']],
            ['to.after_or_equal' => 'La fecha «Hasta» debe ser igual o posterior a «Desde».']);
        $from = (string) ($request->query('from') ?: now('America/Lima')->startOfYear()->toDateString());
        $to = (string) ($request->query('to') ?: now('America/Lima')->toDateString());
        $range = "e.deleted_at is null and (e.occurred_at at time zone 'America/Lima')::date between ? and ?".\App\Core\Auth\Scope::andTable('sst_events', 'e');
        $labels = fn (string $field) => collect(Registry::data()['resources']['sst_events']['fields'])->firstWhere('name', $field)['options'] ?? [];
        $map = fn (string $field) => collect($labels($field))->pluck('label', 'value')->all();
        $type = $map('event_type');
        $sev = $map('severity');

        $out = [['Eventos SST agrupados'], ["Del {$from} al {$to}."], []];
        $section = function (string $title, array $rows, array $names = []) use (&$out) {
            $out[] = [$title];
            $out[] = ['Grupo', 'Eventos', 'Días perdidos'];
            foreach ($rows as $r) {
                $out[] = [$r->k === null ? 'Sin dato' : ($names[$r->k] ?? $r->k), (int) $r->n, (int) $r->d];
            }
            if ($rows === []) {
                $out[] = ['Sin eventos en el periodo', 0, 0];
            }
            $out[] = [];
        };
        $q = fn (string $expr, string $from_sql = 'sst_events e') => DB::select(
            "select k, count(*) as n, coalesce(sum(d), 0) as d
               from (select distinct e.id, {$expr} as k, coalesce(e.lost_days, 0) as d from {$from_sql} where {$range}) x group by k order by 2 desc", [$from, $to]);
        $people = "sst_events e join event_people p on p.event_id = e.id and p.deleted_at is null and p.person_role in ('injured', 'ill')";

        $section('Por tipo de evento', $q('e.event_type'), $type);
        $section('Por gravedad', $q('e.severity'), $sev);
        $section('Por sede', $q('(select s.name from sites s where s.id = e.site_id)'));
        $section('Por parte del cuerpo (personas lesionadas o enfermas)', $q('nullif(trim(p.body_part), \'\')', $people));
        $section('Por agente (personas lesionadas o enfermas)', $q('nullif(trim(coalesce(p.injury_agent, p.disease_agent_type)), \'\')', $people));

        return response(Xlsx::build('Eventos', $out, 5), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"eventos-{$from}-{$to}.xlsx\"",
        ]);
    }
}
