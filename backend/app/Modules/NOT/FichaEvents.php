<?php

namespace App\Modules\NOT;

use Illuminate\Support\Facades\DB;

/**
 * Avisos inmediatos (§10 de las fichas) que nacen al guardar un registro desde las pantallas: se llaman después
 * de las reglas del módulo. Nunca impiden guardar: si algo falla, no se avisa.
 */
final class FichaEvents
{
    /** Revisores del comité o del supervisor SST (planes y programas enviados a aprobación). */
    private const REVIEWERS = ['committee_member', 'sst_supervisor'];

    public static function after(string $op, string $table, string $id, array $values, ?object $current): void
    {
        try {
            match ($table) {
                'annual_plans' => self::entered($values, $current, 'in_review') && self::submitted($id, 'pla.submitted', 'PLA', 'Plan Anual por aprobar',
                    'select title as name from annual_plans where id = ?', '/m/pla?t=annual_plans&open='),
                'training_programs' => self::entered($values, $current, 'committee_review') && self::submitted($id, 'pca.submitted', 'PCA', 'Programa de capacitación por aprobar',
                    'select title as name from training_programs where id = ?', '/m/pca?t=training_programs&open='),
                'baseline_assessments' => self::entered($values, $current, 'completed') && self::submitted($id, 'lba.submitted', 'LBA', 'Diagnóstico de línea base por revisar',
                    'select title as name from baseline_assessments where id = ?', '/m/lba?t=baseline_assessments&open='),
                'psychosocial_surveys' => self::entered($values, $current, 'open') && self::surveyOpen($id),
                'ergonomic_assessments' => self::ergonomic($id, $values, $current),
                'procedure_versions' => self::procedureApproved($id, $values, $current),
                'change_reviews' => self::reviewAssigned($id, $values, $current),
                'workers', 'worker_assignments' => self::ppePending($table, $id, $values, $current),
                default => null,
            };
        } catch (\Throwable) {
            // El aviso nunca impide guardar el registro.
        }
    }

    private static function entered(array $values, ?object $current, string $status): bool
    {
        return ($values['status'] ?? null) === $status && $current?->status !== $status;
    }

    private static function submitted(string $id, string $event, string $module, string $title, string $sql, string $link): bool
    {
        $r = DB::selectOne($sql, [$id]);
        Notify::users(Notify::roles(self::REVIEWERS), $event, $module, $id, $title, "«{$r->name}» se envió a revisión.", $link.$id, 'normal', $event.':'.$id);

        return true;
    }

    /** M-PSI §10: encuesta abierta → trabajadores con cuenta dentro del alcance (sedes o áreas, si se indicaron). */
    private static function surveyOpen(string $id): bool
    {
        $s = DB::selectOne('select id, title, ends_on, target_scope from psychosocial_surveys where id = ?', [$id]);
        $workers = array_column(DB::select(
            "select w.id from workers w, psychosocial_surveys s
              where s.id = ? and w.status = 'active' and w.deleted_at is null
                and (jsonb_typeof(s.target_scope -> 'site_ids') is distinct from 'array' or w.current_site_id::text in (select jsonb_array_elements_text(s.target_scope -> 'site_ids')))
                and (jsonb_typeof(s.target_scope -> 'area_ids') is distinct from 'array' or w.current_area_id::text in (select jsonb_array_elements_text(s.target_scope -> 'area_ids')))",
            [$id]
        ), 'id');
        $until = $s->ends_on ? ' Tienes hasta el '.date('d/m/Y', strtotime($s->ends_on)).'.' : '';
        Notify::workers($workers, 'psi.survey_open', 'PSI', $id, 'Nueva encuesta para responder', "Ya puedes responder la encuesta «{$s->title}». Es anónima.{$until}",
            '/mi/pendientes', 'normal', 'psi.survey_open:'.$id);

        return true;
    }

