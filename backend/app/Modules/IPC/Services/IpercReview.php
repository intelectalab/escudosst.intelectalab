<?php

namespace App\Modules\IPC\Services;

use App\Modules\NOT\Notify;
use Illuminate\Support\Facades\DB;

/**
 * Revisión forzada del IPERC (docs/06 §2): un accidente o incidente peligroso vinculado a una fila (RN-IPC-09, RN-ACC-08)
 * o un cambio aprobado que toca la matriz (M-MOC) la pasan a «requiere revisión»; al aprobarse una nueva versión vuelve a
 * «vigente». La fecha de revisión vencida la marca la revisión periódica (RN-IPC-08).
 */
final class IpercReview
{
    public const OPEN = ['active', 'review_required', 'review_overdue'];

    public static function flag(string $matrixId, string $reason, string $sourceId): void
    {
        $m = DB::selectOne("select id, code, title, area_id, status from iperc_matrices where id = ? and deleted_at is null and status <> 'archived'", [$matrixId]);
        if (! $m) {
            return;
        }
        DB::update("update iperc_matrices set status = 'review_required', review_reason = ?, review_flagged_at = now() where id = ?", [mb_substr($reason, 0, 300), $m->id]);
        self::flagProcedures($m->id, $m->code, $sourceId);
        try {
            $lead = $m->area_id ? DB::selectOne('select w.user_id from areas a join workers w on w.id = a.manager_worker_id where a.id = ?', [$m->area_id])?->user_id : null;
            Notify::users([...Notify::roles(['sst_manager']), $lead], 'ipc.review_required', 'IPC', $m->id, 'IPERC por revisar',
                "La matriz {$m->code} · {$m->title} requiere revisión: {$reason}.", '/m/ipc?t=matrices&matriz='.$m->id, 'high', 'ipc.review_required:'.$m->id.':'.$sourceId);
        } catch (\Throwable) {
        }
    }

    /** M-PET RN-PET-05: PETS vigentes ligados a filas de esa matriz → revisión exigida hoy y aviso al Jefe SST. */
    private static function flagProcedures(string $matrixId, string $code, string $sourceId): void
    {
        $procs = DB::select(
            "select distinct p.id, p.code, p.title from procedures p join procedure_versions v on v.id = p.current_version_id
              where p.deleted_at is null and p.status = 'active' and v.iperc_row_ids is not null
                and exists (select 1 from iperc_rows r join iperc_versions iv on iv.id = r.iperc_version_id
                             where iv.iperc_matrix_id = ? and r.id = any(v.iperc_row_ids))", [$matrixId]);
        foreach ($procs as $p) {
            DB::update("update procedures set next_review_on = least(coalesce(next_review_on, current_date), current_date) where id = ?", [$p->id]);
            try {
                Notify::users(Notify::roles(['sst_manager']), 'pet.review_required', 'PET', $p->id, 'PETS por revisar',
                    "El PETS {$p->code} · {$p->title} debe revisarse: cambió la matriz IPERC {$code} con la que está ligado.", '/m/pet?t=procedures&open='.$p->id, 'normal',
                    'pet.review_required:'.$p->id.':'.$sourceId);
            } catch (\Throwable) {
            }
        }
    }

    /** Evento con fila del IPERC: accidentes e incidentes peligrosos marcan la matriz de esa fila. */
    public static function fromEvent(string $eventId): void
    {
        $e = DB::selectOne('select e.id, e.code, e.event_type, e.iperc_row_id, v.iperc_matrix_id from sst_events e
                              join iperc_rows r on r.id = e.iperc_row_id join iperc_versions v on v.id = r.iperc_version_id where e.id = ?', [$eventId]);
        if ($e && in_array($e->event_type, ['fatal_accident', 'disabling_accident', 'minor_accident', 'dangerous_incident'], true)) {
            self::flag($e->iperc_matrix_id, ($e->event_type === 'dangerous_incident' ? 'incidente peligroso ' : 'accidente ').$e->code, $e->id);
        }
    }

    /** Cambio aprobado que indica la versión del IPERC que afecta (M-MOC). */
    public static function fromChange(string $changeId): void
    {
        $c = DB::selectOne('select c.id, c.code, v.iperc_matrix_id from change_requests c join iperc_versions v on v.id = c.iperc_version_id where c.id = ?', [$changeId]);
        if ($c) {
            self::flag($c->iperc_matrix_id, 'cambio aprobado '.$c->code, $c->id);
        }
    }
}
