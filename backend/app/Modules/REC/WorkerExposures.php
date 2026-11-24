<?php

namespace App\Modules\REC;

use App\Core\Support\LegalParams;
use Illuminate\Support\Facades\DB;

/**
 * M-MAG RN-MAG-06 / §12: marca de exposición por trabajador (worker_exposures), solo ocupacional y sin datos médicos.
 * - sync(): al guardar o anular una medición, sus marcas se anulan y, si está aprobada y sobre el límite, se vuelven a marcar los
 *   expuestos: el trabajador de la medición personal o los activos del puesto (o, sin puesto, del área) del punto, con el agente,
 *   el nivel, la medición y la vigencia (monitoring_frequency_months vigente a la fecha medida).
 * - active(): marcas vigentes (para /mag/exposed, EMO y VMD), filtrables por trabajador, puesto o código de agente, dentro del
 *   alcance por sede y área del acceso.
 */
class WorkerExposures
{
    public static function sync(string $measurementId): void
    {
        DB::update("update worker_exposures set status = 'void', updated_at = now() where measurement_id = ? and status = 'active'", [$measurementId]);
        $m = DB::selectOne("select m.*, p.site_id, p.area_id as point_area_id, p.position_id as point_position_id from measurements m
                              join monitoring_points p on p.id = m.monitoring_point_id where m.id = ?", [$measurementId]);
        if (! $m || $m->deleted_at !== null || ($m->status ?? null) !== 'approved' || $m->result !== 'exceeds_limit') {
            return;
        }
        $on = DB::selectOne("select (?::timestamptz at time zone 'America/Lima')::date as d", [$m->measured_at])->d;
        $months = (int) LegalParams::number('monitoring_frequency_months', 12, $on);
        DB::insert(
            "insert into worker_exposures (company_id, worker_id, measurement_id, monitoring_point_id, agent_type_id, site_id, area_id, position_id, level,
                                           ratio_to_limit, personal, measured_at, valid_from, valid_until)
             select ?, w.id, ?, ?, ?, ?, w.current_area_id, w.current_position_id, 'exceeds_limit', ?, ?::uuid is not null, ?, ?::date, (?::date + make_interval(months => ?::int))::date
               from workers w
              where w.deleted_at is null and w.status = 'active'
                and (w.id = ?::uuid or (?::uuid is null and ((?::uuid is not null and w.current_position_id = ?::uuid)
                     or (?::uuid is null and ?::uuid is not null and w.current_area_id = ?::uuid))))
             on conflict (company_id, measurement_id, worker_id) do update set status = 'active', ratio_to_limit = excluded.ratio_to_limit, measured_at = excluded.measured_at,
                valid_from = excluded.valid_from, valid_until = excluded.valid_until, area_id = excluded.area_id, position_id = excluded.position_id,
                agent_type_id = excluded.agent_type_id, site_id = excluded.site_id, personal = excluded.personal, updated_at = now()",
            [$m->company_id, $m->id, $m->monitoring_point_id, $m->agent_type_id, $m->site_id, $m->ratio_to_limit, $m->worker_id, $m->measured_at, $on, $on, $months,
                $m->worker_id, $m->worker_id, $m->point_position_id, $m->point_position_id, $m->point_position_id, $m->point_area_id, $m->point_area_id]
        );
    }

    /**
     * Marcas vigentes (valid_until >= hoy), la más reciente por trabajador y agente.
     *
     * @param  array{worker_id?: string, position_id?: string, agent_code?: string, worker_ids?: array<string>}  $f
     */
    public static function active(array $f = []): array
    {
        $where = ["e.status = 'active'", 'e.valid_until >= current_date', 'w.deleted_at is null'];
        $bind = [];
        if (! empty($f['worker_id'])) {
            $where[] = 'e.worker_id = ?';
            $bind[] = $f['worker_id'];
        }
        if (! empty($f['position_id'])) {
            $where[] = 'w.current_position_id = ?';
            $bind[] = $f['position_id'];
        }
        if (! empty($f['agent_code'])) {
            $where[] = 'lower(a.code) = lower(?)';
            $bind[] = $f['agent_code'];
        }
        if (isset($f['worker_ids'])) {
            $where[] = 'e.worker_id = any(?::uuid[])';
            $bind[] = '{'.implode(',', $f['worker_ids']).'}';
        }

        return DB::select(
            'select distinct on (e.worker_id, e.agent_type_id) e.id, e.worker_id, w.full_name, w.current_position_id, ps.name as position, ar.name as area, s.name as site,
                    e.agent_type_id, a.code as agent_code, a.name as agent, a.family as agent_family, p.name as point, e.measurement_id, e.level,
                    e.ratio_to_limit, e.personal, e.measured_at, e.valid_from, e.valid_until, m.value, m.unit
               from worker_exposures e join workers w on w.id = e.worker_id join agent_types a on a.id = e.agent_type_id
               join monitoring_points p on p.id = e.monitoring_point_id join measurements m on m.id = e.measurement_id
               left join positions ps on ps.id = w.current_position_id left join areas ar on ar.id = w.current_area_id left join sites s on s.id = e.site_id
              where '.implode(' and ', $where).\App\Core\Auth\Scope::andSql('e.site_id', 'e.area_id').'
              order by e.worker_id, e.agent_type_id, e.measured_at desc', $bind);
    }
}
