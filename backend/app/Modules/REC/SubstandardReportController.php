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
 * GET /act/report.xlsx?from=&to=  M-ACT §11: reportes de actos y condiciones del periodo por área, por tipo y por canal, con
 * cuántos se cerraron y el tiempo de atención (horas hasta la revisión y días hasta el cierre). Sin datos del reportante.
 */
class SubstandardReportController extends Controller
{
    private const TYPE = ['unsafe_act' => 'Acto subestándar', 'unsafe_condition' => 'Condición subestándar', 'near_miss' => 'Casi accidente', 'suggestion' => 'Sugerencia'];

    private const ORIGIN = ['qr' => 'QR', 'whatsapp' => 'WhatsApp', 'app' => 'App', 'web' => 'Web'];

    public function __invoke(Request $request): Response
    {
        if (! in_array('act.substandard_reports.read', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no ve el reporte de actos y condiciones.');
        }
        $from = (string) ($request->query('from') ?: now('America/Lima')->startOfYear()->toDateString());
        $to = (string) ($request->query('to') ?: now('America/Lima')->toDateString());
        $q = fn (string $expr) => DB::select(
            "select {$expr} as k, count(*) as n, count(*) filter (where r.status = 'closed') as closed, count(*) filter (where r.status = 'escalated') as escalated,
                    round(avg(extract(epoch from (r.triaged_at - r.reported_at)) / 3600)::numeric, 1) as triage_h,
                    round(avg(extract(epoch from (r.closed_at - r.reported_at)) / 86400)::numeric, 1) as close_d
               from substandard_reports r
              where r.deleted_at is null and (r.reported_at at time zone 'America/Lima')::date between ? and ?
              group by 1 order by 2 desc", [$from, $to]);

        $out = [['Reportes de actos y condiciones'], ["Del {$from} al {$to}. Tiempo de atención: promedio desde que se reportó."], []];
        foreach ([
            ['Por área', $q('(select a.name from areas a where a.id = r.area_id)'), []],
            ['Por tipo', $q('r.report_type'), self::TYPE],
            ['Por canal', $q('r.origin'), self::ORIGIN],
        ] as [$title, $rows, $names]) {
            $out[] = [$title];
            $out[] = ['Grupo', 'Reportes', 'Cerrados', 'Escalados a evento', 'Horas hasta revisarse', 'Días hasta cerrarse'];
            foreach ($rows as $r) {
                $out[] = [$r->k === null ? 'Sin dato' : ($names[$r->k] ?? $r->k), (int) $r->n, (int) $r->closed, (int) $r->escalated,
                    $r->triage_h === null ? '' : (float) $r->triage_h, $r->close_d === null ? '' : (float) $r->close_d];
            }
            if ($rows === []) {
                $out[] = ['Sin reportes en el periodo', 0, 0, 0, '', ''];
            }
            $out[] = [];
        }

        return response(Xlsx::build('Reportes', $out, 5), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"reportes-actos-condiciones-{$from}-{$to}.xlsx\"",
        ]);
    }
}
