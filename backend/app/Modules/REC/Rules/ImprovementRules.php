<?php

namespace App\Modules\REC\Rules;

use Illuminate\Support\Facades\DB;

/**
 * Mejora y planificación: PLA plan anual, AUI auditorías, RVD revisión por la dirección, MAG monitoreo de agentes,
 * MOC gestión del cambio y OBS observación de conducta (docs/modulos §8 de cada ficha).
 * - PLA: borrador → revisión → aprobado (con la sesión del comité si la empresa tiene comité) → vigente → cerrado; aprobado, sus ítems no cambian.
 * - AUI: código automático; para completarla, conclusiones; para cerrarla, sus hallazgos atendidos.
 * - RVD: para completarla, conclusiones (queda quién la aprobó).
 * - MAG: el resultado de la medición se compara con su límite de exposición.
 * - MOC: código automático; no se aprueba sin una revisión que lo apruebe; no se implementa sin la evaluación de riesgos.
 */
class ImprovementRules extends RecordRules
{
    private const PLAN_FLOW = ['draft' => ['in_review', 'approved'], 'in_review' => ['approved', 'draft'], 'approved' => ['current'], 'current' => ['closed'], 'closed' => []];

    private const MOC_FLOW = [
        'draft' => ['submitted', 'cancelled'], 'submitted' => ['under_review', 'cancelled'], 'under_review' => ['approved', 'rejected'],
        'approved' => ['implemented', 'cancelled'], 'implemented' => ['closed'], 'rejected' => [], 'closed' => [], 'cancelled' => [],
    ];

    public function prepare(string $op, array $res, array &$values, ?object $current, array &$extra): void
    {
        match ($res['table']) {
            'annual_plans' => $this->plan($op, $values, $current, $extra),
            'annual_plan_items' => $this->planItem($values, $current),
            'audits' => $this->audit($op, $values, $current),
            'audit_findings' => $this->auditFinding($values, $current),
            'management_reviews' => $this->review($values, $current, $extra),
            'measurements' => $this->measurement($values, $current),
            'change_requests' => $this->change($op, $values, $current),
            'change_reviews' => $values['reviewed_at'] ??= now()->toIso8601String(),
            default => null,
        };
    }

    private function plan(string $op, array &$values, ?object $current, array &$extra): void
    {
        if ($op === 'create') {
            $values['status'] = 'draft';

            return;
        }
        $to = $values['status'] ?? null;
        if (! $to || $to === $current->status) {
            return;
        }
        if (! in_array($to, self::PLAN_FLOW[$current->status] ?? [], true)) {
            $this->fail('status', 'Ese cambio de estado no está permitido.');
        }
        if ($to === 'approved') {
            if (! DB::selectOne('select 1 as ok from annual_plan_items where annual_plan_id = ? and deleted_at is null', [$current->id])) {
                $this->fail('status', 'El plan no tiene ítems (objetivos, metas y recursos).');
            }
            $model = DB::selectOne('select sst_model from companies where id = ?', [$current->company_id])?->sst_model;
            if ($model === 'committee' && ! $this->value($values, $current, 'committee_meeting_id')) {
                $this->fail('committee_meeting_id', 'La empresa tiene Comité SST: indica la sesión en que se aprobó el plan.');
            }
            $extra['approved_by'] = ['?', [(string) auth()->id()]];
            $extra['approved_at'] = ['now()', []];
        }
        if ($to === 'current') {
            DB::update("update annual_plans set status = 'closed' where id <> ? and status = 'current' and deleted_at is null", [$current->id]);
        }
    }

    private function planItem(array &$values, ?object $current): void
    {
        $st = DB::selectOne('select status from annual_plans where id = ?', [$this->value($values, $current, 'annual_plan_id')])?->status;
        if (! in_array($st, ['draft', 'in_review'], true)) {
            $this->conflict('El plan ya está aprobado: sus ítems no cambian.');
        }
    }

