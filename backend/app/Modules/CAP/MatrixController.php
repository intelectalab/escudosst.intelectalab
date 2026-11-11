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
}
