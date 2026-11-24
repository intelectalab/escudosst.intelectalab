<?php

namespace App\Modules\COM;

use Illuminate\Support\Facades\DB;

/**
 * M-COM §11: informe anual del comité (o supervisor) SST para la revisión por la dirección (RVD).
 * Con los registros del año: integrantes, sesiones programadas y realizadas (con quórum y acta firmada), asistencia de
 * cada integrante, acuerdos y su estado, e inspecciones con participación del comité. Solo datos del sistema.
 */
final class CommitteeAnnualReport
{
    /** Sesiones que cuentan como realizadas (M-COM §9). */
    public const HELD = "('held', 'minutes_draft', 'minutes_signed')";

    public const MEETING_TYPE = ['ordinary' => 'Ordinaria', 'extraordinary' => 'Extraordinaria', 'installation' => 'Instalación'];

    public const MEETING_STATUS = ['scheduled' => 'Programada', 'held' => 'Realizada', 'minutes_draft' => 'Acta en borrador', 'minutes_signed' => 'Acta firmada', 'cancelled' => 'Cancelada'];

    public const AGREEMENT_STATUS = ['agreed' => 'Acordado', 'in_progress' => 'En curso', 'fulfilled' => 'Cumplido', 'overdue' => 'Vencido', 'cancelled' => 'Anulado'];

    /** Fecha de la sesión que cuenta para el año: la realizada o, si no se hizo, la programada (Lima). */
    private const MEETING_DATE = "(coalesce(m.held_at, m.scheduled_at) at time zone 'America/Lima')::date";

