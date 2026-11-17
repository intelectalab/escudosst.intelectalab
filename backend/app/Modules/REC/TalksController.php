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
 * Charlas de 5 minutos (M-CH5):
 *  GET  /ch5/area-workers?area_id=   SCR-CH5-02: trabajadores activos del área precargados (RN-CH5-03)
 *  POST /ch5/quick                   SCR-CH5-02: registra la charla con su asistencia en un solo paso y la marca «realizada»
 *                                    (RN-CH5-01: tema, expositor, fecha y al menos un asistente). Usa el mismo servicio de registros,
 *                                    así se aplican sus reglas, avisos y bitácora.
 *  GET  /ch5/coverage?date=          SCR-CH5-01 / RN-CH5-05: cobertura del día por área = trabajadores del área con asistencia
 *                                    registrada en una charla de ese día / trabajadores activos del área.
 */
class TalksController extends Controller
{
    public function areaWorkers(Request $request): JsonResponse
    {
        $this->can('ch5.talk_attendances.read');
        $area = (string) $request->query('area_id');
        if (! Str::isUuid($area)) {
            throw ApiProblem::badRequest('Elige el área.');
        }

        return response()->json(['data' => DB::select(
            "select w.id, w.full_name, '****' || w.document_last4 as document, p.name as position
               from workers w left join positions p on p.id = w.current_position_id
              where w.deleted_at is null and w.status = 'active' and w.current_area_id = ? order by w.full_name", [$area])]);
    }

    public function quick(Request $request, RecordsService $records): JsonResponse
    {
        $this->can('ch5.safety_talks.create');
        $this->can('ch5.talk_attendances.create');
        $d = $request->validate([
            'topic' => ['required', 'string', 'max:300'],
            'content' => ['nullable', 'string', 'max:5000'],
            'talk_on' => ['required', 'date_format:Y-m-d'],
            'site_id' => ['required', 'uuid'],
            'area_id' => ['required', 'uuid'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:120'],
            'worker_ids' => ['required', 'array', 'min:1', 'max:500'],
            'worker_ids.*' => ['uuid'],
        ], ['topic.required' => 'Escribe el tema de la charla.', 'worker_ids.required' => 'Marca al menos un asistente.', 'worker_ids.min' => 'Marca al menos un asistente.',
            'site_id.required' => 'Elige la sede.', 'area_id.required' => 'Elige el área.']);
        $active = array_column(DB::select("select id from workers where deleted_at is null and status = 'active' and id = any(?::uuid[])",
            ['{'.implode(',', array_unique($d['worker_ids'])).'}']), 'id');
        if (count($active) !== count(array_unique($d['worker_ids']))) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Hay asistentes que no son trabajadores activos.', ['errors' => ['worker_ids' => ['Hay asistentes que no son trabajadores activos.']]]);
        }
        $talkRes = Registry::resource('CH5', 'safety_talks');
        $attRes = Registry::resource('CH5', 'talk_attendances');
        $id = DB::transaction(function () use ($records, $talkRes, $attRes, $d, $active) {
            $talk = $records->create($talkRes, [
                'topic' => $d['topic'], 'content' => $d['content'] ?? null, 'talk_on' => $d['talk_on'], 'site_id' => $d['site_id'], 'area_id' => $d['area_id'],
                'speaker_user_id' => (string) auth()->id(), 'duration_minutes' => $d['duration_minutes'] ?? null,
            ]);
            foreach ($active as $w) {
                // Asistencia por lista: la registra quien da la charla; cada trabajador puede firmarla después desde su app.
                $records->create($attRes, ['safety_talk_id' => $talk['id'], 'worker_id' => $w, 'method' => 'list', 'attended_at' => now()->toIso8601String()]);
            }
            $records->update($talkRes, $talk['id'], ['status' => 'held']);

            return $talk['id'];
        });

        return response()->json(['data' => ['id' => $id, 'attendees' => count($active)]], 201);
    }

    public function coverage(Request $request): JsonResponse
    {
        $this->can('ch5.safety_talks.read');
        $date = (string) $request->query('date', now('America/Lima')->format('Y-m-d'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw ApiProblem::badRequest('Elige una fecha válida.');
        }
        $rows = DB::select(
            "select a.id, a.name as area, s.name as site,
                    (select count(*) from workers w where w.current_area_id = a.id and w.status = 'active' and w.deleted_at is null) as workers,
                    (select count(distinct t.worker_id) from talk_attendances t join safety_talks k on k.id = t.safety_talk_id
                       join workers w on w.id = t.worker_id and w.current_area_id = a.id and w.status = 'active' and w.deleted_at is null
                      where k.talk_on = ?::date and k.status <> 'cancelled' and k.deleted_at is null and t.deleted_at is null
                        and (t.signature_id is not null or t.method <> 'signature')) as attended,
                    (select count(*) from safety_talks k where k.area_id = a.id and k.talk_on = ?::date and k.status <> 'cancelled' and k.deleted_at is null) as talks
               from areas a left join sites s on s.id = a.site_id
              where a.deleted_at is null and coalesce(a.status, 'active') = 'active' order by s.name nulls last, a.name", [$date, $date]);
        foreach ($rows as $r) {
            $r->workers = (int) $r->workers;
            $r->attended = (int) $r->attended;
            $r->talks = (int) $r->talks;
            $r->pct = $r->workers ? (int) round($r->attended * 100 / $r->workers) : null;
        }

        return response()->json(['data' => ['date' => $date, 'areas' => array_values(array_filter($rows, fn ($r) => $r->workers > 0))]]);
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede hacer esto con las charlas.');
        }
    }
}