    private function audit(string $op, array &$values, ?object $current): void
    {
        if ($op === 'create' && ! isset($values['code'])) {
            $n = (int) DB::selectOne('select count(*) + 1 as n from audits where extract(year from created_at) = extract(year from now())')->n;
            do {
                $values['code'] = sprintf('AUD-%s-%04d', date('Y'), $n++);
            } while (DB::selectOne('select 1 as ok from audits where code = ? and deleted_at is null', [$values['code']]));
        }
        $to = $values['status'] ?? null;
        if ($op === 'create') {
            $values['status'] = 'planned';
        }
        if ($current && $to && $to !== $current->status) {
            $flow = ['planned' => ['in_progress', 'cancelled'], 'in_progress' => ['report_draft', 'completed', 'cancelled'], 'report_draft' => ['completed', 'in_progress'],
                'completed' => ['closed'], 'closed' => [], 'cancelled' => []];
            if (! in_array($to, $flow[$current->status] ?? [], true)) {
                $this->fail('status', in_array($current->status, ['closed', 'cancelled'], true) ? 'La auditoría ya está cerrada.' : 'Ese cambio de estado no está permitido: sigue el orden planificada → en curso → completada → cerrada.');
            }
        }
        if ($to === 'completed' && ! $this->value($values, $current, 'conclusions')) {
            $this->fail('conclusions', 'Escribe las conclusiones de la auditoría para completarla.');
        }
        if ($to === 'completed' && in_array($this->value($values, $current, 'audit_type'), ['legal_mandatory', 'external', 'certification'], true)) {
            if (! $this->value($values, $current, 'lead_auditor_name')) {
                $this->fail('lead_auditor_name', 'Indica el auditor líder.');
            }
            if ($this->value($values, $current, 'audit_type') === 'legal_mandatory' && ! $this->value($values, $current, 'auditor_registration')) {
                $this->fail('auditor_registration', 'La auditoría obligatoria la hace un auditor registrado en el MTPE: indica su número de registro (Ley 29783, art. 43).');
            }
        }
        if ($to === 'closed' && $current
            && DB::selectOne("select 1 as ok from audit_findings where audit_id = ? and deleted_at is null and status in ('open','action_assigned')", [$current->id])) {
            $this->fail('status', 'Hay hallazgos de la auditoría sin atender.');
        }
    }

    private function auditFinding(array &$values, ?object $current): void
    {
        $st = DB::selectOne('select status from audits where id = ?', [$this->value($values, $current, 'audit_id')])?->status;
        if (in_array($st, ['closed', 'cancelled'], true)) {
            $this->conflict('La auditoría está cerrada: no se agregan ni cambian hallazgos.');
        }
        $to = $values['status'] ?? null;
        $type = $this->value($values, $current, 'finding_type');
        if ($to === 'resolved' && in_array($type, ['major_nc', 'minor_nc'], true)) {
            $action = $this->value($values, $current, 'corrective_action_id');
            if (! $action || ! DB::selectOne("select 1 as ok from corrective_actions where id = ? and status = 'verified' and effectiveness = 'effective'", [$action])) {
                $this->fail('status', 'Una no conformidad se resuelve cuando su acción correctiva se verifica como eficaz.');
            }
        }
        if ($to === 'dismissed' && in_array($type, ['major_nc', 'minor_nc'], true)) {
            $this->fail('status', 'Una no conformidad no se descarta: asígnale una acción correctiva.');
        }
    }

    /** Revisión por la dirección (DS 005-2012-TR art. 89): toma los indicadores del sistema al iniciarse. */
    private function review(array &$values, ?object $current, array &$extra): void
    {
        $to = $values['status'] ?? null;
        if ($current === null) {
            $values['status'] = 'planned';

            return;
        }
        if ($to && $to !== $current->status) {
            $flow = ['planned' => ['in_progress'], 'in_progress' => ['completed', 'planned'], 'completed' => ['closed', 'in_progress'], 'closed' => []];
            if (! in_array($to, $flow[$current->status] ?? [], true)) {
                $this->fail('status', $current->status === 'closed' ? 'La revisión ya está cerrada.' : 'Ese cambio de estado no está permitido: sigue el orden planificada → en curso → completada → cerrada.');
            }
        }
        if ($to === 'in_progress' && in_array($current->inputs, [null, '', 'null', '{}', '[]'], true)) {
            $values['inputs'] = json_encode($this->reviewInputs((int) $current->year), JSON_UNESCAPED_UNICODE);
        }
        if ($to === 'completed') {
            if (! $this->value($values, $current, 'conclusions')) {
                $this->fail('conclusions', 'Escribe las conclusiones de la revisión por la dirección.');
            }
            $p = $this->value($values, $current, 'participants');
            if (empty($p) || in_array($p, ['[]', '{}', 'null'], true)) {
                $this->fail('participants', 'Indica quiénes participaron (la alta dirección participa en la revisión).');
            }
            $extra['approved_by'] = ['?', [(string) auth()->id()]];
            $extra['approved_at'] = ['now()', []];
        }
        if ($to === 'closed' && DB::selectOne("select 1 as ok from management_review_outputs where review_id = ? and deleted_at is null and status in ('open','in_progress')", [$current->id])) {
            $this->fail('status', 'Hay decisiones de la revisión sin cumplir: complétalas o anúlalas antes de cerrarla.');
        }
    }

