<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * M-SIM SCR-SIM-02 «Ejecutar simulacro»:
 *  GET  /sim/drills/{id}/run   simulacro, meta de evacuación de la sede y trabajadores activos de la sede (con su marca si ya se registró)
 *  POST /sim/drills/{id}/run   {alarm_time, evacuation_complete_time, attendees: [{worker_id, role}]}: guarda los tiempos (RN-SIM-01),
 *                              la asistencia por trabajador presente (RN-SIM-08) y deja el simulacro «ejecutado» con el mismo
 *                              servicio de registros (reglas, avisos y bitácora). Devuelve quiénes no participaron.
 *  POST /sim/drills/{id}/evaluation  evaluación por criterios y observaciones (SCR-SIM-03).
 */
class DrillRunController extends Controller
{
    public function show(string $id): JsonResponse
    {
        $this->can('sim.drills.read');
        $d = $this->drill($id);

        return response()->json(['data' => $this->payload($d)]);
    }

    public function save(Request $request, string $id, RecordsService $records): JsonResponse
    {
        $this->can('sim.drills.update');
        $this->can('sim.drill_participants.create');
        $d = $this->drill($id);
        if (in_array($d->status, ['evaluated', 'cancelled'], true)) {
            throw new ApiProblem(409, 'conflict', 'No se modifica', $d->status === 'evaluated' ? 'El simulacro ya fue evaluado: no se modifica.' : 'El simulacro está cancelado.');
        }
        $v = $request->validate([
            'alarm_time' => ['required', 'date'],
            'evacuation_complete_time' => ['required', 'date', 'after_or_equal:alarm_time'],
            'attendees' => ['array', 'max:2000'],
            'attendees.*.worker_id' => ['required', 'uuid'],
            'attendees.*.role' => ['nullable', 'in:participant,brigadist,observer,evaluator'],
        ], ['alarm_time.required' => 'Marca la hora de la alarma.', 'evacuation_complete_time.required' => 'Marca la hora en que terminó la evacuación.',
            'evacuation_complete_time.after_or_equal' => 'La evacuación no puede terminar antes de la alarma.']);
        $alarm = new \DateTimeImmutable($v['alarm_time']);
        $done = new \DateTimeImmutable($v['evacuation_complete_time']);
        $seconds = $done->getTimestamp() - $alarm->getTimestamp();
        $att = collect($v['attendees'] ?? [])->keyBy('worker_id');
        $resDrill = Registry::resource('SIM', 'drills');
        $resPart = Registry::resource('SIM', 'drill_participants');

        DB::transaction(function () use ($records, $resDrill, $resPart, $d, $att, $alarm, $done, $seconds) {
            $existing = collect(DB::select('select id, worker_id from drill_participants where drill_id = ? and worker_id is not null and deleted_at is null', [$d->id]))->keyBy('worker_id');
            foreach ($att as $w => $a) {
                $role = $a['role'] ?? 'participant';
                if ($e = $existing->get($w)) {
                    $records->update($resPart, $e->id, ['attended' => true, 'role' => $role]);
                } else {
                    $records->create($resPart, ['drill_id' => $d->id, 'worker_id' => $w, 'role' => $role, 'attended' => true]);
                }
            }
            foreach ($existing as $w => $e) {
                if (! $att->has($w)) {
                    $records->update($resPart, $e->id, ['attended' => false]);
                }
            }
            $records->update($resDrill, $d->id, ['alarm_time' => $alarm->format(DATE_ATOM), 'evacuation_complete_time' => $done->format(DATE_ATOM),
                'evacuation_time_seconds' => $seconds, 'participants_count' => $att->count(), 'executed_at' => $alarm->format(DATE_ATOM), 'status' => 'executed']);
        });

        return response()->json(['data' => $this->payload($this->drill($id))]);
    }

