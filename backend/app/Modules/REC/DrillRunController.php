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
