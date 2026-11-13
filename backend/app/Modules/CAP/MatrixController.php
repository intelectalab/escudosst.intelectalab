<?php

namespace App\Modules\CAP;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GET /cap/matrix — matriz de capacitación por cargo (M-CAP, SCR-CAP-07/08): por cada cargo y capacitación exigida,
 * cuántos trabajadores activos del cargo la tienen vigente (certificado validado no vencido o asistencia dentro del
 * plazo de renovación), y los cuatro indicadores de la cabecera del módulo.
 */
class MatrixController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $today = DB::selectOne("select (now() at time zone 'America/Lima')::date as d")->d;
        $year = (int) substr($today, 0, 4);

        $cells = DB::select(
            "select q.position_id, q.training_id, q.requirement_level as level, count(w.id) as total,
                    count(w.id) filter (where exists (
                        select 1 from training_certificates c where c.worker_id = w.id and c.training_id = q.training_id and c.deleted_at is null
                           and c.status = 'validated' and (c.expires_on is null or c.expires_on >= ?::date))
                      or exists (
                        select 1 from training_attendances a join training_sessions s on s.id = a.session_id
                         where a.worker_id = w.id and s.training_id = q.training_id and a.deleted_at is null and s.deleted_at is null and a.attended
                           and (q.renewal_months is null or (s.starts_at at time zone 'America/Lima')::date >= ?::date - make_interval(months => q.renewal_months)))) as done
               from position_training_requirements q
               left join workers w on w.current_position_id = q.position_id and w.deleted_at is null and w.status = 'active'
              where q.deleted_at is null and coalesce(q.is_active, true) and q.training_id is not null
              group by q.position_id, q.training_id, q.requirement_level", [$today, $today]);

        $posIds = array_values(array_unique(array_column($cells, 'position_id')));
        $trIds = array_values(array_unique(array_column($cells, 'training_id')));
        $positions = $posIds ? DB::select(
            "select p.id, p.name, (select count(*) from workers w where w.current_position_id = p.id and w.deleted_at is null and w.status = 'active') as workers
               from positions p where p.id = any(?::uuid[]) order by p.name", ['{'.implode(',', $posIds).'}']) : [];
        $trainings = $trIds ? DB::select('select id, code, title from trainings where id = any(?::uuid[]) order by title', ['{'.implode(',', $trIds).'}']) : [];

        $mandatory = array_filter($cells, fn ($c) => $c->level !== 'suggested');
        $den = array_sum(array_map(fn ($c) => (int) $c->total, $mandatory));
        $num = array_sum(array_map(fn ($c) => (int) $c->done, $mandatory));
        $workers = (int) DB::selectOne("select count(*) as n from workers where deleted_at is null and status = 'active'")->n;
        $hours = (float) DB::selectOne(
            "select coalesce(sum(coalesce(a.hours, extract(epoch from (s.ends_at - s.starts_at)) / 3600)), 0) as h
               from training_attendances a join training_sessions s on s.id = a.session_id
              where a.deleted_at is null and s.deleted_at is null and a.attended and a.worker_id is not null
                and extract(year from s.starts_at at time zone 'America/Lima') = ?", [$year])->h;
        $certs = DB::selectOne(
            "select count(*) filter (where status = 'pending_validation') as pending,
                    count(*) filter (where status = 'validated' and expires_on between ?::date and ?::date + 30) as expiring
               from training_certificates where deleted_at is null", [$today, $today]);

        return response()->json(['data' => [
            'positions' => array_map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'workers' => (int) $p->workers], $positions),
            'trainings' => $trainings,
            'cells' => array_map(fn ($c) => ['position_id' => $c->position_id, 'training_id' => $c->training_id, 'level' => $c->level,
                'total' => (int) $c->total, 'done' => (int) $c->done], $cells),
            'kpis' => [
                'hours_per_worker' => $workers ? round($hours / $workers, 1) : null,
                'matrix_pct' => $den ? (int) round($num * 100 / $den) : null,
                'matrix_num' => $num,
                'matrix_den' => $den,
                'certificates_pending' => (int) $certs->pending,
                'certificates_expiring' => (int) $certs->expiring,
            ],
        ]]);
    }

    /**
     * GET /cap/compliance?site_id=&area_id=&position_id= — SCR-CAP-08: trabajadores × temas que exige su cargo con el semáforo
     * de RN-CAP-05: vigente, por vencer (training_expiry_notice_days), vencido, faltante, o en plazo si aún corre su plazo de ingreso.
     */
    public function compliance(\Illuminate\Http\Request $request): JsonResponse
    {
        $f = $request->validate(['site_id' => ['nullable', 'uuid'], 'area_id' => ['nullable', 'uuid'], 'position_id' => ['nullable', 'uuid']]);
        $today = DB::selectOne("select (now() at time zone 'America/Lima')::date as d")->d;
        $soon = (int) \App\Core\Support\LegalParams::number('training_expiry_notice_days', 30);
        $where = ["w.deleted_at is null", "w.status = 'active'"];
        $bind = [$today, $soon, $today, $today];
        foreach (['site_id' => 'current_site_id', 'area_id' => 'current_area_id', 'position_id' => 'current_position_id'] as $k => $col) {
            if (! empty($f[$k])) {
                $where[] = "w.{$col} = ?";
                $bind[] = $f[$k];
            }
        }
        $rows = DB::select(
            "select w.id as worker_id, w.full_name, p.name as position, a.name as area, s.name as site, t.id as training_id, t.title, t.course_id,
                    c.expires_on, c.status as cert_status,
                    case when c.id is not null and (c.expires_on is null or c.expires_on > ?::date + ?::int) then 'valid'
                         when c.id is not null and c.expires_on >= ?::date then 'expiring'
                         when x.id is not null then 'expired'
                         when q.due_within_days is not null and w.hire_on is not null and w.hire_on + q.due_within_days >= ?::date then 'in_window'
                         else 'missing' end as state
               from workers w
               join position_training_requirements q on q.position_id = w.current_position_id and q.deleted_at is null and coalesce(q.is_active, true)
                and q.training_id is not null and q.requirement_level <> 'suggested'
               join trainings t on t.id = q.training_id and t.deleted_at is null
               left join positions p on p.id = w.current_position_id
               left join areas a on a.id = w.current_area_id
               left join sites s on s.id = w.current_site_id
               left join lateral (select id, expires_on, status from training_certificates where worker_id = w.id and training_id = t.id and deleted_at is null
                                    and status = 'validated' order by expires_on desc nulls first limit 1) c on true
               left join lateral (select id from training_certificates where worker_id = w.id and training_id = t.id and deleted_at is null and status in ('validated', 'expired')
                                    limit 1) x on true
              where ".implode(' and ', $where).'
              order by w.full_name, t.title limit 3000',
            $bind
        );
        $workers = [];
        foreach ($rows as $r) {
            $workers[$r->worker_id] ??= ['id' => $r->worker_id, 'name' => $r->full_name, 'position' => $r->position, 'area' => $r->area, 'site' => $r->site, 'items' => []];
            $workers[$r->worker_id]['items'][] = ['training_id' => $r->training_id, 'title' => $r->title, 'course_id' => $r->course_id, 'state' => $r->state, 'expires_on' => $r->expires_on];
        }
        $count = array_count_values(array_column($rows, 'state'));
        $sessions = DB::select("select s.id, s.training_id, s.starts_at, s.capacity, (select count(*) from training_attendances x where x.session_id = s.id and x.deleted_at is null) as invited
                                  from training_sessions s where s.deleted_at is null and s.status = 'scheduled' and s.starts_at > now() order by s.starts_at limit 300");

        return response()->json(['data' => ['workers' => array_values($workers), 'counts' => $count, 'sessions' => $sessions]]);
    }

    /** POST /cap/sessions/{id}/invite {worker_ids[]} — «Convocar» desde el cumplimiento: los agrega a la sesión programada (con sus reglas). */
    public function invite(\Illuminate\Http\Request $request, string $id, \App\Modules\REC\RecordsService $records): JsonResponse
    {
        if (! in_array('cap.training_attendances.create', \App\Core\Auth\Permissions::effective(), true)) {
            throw \App\Core\Http\ApiProblem::forbidden('Tu rol no convoca a capacitaciones.');
        }
        $d = $request->validate(['worker_ids' => ['required', 'array', 'min:1', 'max:300'], 'worker_ids.*' => ['uuid']], [], ['worker_ids' => 'trabajadores']);
        $s = DB::selectOne("select id, capacity, status from training_sessions where id = ? and deleted_at is null", [$id]);
        if (! $s || $s->status !== 'scheduled') {
            throw new \App\Core\Http\ApiProblem(409, 'conflict', 'Sesión no disponible', 'Solo se convoca a una sesión programada.');
        }
        $res = \App\Modules\REC\Registry::resource('CAP', 'training_attendances');
        $added = 0;
        $errors = [];
        foreach ($d['worker_ids'] as $w) {
            if (DB::selectOne('select 1 as ok from training_attendances where session_id = ? and worker_id = ? and deleted_at is null', [$id, $w])) {
                continue;
            }
            if ($s->capacity && (int) DB::selectOne('select count(*) as n from training_attendances where session_id = ? and deleted_at is null', [$id])->n >= (int) $s->capacity) {
                $errors[] = 'La sesión llegó a su aforo.';
                break;
            }
            try {
                $records->create($res, ['session_id' => $id, 'worker_id' => $w, 'attended' => false]);
                $added++;
            } catch (\Throwable $e) {
                $name = DB::selectOne('select full_name from workers where id = ?', [$w])?->full_name ?? 'Trabajador';
                $errors[] = $name.': '.match (true) {
                    $e instanceof \App\Core\Http\ApiProblem => collect($e->extra['errors'] ?? [])->flatten()->first() ?? ($e->detail ?? $e->title),
                    $e instanceof \Illuminate\Validation\ValidationException => collect($e->errors())->flatten()->first(),
                    default => 'no se pudo convocar ('.class_basename($e).': '.mb_substr($e->getMessage(), 0, 120).')',
                };
            }
        }

        return response()->json(['data' => ['invited' => $added, 'errors' => $errors]]);
    }
}
