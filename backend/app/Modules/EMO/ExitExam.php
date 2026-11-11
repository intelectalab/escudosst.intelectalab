<?php

namespace App\Modules\EMO;

use App\Core\Support\LegalParams;
use Illuminate\Support\Facades\DB;

/**
 * EMO de retiro al cese (M-TRB §7 «Cese» paso 3, M-EMO RN-EMO-08): al registrarse el cese de un trabajador, si el
 * parámetro `emo_on_termination` está activo y la empresa tiene el módulo EMO habilitado, se genera la orden de examen
 * de tipo retiro (`exit`) en la base principal, sin datos clínicos y sin fecha: el área de salud la agenda en EMO. No se
 * duplica si el trabajador ya tiene una orden de retiro abierta. Avisa en la app al médico ocupacional y al personal de
 * salud de la empresa (sin datos médicos).
 */
final class ExitExam
{
    /** @return string|null id de la orden creada (null si no corresponde o ya existía) */
    public static function onTermination(string $workerId): ?string
    {
        $w = DB::selectOne('select id, company_id, full_name, status, termination_on, current_position_id from workers where id = ? and deleted_at is null', [$workerId]);
        if (! $w || $w->status !== 'terminated' || ! LegalParams::flag('emo_on_termination', true)) {
            return null;
        }
        if (! DB::selectOne("select 1 as ok from feature_flags where company_id = ? and module_code = 'EMO' and mode = 'enabled' and deleted_at is null", [$w->company_id])) {
            return null;
        }
        if (DB::selectOne("select 1 as ok from medical_exam_orders where worker_id = ? and exam_type = 'exit' and deleted_at is null and status not in ('cancelled', 'no_show')", [$workerId])) {
            return null;
        }
        $protocol = $w->current_position_id ? DB::selectOne('select emo_protocol_code from positions where id = ?', [$w->current_position_id])?->emo_protocol_code : null;
        $me = auth()->id() !== null ? (string) auth()->id() : null;
        $id = DB::selectOne(
            "insert into medical_exam_orders (company_id, worker_id, exam_type, position_id, protocol_code, status, requested_by, created_by, updated_by)
             values (?, ?, 'exit', ?, ?, 'scheduled', ?, ?, ?) returning id",
            [$w->company_id, $workerId, $w->current_position_id, $protocol, $me, $me, $me])->id;

        $to = DB::select(
            "select distinct m.user_id from memberships m join roles r on r.id = m.role_id
              where m.company_id = ? and coalesce(r.base_role_code, r.code) in ('occupational_doctor', 'health_staff') and m.status = 'active' and m.deleted_at is null and m.user_id is not null",
            [$w->company_id]);
        $when = $w->termination_on ? date('d/m/Y', strtotime((string) $w->termination_on)) : null;
        foreach ($to as $u) {
            DB::insert(
                "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, dedupe_key)
                 values (?, 'emo.exit_order_created', 'medical_exam_orders', ?, ?, 'EMO de retiro por agendar', ?, '/m/emo', 'normal', ?)",
                [$w->company_id, $id, $u->user_id,
                    mb_substr("Cese de {$w->full_name}".($when ? " el {$when}" : '').': se generó su orden de examen médico de retiro. Agéndala en Exámenes médicos.', 0, 500),
                    "emo.exit_order.{$id}.{$u->user_id}"]);
        }

        return $id;
    }
}
