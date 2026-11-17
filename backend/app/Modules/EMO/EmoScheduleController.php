<?php

namespace App\Modules\EMO;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Http\Controllers\Controller;
use App\Modules\REC\RecordsService;
use App\Modules\REC\Registry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Programación de EMO (M-EMO SCR-EMO-01), sin datos clínicos:
 *  GET  /emo/due?site_id=&position_id=   trabajadores activos con aptitud vencida, por vencer (emo_due_notice_days) o sin EMO, con su orden abierta
 *                                        y el resumen por sede y por puesto
 *  POST /emo/orders/bulk                 genera órdenes para muchos trabajadores a la vez {worker_ids, exam_type, scheduled_at, clinic_name?}
 */
class EmoScheduleController extends Controller
{
    public function due(Request $request): JsonResponse
    {
        if (! in_array('emo.medical_exam_orders.read', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no programa exámenes médicos.');
        }
        $days = (int) LegalParams::number('emo_due_notice_days', 30);
        $bind = [$days];
        $where = '';
        foreach (['site_id' => 'w.current_site_id', 'position_id' => 'w.current_position_id'] as $q => $col) {
            $v = (string) $request->query($q, '');
            if (\Illuminate\Support\Str::isUuid($v)) {
                $where .= " and {$col} = ?";
                $bind[] = $v;
            }
        }
        $rows = DB::select(
            "select w.id, w.full_name, s.name as site, p.name as position, f.valid_until,
                    case when f.id is null then 'none' when f.valid_until is not null and f.valid_until < current_date then 'expired' else 'expiring' end as state,
                    (select o.scheduled_at from medical_exam_orders o where o.worker_id = w.id and o.deleted_at is null and o.status = 'scheduled' order by o.scheduled_at limit 1) as scheduled_at
               from workers w left join sites s on s.id = w.current_site_id left join positions p on p.id = w.current_position_id
               left join fitness_status f on f.worker_id = w.id and f.is_current and f.deleted_at is null
              where w.deleted_at is null and w.status = 'active'
                and (f.id is null or (f.valid_until is not null and f.valid_until <= current_date + ?::int)) {$where}
              order by (f.id is null) desc, f.valid_until nulls first, w.full_name", $bind);
        $group = function (string $key) use ($rows) {
            $g = [];
            foreach ($rows as $r) {
                $k = $r->{$key} ?? 'Sin dato';
                $g[$k] ??= ['name' => $k, 'expired' => 0, 'expiring' => 0, 'none' => 0];
                $g[$k][$r->state]++;
            }

            return array_values($g);
        };

        return response()->json(['data' => ['days' => $days, 'workers' => $rows, 'by_site' => $group('site'), 'by_position' => $group('position')]]);
    }

    public function bulk(Request $request, RecordsService $records): JsonResponse
    {
        if (! in_array('emo.medical_exam_orders.create', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no programa exámenes médicos.');
        }
        $d = $request->validate([
            'worker_ids' => ['required', 'array', 'min:1', 'max:300'], 'worker_ids.*' => ['uuid'],
            'exam_type' => ['required', 'in:pre_employment,periodic,exit,return_to_work,position_change,other'],
            'scheduled_at' => ['required', 'date'], 'clinic_name' => ['nullable', 'string', 'max:200'], 'clinic_ruc' => ['nullable', 'string', 'max:11'],
        ], ['worker_ids.required' => 'Elige a los trabajadores.', 'scheduled_at.required' => 'Indica la fecha de la cita.']);
        $res = Registry::resource('EMO', 'medical_exam_orders');
        $created = 0;
        $skipped = [];
        foreach (array_unique($d['worker_ids']) as $w) {
            $name = DB::selectOne('select full_name from workers where id = ? and deleted_at is null', [$w])?->full_name;
            if (! $name) {
                continue;
            }
            if (DB::selectOne("select 1 as ok from medical_exam_orders where worker_id = ? and status = 'scheduled' and deleted_at is null", [$w])) {
                $skipped[] = "{$name}: ya tiene una orden programada";

                continue;
            }
            try {
                $records->create($res, array_filter(['worker_id' => $w, 'exam_type' => $d['exam_type'], 'scheduled_at' => $d['scheduled_at'],
                    'clinic_name' => $d['clinic_name'] ?? null, 'clinic_ruc' => $d['clinic_ruc'] ?? null], fn ($x) => $x !== null));
                $created++;
            } catch (\Throwable $e) {
                $skipped[] = "{$name}: ".($e instanceof ApiProblem ? (collect($e->extra['errors'] ?? [])->flatten()->first() ?? ($e->detail ?? $e->title)) : 'no se pudo programar');
            }
        }

        return response()->json(['data' => ['created' => $created, 'skipped' => $skipped]], 201);
    }
}
