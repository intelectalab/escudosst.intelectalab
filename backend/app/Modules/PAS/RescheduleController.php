<?php

namespace App\Modules\PAS;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * POST /pas/activities/{id}/reschedule {from_month, to_month, reason} — «Reprogramar» una actividad-mes (M-PAS RN-PAS-02).
 * Solo un mes programado y sin ejecución, con programa aprobado o en ejecución; el mes nuevo es del mismo año, no está
 * ya programado y no es anterior al mes actual. Mueve el mes en planned_months, guarda la marca original en
 * activity_reschedules (inalterable) y deja la acción en la auditoría.
 */
class RescheduleController extends Controller
{
    public function __invoke(Request $request, string $id): JsonResponse
    {
        $d = $request->validate([
            'from_month' => ['required', 'integer', 'between:1,12'],
            'to_month' => ['required', 'integer', 'between:1,12', 'different:from_month'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'reason.required' => 'Escribe el motivo de la reprogramación.',
            'reason.min' => 'Explica el motivo de la reprogramación (al menos 5 caracteres).',
            'to_month.required' => 'Elige el nuevo mes.',
            'to_month.different' => 'El nuevo mes debe ser distinto del mes original.',
        ]);
        if (! Str::isUuid($id)) {
            throw ApiProblem::notFound('La actividad no existe.');
        }
        $a = DB::selectOne(
            'select a.id, a.title, a.status, a.planned_months, p.year, p.status as program_status
               from program_activities a join annual_programs p on p.id = a.program_id
              where a.id = ? and a.deleted_at is null and p.deleted_at is null', [$id]
        );
        if (! $a) {
            throw ApiProblem::notFound('La actividad no existe.');
        }
        if (! in_array($a->program_status, ['approved', 'in_execution'], true)) {
            $this->fail('from_month', 'Solo se reprograma en un programa aprobado o en ejecución.');
        }
        if ($a->status === 'cancelled') {
            $this->fail('from_month', 'La actividad está cancelada.');
        }
        $planned = array_map('intval', $a->planned_months ? explode(',', trim((string) $a->planned_months, '{}')) : []);
        $from = (int) $d['from_month'];
        $to = (int) $d['to_month'];
        if (! in_array($from, $planned, true)) {
            $this->fail('from_month', 'Ese mes no está programado para la actividad.');
        }
        if (in_array($to, $planned, true)) {
            $this->fail('to_month', 'La actividad ya está programada en ese mes.');
        }
        $year = (int) $a->year;
        if (DB::selectOne(
            'select 1 as ok from activity_executions where activity_id = ? and deleted_at is null and extract(year from executed_on) = ? and extract(month from executed_on) = ?',
            [$id, $year, $from]
        )) {
            $this->fail('from_month', 'Ese mes ya tiene una ejecución registrada: no se reprograma.');
        }
        $now = DB::selectOne("select extract(year from now() at time zone 'America/Lima')::int as y, extract(month from now() at time zone 'America/Lima')::int as m");
        if ($year < $now->y || ($year === (int) $now->y && $to < (int) $now->m)) {
            $this->fail('to_month', 'El nuevo mes no puede ser un mes que ya pasó.');
        }

        $new = array_values(array_unique([...array_diff($planned, [$from]), $to]));
        sort($new);
        $ctx = app(CompanyContext::class);
        $uid = (string) auth()->id();
        $rid = DB::transaction(function () use ($id, $new, $year, $from, $to, $d, $ctx, $uid, $planned) {
            DB::update('update program_activities set planned_months = ?::smallint[], updated_by = ? where id = ?', ['{'.implode(',', $new).'}', $uid, $id]);
            $rid = DB::selectOne(
                'insert into activity_reschedules (company_id, activity_id, year, from_month, to_month, reason, created_by) values (?, ?, ?, ?, ?, ?, ?) returning id',
                [$ctx->companyId, $id, $year, $from, $to, trim($d['reason']), $uid]
            )->id;
            DB::insert(
                "insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes, reason, ip, user_agent)
                 values (?, ?, ?, 'user', 'activity_rescheduled', 'PAS', 'program_activities', ?, ?::jsonb, ?, ?::inet, ?)",
                [$ctx->companyId, $uid, $ctx->roleCode, $id, json_encode(['planned_months' => ['old' => $planned, 'new' => $new], 'from_month' => $from, 'to_month' => $to], JSON_UNESCAPED_UNICODE),
                    trim($d['reason']), request()->ip(), mb_substr((string) request()->userAgent(), 0, 300)]
            );

            return $rid;
        });

        return response()->json(['data' => ['id' => $rid, 'activity_id' => $id, 'from_month' => $from, 'to_month' => $to, 'planned_months' => $new]], 201);
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}