    /** Entradas de la revisión: lo que el sistema ya sabe del año (estadísticas, auditorías, acciones, eventos y plan). */
    private function reviewInputs(int $year): array
    {
        $y = [$year];
        $stats = DB::selectOne('select coalesce(sum(hours_worked),0) as hh, coalesce(sum(accidents_fatal),0) as fatal, coalesce(sum(accidents_disabling),0) as disabling,
                                       coalesce(sum(lost_days),0) as days from monthly_statistics where deleted_at is null and site_id is null and period_year = ?', $y);
        $hh = (float) $stats->hh;
        $events = DB::select("select event_type, count(*) as n from sst_events where deleted_at is null and status <> 'discarded' and extract(year from occurred_at) = ? group by event_type", $y);

        return [
            'generated_at' => now('America/Lima')->format('Y-m-d H:i'),
            'statistics' => ['horas_hombre' => $hh, 'accidentes_mortales' => (int) $stats->fatal, 'accidentes_incapacitantes' => (int) $stats->disabling, 'dias_perdidos' => (int) $stats->days,
                'indice_frecuencia' => $hh > 0 ? round(((int) $stats->fatal + (int) $stats->disabling) * 1000000 / $hh, 2) : null,
                'indice_gravedad' => $hh > 0 ? round((int) $stats->days * 1000000 / $hh, 2) : null],
            'events' => array_column($events, 'n', 'event_type'),
            'audits' => DB::selectOne("select count(*) as audits, coalesce(sum((select count(*) from audit_findings f where f.audit_id = a.id and f.deleted_at is null and f.finding_type in ('major_nc','minor_nc'))),0) as non_conformities
                                         from audits a where a.deleted_at is null and extract(year from a.starts_on) = ?", $y),
            'corrective_actions' => DB::selectOne("select count(*) filter (where status in ('open','in_progress','overdue','pending_verification')) as open,
                                                         count(*) filter (where status in ('open','in_progress','overdue') and due_on < current_date) as overdue,
                                                         count(*) filter (where status = 'verified' and effectiveness = 'effective') as effective
                                                    from corrective_actions where deleted_at is null and extract(year from created_at) = ?", $y),
            'annual_plan_items' => (int) DB::selectOne('select count(i.*) as n from annual_plans p join annual_plan_items i on i.annual_plan_id = p.id and i.deleted_at is null
                                             where p.deleted_at is null and p.year = ?', $y)->n,
        ];
    }

    private function measurement(array &$values, ?object $current): void
    {
        $limitId = $this->value($values, $current, 'exposure_limit_id');
        $value = $this->value($values, $current, 'value');
        if (! $limitId || $value === null) {
            return;
        }
        $limit = DB::selectOne('select limit_value, unit from exposure_limits where id = ?', [$limitId]);
        $unit = $this->value($values, $current, 'unit');
        if ($limit?->limit_value !== null && (! $unit || ! $limit->unit || mb_strtolower($unit) === mb_strtolower($limit->unit))) {
            $values['result'] = (float) $value > (float) $limit->limit_value ? 'exceeds_limit' : 'within_limit';
        }
    }

    public function after(string $op, array $res, string $id, array $values, ?object $current): void
    {
        // Sin límite comparable, el resultado lo indica quien registra la medición.
        if ($res['table'] === 'measurements' && DB::selectOne('select result from measurements where id = ?', [$id])?->result === null) {
            $this->fail('result', 'Indica el resultado de la medición (no hay un límite de exposición comparable).');
        }
    }

    private function change(string $op, array &$values, ?object $current): void
    {
        if ($op === 'create') {
            if (! isset($values['code'])) {
                $n = (int) DB::selectOne('select count(*) + 1 as n from change_requests where extract(year from created_at) = extract(year from now())')->n;
                do {
                    $values['code'] = sprintf('MOC-%s-%04d', date('Y'), $n++);
                } while (DB::selectOne('select 1 as ok from change_requests where code = ? and deleted_at is null', [$values['code']]));
            }
            $values['status'] = 'draft';
            $values['requested_on'] ??= date('Y-m-d');

            return;
        }
        $to = $values['status'] ?? null;
        if (! $to || $to === $current->status) {
            return;
        }
        if (! in_array($to, self::MOC_FLOW[$current->status] ?? [], true)) {
            $this->fail('status', 'Ese cambio de estado no está permitido.');
        }
        if ($to === 'approved'
            && ! DB::selectOne("select 1 as ok from change_reviews where change_request_id = ? and deleted_at is null and decision in ('approve','approve_with_conditions')", [$current->id])) {
            $this->fail('status', 'Registra la revisión que aprueba el cambio (en «Revisiones») antes de aprobarlo.');
        }
        if ($to === 'implemented' && ! $this->value($values, $current, 'risk_assessment') && ! $this->value($values, $current, 'iperc_version_id')) {
            $this->fail('risk_assessment', 'Antes de implementar el cambio, registra su evaluación de riesgos o vincula la versión del IPERC actualizada.');
        }
        if (in_array($to, ['approved', 'rejected'], true)) {
            $values['decision_at'] = now()->toIso8601String();
        }
        if ($to === 'implemented') {
            $values['implemented_at'] = now()->toIso8601String();
        }
    }
}