    public static function data(object $c, int $year): array
    {
        $from = "{$year}-01-01";
        $to = "{$year}-12-31";
        $members = DB::select(
            "select cm.id, w.full_name, p.name as position, cm.representation, cm.member_type, cm.role_in_committee, cm.starts_on, cm.ends_on, cm.status
               from committee_members cm join workers w on w.id = cm.worker_id left join positions p on p.id = w.current_position_id
              where cm.committee_id = ? and cm.deleted_at is null
                and (cm.starts_on is null or cm.starts_on <= ?::date) and (cm.ends_on is null or cm.ends_on >= ?::date)
              order by case cm.role_in_committee when 'president' then 0 when 'secretary' then 1 else 2 end, cm.member_type desc, cm.representation, w.full_name",
            [$c->id, $to, $from]);
        $meetings = DB::select(
            'select m.id, m.number, m.meeting_type, m.scheduled_at, m.held_at, m.status, m.quorum_reached, m.attendance,
                    (select count(*) from meeting_agreements a where a.meeting_id = m.id and a.deleted_at is null and a.status <> \'cancelled\') as agreements
               from committee_meetings m where m.committee_id = ? and m.deleted_at is null and '.self::MEETING_DATE.' between ?::date and ?::date
              order by coalesce(m.held_at, m.scheduled_at), m.number', [$c->id, $from, $to]);
        $held = array_values(array_filter($meetings, fn ($m) => in_array($m->status, ['held', 'minutes_draft', 'minutes_signed'], true)));
        $present = [];
        $rows = [];
        foreach ($meetings as $m) {
            $ids = json_decode((string) ($m->attendance ?? '[]'), true);
            $ids = array_map(fn ($x) => is_array($x) ? (string) ($x['id'] ?? $x['member_id'] ?? '') : (string) $x, is_array($ids) ? $ids : []);
            $isHeld = in_array($m->status, ['held', 'minutes_draft', 'minutes_signed'], true);
            if ($isHeld) {
                foreach ($ids as $id) {
                    $present[$id] = ($present[$id] ?? 0) + 1;
                }
            }
            $rows[] = ['number' => $m->number, 'meeting_type' => $m->meeting_type, 'meeting_type_label' => self::MEETING_TYPE[$m->meeting_type] ?? $m->meeting_type,
                'scheduled_at' => $m->scheduled_at, 'held_at' => $m->held_at, 'status' => $m->status, 'status_label' => self::MEETING_STATUS[$m->status] ?? $m->status,
                'quorum_reached' => $isHeld ? (bool) $m->quorum_reached : null, 'present' => $isHeld ? count($ids) : null, 'agreements' => (int) $m->agreements];
        }
        $heldCount = count($held);
        $memberRows = array_map(fn ($x) => ['name' => $x->full_name, 'position' => $x->position, 'representation' => $x->representation, 'member_type' => $x->member_type,
            'role_in_committee' => $x->role_in_committee, 'status' => $x->status, 'attended' => $present[(string) $x->id] ?? 0,
            'attendance_pct' => $heldCount > 0 ? round(($present[(string) $x->id] ?? 0) * 100 / $heldCount, 1) : null], $members);
        $presentTotal = array_sum(array_map(fn ($r) => $r['present'] ?? 0, array_filter($rows, fn ($r) => $r['present'] !== null)));
        $agreements = DB::select(
            "select a.number, m.number as meeting_number, a.description, coalesce(u.full_name, w.full_name) as responsible, a.due_on, a.status, a.fulfilled_at,
                    ca.code as action_code
               from meeting_agreements a join committee_meetings m on m.id = a.meeting_id
               left join users u on u.id = a.responsible_user_id left join workers w on w.id = a.responsible_worker_id
               left join corrective_actions ca on ca.id = a.corrective_action_id
              where m.committee_id = ? and a.deleted_at is null and m.deleted_at is null and ".self::MEETING_DATE.' between ?::date and ?::date
              order by m.number, a.number', [$c->id, $from, $to]);
        $byStatus = array_fill_keys(array_keys(self::AGREEMENT_STATUS), 0);
        foreach ($agreements as $a) {
            $a->status_label = self::AGREEMENT_STATUS[$a->status] ?? $a->status;
            $byStatus[$a->status] = ($byStatus[$a->status] ?? 0) + 1;
        }
        $insp = DB::selectOne(
            "select count(*) as total, count(*) filter (where status in ('completed', 'signed', 'closed')) as done
               from inspections where deleted_at is null and committee_participation and status <> 'cancelled'
                and coalesce((completed_at at time zone 'America/Lima')::date, scheduled_on) between ?::date and ?::date".($c->site_id ? ' and site_id = ?' : ''),
            $c->site_id ? [$from, $to, $c->site_id] : [$from, $to]);
        $ordinaryHeldMonths = count(array_unique(array_map(fn ($m) => substr((string) self::lima($m->held_at), 0, 7),
            array_filter($held, fn ($m) => $m->meeting_type === 'ordinary' && $m->held_at))));

        return [
            'year' => $year,
            'committee' => ['name' => $c->name, 'committee_type' => $c->committee_type, 'site' => $c->site_id ? DB::selectOne('select name from sites where id = ?', [$c->site_id])?->name : null,
                'period_start_on' => $c->period_start_on, 'period_end_on' => $c->period_end_on, 'installation_on' => $c->installation_on, 'status' => $c->status],
            'summary' => [
                'meetings_planned' => count($meetings),   // todas las sesiones del año, también las que luego se cancelaron
                'meetings_held' => $heldCount,
                'meetings_cancelled' => count(array_filter($meetings, fn ($m) => $m->status === 'cancelled')),
                'meetings_pending' => count(array_filter($meetings, fn ($m) => $m->status === 'scheduled')),
                'ordinary_held' => count(array_filter($held, fn ($m) => $m->meeting_type === 'ordinary')),
                'extraordinary_held' => count(array_filter($held, fn ($m) => $m->meeting_type === 'extraordinary')),
                'months_with_ordinary' => $ordinaryHeldMonths,
                'with_quorum' => count(array_filter($held, fn ($m) => (bool) $m->quorum_reached)),
                'minutes_signed' => count(array_filter($held, fn ($m) => $m->status === 'minutes_signed')),
                'average_attendance_pct' => $heldCount > 0 && count($members) > 0 ? round($presentTotal * 100 / ($heldCount * count($members)), 1) : null,
                'agreements_total' => count(array_filter($agreements, fn ($a) => $a->status !== 'cancelled')),
                'agreements_by_status' => $byStatus,
                'inspections_with_committee' => (int) $insp->total,
                'inspections_with_committee_done' => (int) $insp->done,
            ],
            'members' => $memberRows,
            'meetings' => $rows,
            'agreements' => $agreements,
        ];
    }

    /** Lo que entra a la revisión por la dirección del periodo (RN-RVD-01): sesiones y acuerdos de los comités. */
    public static function reviewInputs(string $from, string $to): array
    {
        $s = DB::selectOne(
            "select count(*) as programadas, count(*) filter (where m.status in ".self::HELD.') as realizadas,
                    count(*) filter (where m.status in '.self::HELD." and m.quorum_reached) as con_quorum, count(*) filter (where m.status = 'minutes_signed') as actas_firmadas
               from committee_meetings m join committees c on c.id = m.committee_id and c.deleted_at is null
              where m.deleted_at is null and ".self::MEETING_DATE.' between ?::date and ?::date', [$from, $to]);
        $a = DB::selectOne(
            "select count(*) filter (where a.status <> 'cancelled') as total, count(*) filter (where a.status = 'fulfilled') as cumplidos,
                    count(*) filter (where a.status in ('agreed', 'in_progress')) as en_curso, count(*) filter (where a.status = 'overdue') as vencidos
               from meeting_agreements a join committee_meetings m on m.id = a.meeting_id and m.deleted_at is null
              where a.deleted_at is null and ".self::MEETING_DATE.' between ?::date and ?::date', [$from, $to]);
        $i = DB::selectOne("select count(*) as n from inspections where deleted_at is null and committee_participation and status in ('completed', 'signed', 'closed')
                             and (completed_at at time zone 'America/Lima')::date between ?::date and ?::date", [$from, $to]);

        return ['sesiones' => (array) $s, 'acuerdos' => (array) $a, 'inspecciones_con_comite' => (int) $i->n];
    }

    private static function lima(?string $ts): ?string
    {
        return $ts ? (new \DateTimeImmutable($ts))->setTimezone(new \DateTimeZone('America/Lima'))->format('Y-m-d') : null;
    }
}
