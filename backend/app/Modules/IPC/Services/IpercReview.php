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
        try {
            $lead = $m->area_id ? DB::selectOne('select w.user_id from areas a join workers w on w.id = a.manager_worker_id where a.id = ?', [$m->area_id])?->user_id : null;
            Notify::users([...Notify::roles(['sst_manager']), $lead], 'ipc.review_required', 'IPC', $m->id, 'IPERC por revisar',
                "La matriz {$m->code} · {$m->title} requiere revisión: {$reason}.", '/m/ipc?t=matrices&matriz='.$m->id, 'high', 'ipc.review_required:'.$m->id.':'.$sourceId);
        } catch (\Throwable) {
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
