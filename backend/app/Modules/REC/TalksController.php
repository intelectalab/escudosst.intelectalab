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
 *  GET  /ch5/topics?area_id=       SCR-CH5-03: banco de temas y temas sugeridos según los peligros significativos del IPERC vigente
 *                                    del área (RN-CH5-08: un tema se sugiere si alguna de sus palabras de peligro aparece en el peligro)
 *  POST /ch5/topics                 crear o editar un tema {id?, title, content?, hazard_keywords[], duration_minutes?, is_active}
 *  GET  /ch5/coverage?date=          SCR-CH5-01 / RN-CH5-05: cobertura del día por área = trabajadores del área con asistencia
 *                                    registrada en una charla de ese día / trabajadores activos del área.
 *  GET  /ch5/talks/{id}/attendances SCR-CH5-02: asistentes de la charla con su firma, para la firma asistida uno tras otro
 *  POST /ch5/attendances/{id}/sign   firma asistida del asistente ante quien da la charla (M-FIR RN-FIR-05): {assisted, dni + pin | image}
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
            'topic_bank_id' => ['nullable', 'uuid'],
            'photo_file_id' => ['nullable', 'uuid'],
            'worker_ids' => ['required', 'array', 'min:1', 'max:500'],
            'worker_ids.*' => ['uuid'],
        ], ['topic.required' => 'Escribe el tema de la charla.', 'worker_ids.required' => 'Marca al menos un asistente.', 'worker_ids.min' => 'Marca al menos un asistente.',
            'site_id.required' => 'Elige la sede.', 'area_id.required' => 'Elige el área.']);
        $active = array_column(DB::select("select id from workers where deleted_at is null and status = 'active' and id = any(?::uuid[])",
            ['{'.implode(',', array_unique($d['worker_ids'])).'}']), 'id');
        if (count($active) !== count(array_unique($d['worker_ids']))) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Hay asistentes que no son trabajadores activos.', ['errors' => ['worker_ids' => ['Hay asistentes que no son trabajadores activos.']]]);
        }
        // SCR-CH5-02: foto de grupo (archivo ya subido de la empresa).
        if (! empty($d['photo_file_id']) && ! DB::selectOne("select 1 as ok from files where id = ? and status = 'available'", [$d['photo_file_id']])) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'La foto de grupo no se subió bien: vuelve a tomarla.', ['errors' => ['photo_file_id' => ['La foto de grupo no se subió bien.']]]);
        }
        $talkRes = Registry::resource('CH5', 'safety_talks');
        $attRes = Registry::resource('CH5', 'talk_attendances');
        $id = DB::transaction(function () use ($records, $talkRes, $attRes, $d, $active) {
            $talk = $records->create($talkRes, [
                'topic' => $d['topic'], 'content' => $d['content'] ?? null, 'talk_on' => $d['talk_on'], 'site_id' => $d['site_id'], 'area_id' => $d['area_id'],
                'speaker_user_id' => (string) auth()->id(), 'duration_minutes' => $d['duration_minutes'] ?? null, 'photo_file_id' => $d['photo_file_id'] ?? null,
                'topic_bank_id' => ! empty($d['topic_bank_id']) && DB::selectOne('select 1 as ok from talk_topics where id = ? and deleted_at is null', [$d['topic_bank_id']]) ? $d['topic_bank_id'] : null,
            ]);
            foreach ($active as $w) {
                // Asistencia por lista: la registra quien da la charla; cada trabajador puede firmarla después desde su app.
                $records->create($attRes, ['safety_talk_id' => $talk['id'], 'worker_id' => $w, 'method' => 'list', 'attended_at' => now()->toIso8601String()]);
            }
            // RN-CH5-01: queda programada hasta que firme al menos un asistente; con la primera firma pasa sola a «realizada».

            return $talk['id'];
        });

        return response()->json(['data' => ['id' => $id, 'attendees' => count($active)]], 201);
    }

    public function topics(Request $request): JsonResponse
    {
        $this->can('ch5.safety_talks.read');
        $topics = DB::select('select id, title, content, hazard_keywords, duration_minutes, is_active from talk_topics where deleted_at is null order by is_active desc, title');
        foreach ($topics as $t) {
            $t->hazard_keywords = $this->arr($t->hazard_keywords);
        }
        $area = (string) $request->query('area_id');
        $hazards = Str::isUuid($area) ? array_column(DB::select(
            "select distinct r.hazard from iperc_rows r join iperc_versions v on v.id = r.iperc_version_id and v.status = 'current' and v.deleted_at is null
               join iperc_matrices m on m.id = v.iperc_matrix_id and m.current_version_id = v.id and m.deleted_at is null
               left join positions ps on ps.id = coalesce(r.position_id, m.position_id)
              where r.deleted_at is null and r.is_significant and (m.area_id = ? or ps.area_id = ?) order by r.hazard", [$area, $area]), 'hazard') : [];
        $suggested = [];
        foreach ($topics as $t) {
            if (! $t->is_active) {
                continue;
            }
            $match = array_values(array_filter($hazards, fn ($h) => (bool) array_filter($t->hazard_keywords, fn ($k) => $k !== '' && mb_stripos($h, $k) !== false)));
            if ($match) {
                $suggested[] = ['id' => $t->id, 'title' => $t->title, 'hazards' => $match];
            }
        }

        return response()->json(['data' => ['topics' => $topics, 'hazards' => $hazards, 'suggested' => $suggested]]);
    }

    public function saveTopic(Request $request): JsonResponse
    {
        $this->can('ch5.safety_talks.create');
        $d = $request->validate([
            'id' => ['nullable', 'uuid'],
            'title' => ['required', 'string', 'max:300'],
            'content' => ['nullable', 'string', 'max:5000'],
            'hazard_keywords' => ['array', 'max:30'],
            'hazard_keywords.*' => ['nullable', 'string', 'max:80'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:120'],
            'is_active' => ['boolean'],
        ], ['title.required' => 'Escribe el título del tema.']);
        $kw = '{'.implode(',', array_map(fn ($k) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $k).'"', array_values(array_filter(array_map(fn ($k) => trim((string) $k), $d['hazard_keywords'] ?? []))))).'}';
        $uid = (string) auth()->id();
        if (! empty($d['id'])) {
            if (! DB::update('update talk_topics set title = ?, content = ?, hazard_keywords = ?::text[], duration_minutes = ?, is_active = ?, updated_by = ?, updated_at = now() where id = ? and deleted_at is null',
                [$d['title'], $d['content'] ?? null, $kw, $d['duration_minutes'] ?? null, $d['is_active'] ?? true, $uid, $d['id']])) {
                throw ApiProblem::notFound('El tema no existe.');
            }
            $id = $d['id'];
        } else {
            $id = DB::selectOne('insert into talk_topics (company_id, title, content, hazard_keywords, duration_minutes, is_active, created_by, updated_by) values (?, ?, ?, ?::text[], ?, ?, ?, ?) returning id',
                [app(\App\Core\Tenancy\CompanyContext::class)->companyId, $d['title'], $d['content'] ?? null, $kw, $d['duration_minutes'] ?? null, $d['is_active'] ?? true, $uid, $uid])->id;
        }

        return response()->json(['data' => ['id' => $id]], empty($d['id']) ? 201 : 200);
    }

    /** Arreglo de texto de Postgres ({a,"b c"}) a lista. */
    private function arr(?string $v): array
    {
        return $v && $v !== '{}' ? array_map(fn ($x) => stripcslashes(trim($x, '"')), str_getcsv(trim($v, '{}'))) : [];
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

    /** GET /ch5/report.xlsx?from=&to= — M-CH5 §11: charlas del periodo con tema, área, expositor, duración y asistentes con firma. */
    public function report(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $this->can('ch5.safety_talks.read');
        $from = (string) ($request->query('from') ?: now('America/Lima')->startOfMonth()->toDateString());
        $to = (string) ($request->query('to') ?: now('America/Lima')->toDateString());
        $rows = DB::select(
            "select k.talk_on, k.topic, k.status, k.duration_minutes, a.name as area, s.name as site,
                    coalesce(u.full_name, w.full_name) as speaker,
                    (select count(*) from talk_attendances t where t.safety_talk_id = k.id and t.deleted_at is null) as attendees,
                    (select count(*) from talk_attendances t where t.safety_talk_id = k.id and t.deleted_at is null and (t.signature_id is not null or t.method <> 'signature')) as signed
               from safety_talks k left join areas a on a.id = k.area_id left join sites s on s.id = k.site_id
               left join users u on u.id = k.speaker_user_id left join workers w on w.id = k.speaker_worker_id
              where k.deleted_at is null and k.talk_on between ?::date and ?::date order by k.talk_on, a.name", [$from, $to]);
        $status = ['scheduled' => 'Programada', 'held' => 'Realizada', 'cancelled' => 'Anulada'];
        $out = [['Charlas de cinco minutos'], ["Del {$from} al {$to}."], [], ['Fecha', 'Tema', 'Sede', 'Área', 'Expositor', 'Minutos', 'Asistentes', 'Con firma', 'Estado']];
        foreach ($rows as $r) {
            $out[] = [date('d/m/Y', strtotime($r->talk_on)), $r->topic, $r->site, $r->area, $r->speaker, $r->duration_minutes === null ? '' : (int) $r->duration_minutes,
                (int) $r->attendees, (int) $r->signed, $status[$r->status] ?? $r->status];
        }
        if ($rows === []) {
            $out[] = ['Sin charlas en el periodo'];
        }

        return response(\App\Core\Support\Xlsx::build('Charlas', $out, 4), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"charlas-{$from}-{$to}.xlsx\"",
        ]);
    }

    public function attendances(string $id): JsonResponse
    {
        $this->can('ch5.talk_attendances.read');
        $talk = Str::isUuid($id) ? DB::selectOne('select id, topic, talk_on, status, photo_file_id from safety_talks where id = ? and deleted_at is null', [$id]) : null;
        if (! $talk) {
            throw ApiProblem::notFound('La charla no existe.');
        }
        $rows = DB::select(
            "select t.id, t.worker_id, coalesce(w.full_name, trim(concat_ws(' ', cw.first_name, cw.paternal_last_name, cw.maternal_last_name))) as full_name, coalesce(w.document_last4, cw.document_last4) as document_last4,
                    t.signature_id is not null as signed, s.method as signature_method, s.signed_at
               from talk_attendances t left join workers w on w.id = t.worker_id left join contractor_workers cw on cw.id = t.contractor_worker_id
               left join signatures s on s.id = t.signature_id
              where t.safety_talk_id = ? and t.deleted_at is null order by (t.signature_id is not null), 3", [$id]);

        return response()->json(['data' => ['talk' => $talk, 'attendances' => $rows]]);
    }

    public function sign(Request $request, string $id): JsonResponse
    {
        $this->can('ch5.talk_attendances.create');
        $a = Str::isUuid($id) ? DB::selectOne(
            'select t.id, t.worker_id, t.signature_id, k.status as talk_status from talk_attendances t join safety_talks k on k.id = t.safety_talk_id
              where t.id = ? and t.deleted_at is null and k.deleted_at is null for update of t', [$id]) : null;
        if (! $a) {
            throw ApiProblem::notFound('La asistencia no existe.');
        }
        if ($a->signature_id) {
            throw new ApiProblem(409, 'conflict', 'Ya firmó', 'Este asistente ya firmó la charla.');
        }
        if ($a->talk_status === 'cancelled') {
            throw new ApiProblem(409, 'conflict', 'Charla anulada', 'La charla está anulada: no se firma.');
        }
        if (! $a->worker_id) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'La firma asistida es para trabajadores de la empresa.', ['errors' => ['worker_id' => ['La firma asistida es para trabajadores de la empresa.']]]);
        }
        $sig = DB::transaction(function () use ($request, $a) {
            $sig = \App\Modules\FIR\AssistedSignature::sign($request, 'talk_attendances', $a->id, $a->worker_id, 'attendee');
            DB::update("update talk_attendances set signature_id = ?, method = 'signature', updated_by = ?, updated_at = now() where id = ?", [$sig, (string) auth()->id(), $a->id]);
            // RN-CH5-01: con la primera firma de un asistente, la charla programada pasa a «realizada».
            DB::update("update safety_talks set status = 'held', updated_at = now() where status = 'scheduled' and id = (select safety_talk_id from talk_attendances where id = ?)", [$a->id]);

            return $sig;
        });

        return response()->json(['data' => ['signature_id' => $sig]], 201);
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede hacer esto con las charlas.');
        }
    }
}
