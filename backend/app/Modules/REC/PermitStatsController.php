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
 * GET /ptr/stats.xlsx?from=&to=  M-PTR §11: estadísticas de permisos del periodo por tipo (emitidos, activos, cerrados,
 * suspendidos, rechazados, vencidos), por sede, y las suspensiones con su causa.
 */
class PermitStatsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if (! in_array('ptr.work_permits.read', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no ve las estadísticas de permisos.');
        }
        $from = (string) ($request->query('from') ?: now('America/Lima')->startOfYear()->toDateString());
        $to = (string) ($request->query('to') ?: now('America/Lima')->toDateString());
        $range = "w.deleted_at is null and (w.created_at at time zone 'America/Lima')::date between ? and ?".\App\Core\Auth\Scope::andSql('w.site_id', 'w.area_id');
        $suspendedEver = "w.status = 'suspended' or w.suspended_reason is not null";
        $q = fn (string $expr) => DB::select(
            "select {$expr} as k, count(*) as n,
                    count(*) filter (where w.status = 'active') as active, count(*) filter (where w.status = 'closed') as closed,
                    count(*) filter (where {$suspendedEver}) as suspended, count(*) filter (where w.status = 'rejected') as rejected,
                    count(*) filter (where w.status = 'expired') as expired
               from work_permits w where {$range} group by 1 order by 2 desc", [$from, $to]);

        $out = [['Estadísticas de permisos de trabajo (PETAR)'], ["Del {$from} al {$to}. Suspendidos: permisos que estuvieron suspendidos alguna vez."], []];
        foreach ([['Por tipo de trabajo', $q('(select t.name from permit_types t where t.id = w.permit_type_id)')], ['Por sede', $q('(select s.name from sites s where s.id = w.site_id)')]] as [$title, $rows]) {
            $out[] = [$title];
            $out[] = ['Grupo', 'Emitidos', 'Activos', 'Cerrados', 'Suspendidos', 'Rechazados', 'Vencidos'];
            foreach ($rows as $r) {
                $out[] = [$r->k ?? 'Sin dato', (int) $r->n, (int) $r->active, (int) $r->closed, (int) $r->suspended, (int) $r->rejected, (int) $r->expired];
            }
            if ($rows === []) {
                $out[] = ['Sin permisos en el periodo', 0, 0, 0, 0, 0, 0];
            }
            $out[] = [];
        }
        $out[] = ['Suspensiones y causas'];
        $out[] = ['Permiso', 'Tipo', 'Sede', 'Causa'];
        $sus = DB::select(
            "select w.code, (select t.name from permit_types t where t.id = w.permit_type_id) as type, (select s.name from sites s where s.id = w.site_id) as site, w.suspended_reason
               from work_permits w where {$range} and w.suspended_reason is not null order by w.created_at", [$from, $to]);
        foreach ($sus as $s) {
            $out[] = [$s->code, $s->type, $s->site, $s->suspended_reason];
        }
        if ($sus === []) {
            $out[] = ['Sin suspensiones en el periodo', '', '', ''];
        }

        return response(Xlsx::build('Permisos', $out, 5), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"permisos-{$from}-{$to}.xlsx\"",
        ]);
    }
}