    /**
     * POST /sim/drills/{id}/evaluation  {criteria: [{name, met, note}], observations}  (SCR-SIM-03): evaluación por criterios
     * (los del parámetro drill_evaluation_criteria) y observaciones. Se registra con el simulacro ejecutado y antes de evaluarlo;
     * al evaluarlo «por mejorar» o «no satisfactorio», las observaciones pasan a su acción correctiva (RN-SIM-06).
     */
    public function evaluate(Request $request, string $id): JsonResponse
    {
        $this->can('sim.drills.update');
        $d = $this->drill($id);
        if ($d->status !== 'executed') {
            throw new ApiProblem(409, 'conflict', 'No se evalúa ahora', $d->status === 'planned' ? 'Primero registra la ejecución del simulacro.' : 'El simulacro ya fue evaluado o está cancelado.');
        }
        $v = $request->validate([
            'criteria' => ['array', 'max:50'],
            'criteria.*.name' => ['required', 'string', 'max:200'],
            'criteria.*.met' => ['nullable', 'boolean'],
            'criteria.*.note' => ['nullable', 'string', 'max:500'],
            'observations' => ['nullable', 'string', 'max:4000'],
        ]);
        $criteria = array_map(fn ($c) => ['name' => $c['name'], 'met' => $c['met'] ?? null, 'note' => $c['note'] ?? null], $v['criteria'] ?? []);
        $failed = array_map(fn ($c) => $c['name'].($c['note'] ? " ({$c['note']})" : ''), array_filter($criteria, fn ($c) => $c['met'] === false));
        $evaluation = ['criteria' => $criteria, 'observations' => trim((string) ($v['observations'] ?? '')) ?: null, 'not_met' => array_values($failed)];
        DB::update('update drills set evaluation = ?::jsonb, updated_by = ?, updated_at = now() where id = ?',
            [json_encode($evaluation, JSON_UNESCAPED_UNICODE), (string) auth()->id(), $d->id]);

        return response()->json(['data' => $this->payload($this->drill($id))]);
    }

    /** GET /sim/drills/{id}/report — datos del informe de simulacro para imprimir (M-SIM §salidas «informe de simulacro»). */
    public function report(string $id): JsonResponse
    {
        $this->can('sim.drills.read');
        $d = $this->drill($id);
        $c = DB::selectOne('select legal_name, trade_name, ruc from companies where id = ?', [$d->company_id]);
        $present = DB::select("select w.full_name, p.role from drill_participants p join workers w on w.id = p.worker_id
                                where p.drill_id = ? and p.attended and p.deleted_at is null order by w.full_name", [$d->id]);

        return response()->json(['data' => $this->payload($d) + [
            'company' => ['name' => $c->trade_name ?: $c->legal_name, 'legal_name' => $c->legal_name, 'ruc' => trim((string) $c->ruc)],
            'details' => ['drill_type' => $d->drill_type, 'scenario' => $d->scenario, 'objectives' => $d->objectives, 'is_announced' => $d->is_announced,
                'executed_at' => $d->executed_at, 'result' => $d->result, 'external_entity' => $d->external_entity, 'participants_count' => $d->participants_count],
            'present' => array_map(fn ($p) => ['full_name' => $p->full_name, 'role' => $p->role], $present),
        ]]);
    }

    private function payload(object $d): array
    {
        $target = $d->site_id ? DB::selectOne('select evacuation_target_seconds from sites where id = ?', [$d->site_id])?->evacuation_target_seconds : null;
        $workers = $d->site_id ? DB::select(
            "select w.id, w.full_name, '****' || w.document_last4 as document, a.name as area, p.attended, p.role
               from workers w left join areas a on a.id = w.current_area_id
               left join drill_participants p on p.drill_id = ? and p.worker_id = w.id and p.deleted_at is null
              where w.deleted_at is null and w.status = 'active' and w.current_site_id = ? order by a.name nulls last, w.full_name", [$d->id, $d->site_id]) : [];

        return [
            'drill' => ['id' => $d->id, 'code' => $d->code, 'title' => $d->title, 'drill_type' => $d->drill_type, 'status' => $d->status, 'scheduled_at' => $d->scheduled_at,
                'site' => $d->site_id ? DB::selectOne('select name from sites where id = ?', [$d->site_id])?->name : null,
                'alarm_time' => $d->alarm_time, 'evacuation_complete_time' => $d->evacuation_complete_time, 'evacuation_time_seconds' => $d->evacuation_time_seconds === null ? null : (int) $d->evacuation_time_seconds],
            'target_seconds' => $target === null ? null : (int) $target,
            'met' => $target !== null && $d->evacuation_time_seconds !== null ? (int) $d->evacuation_time_seconds <= (int) $target : null,
            'workers' => array_map(fn ($w) => ['id' => $w->id, 'full_name' => $w->full_name, 'document' => $w->document, 'area' => $w->area,
                'attended' => $w->attended === null ? null : (bool) $w->attended, 'role' => $w->role], $workers),
            'criteria' => array_values(array_filter((array) (json_decode(\App\Core\Support\LegalParams::text('drill_evaluation_criteria', '[]'), true) ?: []), 'is_string')),
            'evaluation' => $d->evaluation ? json_decode((string) $d->evaluation, true) : null,
            'not_participated' => $d->status === 'planned' ? [] : array_values(array_map(fn ($w) => $w->full_name, array_filter($workers, fn ($w) => ! $w->attended))),
        ];
    }

    private function drill(string $id): object
    {
        return (Str::isUuid($id) ? DB::selectOne('select * from drills where id = ? and deleted_at is null', [$id]) : null) ?? throw ApiProblem::notFound('El simulacro no existe.');
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede registrar la ejecución del simulacro.');
        }
    }
}
