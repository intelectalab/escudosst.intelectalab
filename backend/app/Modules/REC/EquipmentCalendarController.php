<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /eqe/calendar?year= — M-EQE SCR-EQE-04: recargas, pruebas hidrostáticas e inspecciones de los equipos por mes (vencidas
 * marcadas), y por sede los extintores operativos frente al mínimo que configuró la empresa (RN-EQE-08, brecha para SUNAFIL).
 */
class EquipmentCalendarController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! in_array('eqe.emergency_equipment.read', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no ve los equipos de emergencia.');
        }
        $year = (int) $request->query('year', now('America/Lima')->format('Y'));
        $today = now('America/Lima')->format('Y-m-d');
        $items = DB::select(
            "select e.id, e.code, e.equipment_type, s.name as site, x.kind, x.due
               from emergency_equipment e left join sites s on s.id = e.site_id
               cross join lateral (values ('recharge', e.next_recharge_on), ('hydrostatic_test', e.next_hydrostatic_test_on), ('inspection', e.next_inspection_on),
                                          ('maintenance', e.next_maintenance_on)) as x(kind, due)
              where e.deleted_at is null and e.status <> 'retired' and x.due is not null and (extract(year from x.due) = ? or x.due < ?::date)
              ".\App\Core\Auth\Scope::andTable('emergency_equipment', 'e').
              " order by x.due", [$year, $today]);
        foreach ($items as $i) {
            $i->overdue = $i->due < $today;
        }
        $sites = DB::select(
            "select s.id, s.name, s.min_extinguishers,
                    (select count(*) from emergency_equipment e where e.site_id = s.id and e.deleted_at is null and e.equipment_type = 'fire_extinguisher' and e.status = 'operational') as operational
               from sites s where s.deleted_at is null and s.status = 'active'".\App\Core\Auth\Scope::andTable('sites', 's').' order by s.name');
        foreach ($sites as $s) {
            $s->operational = (int) $s->operational;
            $s->gap = $s->min_extinguishers !== null && $s->operational < (int) $s->min_extinguishers;
        }

        return response()->json(['data' => ['year' => $year, 'items' => $items, 'sites' => $sites]]);
    }
}