    /** M-PSI §10: evaluación ergonómica con nivel alto o muy alto → Jefe SST (sin repetir si el nivel no cambia). */
    private static function ergonomic(string $id, array $values, ?object $current): bool
    {
        $level = $values['risk_level'] ?? null;
        if (! in_array($level, ['high', 'very_high'], true) || $level === $current?->risk_level) {
            return false;
        }
        $e = DB::selectOne('select e.method, p.name as position from ergonomic_assessments e left join positions p on p.id = e.position_id where e.id = ?', [$id]);
        Notify::users(Notify::roles(['sst_manager']), 'psi.ergonomic_high', 'PSI', $id, 'Evaluación ergonómica con nivel alto',
            'Una evaluación ergonómica'.($e->position ? " del puesto {$e->position}" : '').' salió con riesgo '.($level === 'very_high' ? 'muy alto' : 'alto').': revisa sus recomendaciones.',
            '/m/psi?t=ergonomic_assessments&open='.$id, 'high', 'psi.ergonomic_high:'.$id.':'.$level);

        return true;
    }

    /** M-PET §10: PETS aprobado → trabajadores con cuenta de los puestos vinculados. */
    private static function procedureApproved(string $id, array $values, ?object $current): bool
    {
        $live = ['approved', 'current'];
        if (! in_array($values['status'] ?? null, $live, true) || in_array($current?->status, $live, true)) {
            return false;
        }
        $p = DB::selectOne('select p.id, p.code, p.title, p.position_id, v.version_number from procedure_versions v join procedures p on p.id = v.procedure_id where v.id = ?', [$id]);
        if (! $p->position_id) {
            return false;
        }
        $workers = array_column(DB::select("select id from workers where current_position_id = ? and status = 'active' and deleted_at is null", [$p->position_id]), 'id');
        Notify::workers($workers, 'pet.approved', 'PET', $p->id, 'Nuevo procedimiento de tu puesto',
            "Se aprobó {$p->code} · {$p->title} (versión {$p->version_number}) para tu puesto.", '/mi', 'normal', 'pet.approved:'.$id);

        return true;
    }

    /** M-MOC §10: revisión asignada → al revisor. */
    private static function reviewAssigned(string $id, array $values, ?object $current): bool
    {
        $to = $values['reviewer_user_id'] ?? null;
        if (! $to || $to === $current?->reviewer_user_id) {
            return false;
        }
        $c = DB::selectOne('select c.id, c.code, c.title from change_reviews r join change_requests c on c.id = r.change_request_id where r.id = ?', [$id]);
        Notify::users([$to], 'moc.review_assigned', 'MOC', $c->id, 'Revisión de cambio asignada', "Te asignaron revisar el cambio {$c->code} · {$c->title}.",
            '/m/moc?t=change_requests&open='.$c->id, 'normal', 'moc.review_assigned:'.$id);

        return true;
    }

    /** M-EPP §10: trabajador nuevo o con nuevo puesto que tiene EPP obligatorio sin entregar → supervisores que ven la sede del trabajador. */
    private static function ppePending(string $table, string $id, array $values, ?object $current): bool
    {
        if ($table === 'workers') {
            $position = $values['current_position_id'] ?? null;
            if (! $position || $position === $current?->current_position_id) {
                return false;
            }
            $worker = $id;
        } else {
            if ($current || ! ($values['is_primary'] ?? true) || ! ($values['position_id'] ?? null)) {
                return false;
            }
            $worker = $values['worker_id'];
            $position = $values['position_id'];
        }
        $w = DB::selectOne("select id, full_name, current_site_id from workers where id = ? and status <> 'terminated'", [$worker]);
        if (! $w) {
            return false;
        }
        $missing = DB::select(
            "select i.name from ppe_position_requirements r join ppe_items i on i.id = r.ppe_item_id
              where r.position_id = ? and r.deleted_at is null and r.is_mandatory
                and not exists (select 1 from ppe_deliveries d where d.worker_id = ? and d.ppe_item_id = r.ppe_item_id and d.deleted_at is null and d.status = 'delivered')
              order by i.name",
            [$position, $worker]
        );
        if ($missing === []) {
            return false;
        }
        $names = implode(', ', array_slice(array_column($missing, 'name'), 0, 6)).(count($missing) > 6 ? '…' : '');
        Notify::users(Notify::siteTeam(['sst_supervisor'], $w->current_site_id), 'epp.new_worker_pending', 'EPP', $worker, 'Trabajador con EPP pendiente',
            "{$w->full_name} necesita su EPP obligatorio: {$names}.", '/m/epp?t=ppe_deliveries&worker='.$worker, 'normal', 'epp.new_worker_pending:'.$worker.':'.$position);

        return true;
    }
}
