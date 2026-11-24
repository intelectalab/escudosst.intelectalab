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
        'approved' => ['implemented', 'cancelled'], 'implemented' => ['verified'], 'verified' => ['closed'], 'rejected' => [], 'closed' => [], 'cancelled' => [],
    ];

    public function prepare(string $op, array $res, array &$values, ?object $current, array &$extra): void
    {
        match ($res['table']) {
            'annual_plans' => $this->plan($op, $values, $current, $extra),
            'annual_plan_items' => $this->planItem($values, $current),
            'audits' => $this->audit($op, $values, $current),
            'audit_findings' => $this->auditFinding($values, $current),
            'management_reviews' => $this->review($values, $current, $extra),
            'management_review_outputs' => $this->reviewOutput($values, $current),
            'measurements' => $this->measurement($values, $current, $extra),
            'change_requests' => $this->change($op, $values, $current),
            'monitoring_campaigns' => $this->campaign($values, $current),
            'ergonomic_assessments' => $this->ergonomic($values, $current),
            'change_reviews' => $this->changeReview($op, $values, $current),
            default => null,
        };
    }

    private function plan(string $op, array &$values, ?object $current, array &$extra): void
    {
        if ($op === 'create') {
            $values['status'] = 'draft';
            // RN-PLA-01: versión del plan por año (una nueva versión la crea /pla/plans/{id}/new-version).
            $extra['version_number'] = ['?', [(int) DB::selectOne('select coalesce(max(version_number), 0) + 1 as n from annual_plans where year = ? and deleted_at is null',
                [(int) ($values['year'] ?? date('Y'))])->n]];

            return;
        }
        // RN-PLA-04/08: el plan aprobado, vigente o cerrado no cambia sus datos (solo su estado).
        if (in_array($current->status, ['approved', 'current', 'closed'], true) && array_diff(array_keys($values), ['status']) !== []) {
            $this->conflict('El plan ya está aprobado o cerrado: sus datos no cambian. Crea una nueva versión.');
        }
        $to = $values['status'] ?? null;
        if (! $to || $to === $current->status) {
            return;
        }
        if (! in_array($to, self::PLAN_FLOW[$current->status] ?? [], true)) {
            $this->fail('status', 'Ese cambio de estado no está permitido.');
        }
        if (in_array($to, ['in_review', 'approved'], true)) {
            $this->planReady($values, $current);
        }
        if ($to === 'approved') {
            if (! DB::selectOne('select 1 as ok from annual_plan_items where annual_plan_id = ? and deleted_at is null', [$current->id])) {
                $this->fail('status', 'El plan no tiene ítems (objetivos, metas y recursos).');
            }
            // RN-PLA-01: un solo plan aprobado por año (una nueva versión reemplaza a la anterior al ponerse vigente).
            if (DB::selectOne("select 1 as ok from annual_plans where year = ? and id <> ? and status = 'approved' and deleted_at is null", [$current->year, $current->id])) {
                $this->fail('status', 'Ya hay otro plan aprobado de ese año: ponlo vigente o vuelve este a borrador.');
            }
            $model = DB::selectOne('select sst_model from companies where id = ?', [$current->company_id])?->sst_model;
            if ($model === 'committee') {
                $meeting = $this->value($values, $current, 'committee_meeting_id');
                if (! $meeting) {
                    $this->fail('committee_meeting_id', 'La empresa tiene Comité SST: indica la sesión en que se aprobó el plan.');
                }
                // RN-PLA-03: la sesión del comité debe tener quórum.
                if (! DB::selectOne('select 1 as ok from committee_meetings where id = ? and quorum_reached and deleted_at is null', [$meeting])) {
                    $this->fail('committee_meeting_id', 'La sesión elegida no tiene quórum registrado: el plan se aprueba en una sesión con quórum.');
                }
            } elseif ($model === 'supervisor' && ! DB::selectOne("select 1 as ok from signatures where subject_type = 'annual_plans' and subject_id = ? and ".\App\Modules\FIR\SignatureService::valid('signatures')."", [$current->id])) {
                // RN-PLA-03: con Supervisor SST, la aprobación lleva su firma (se pide con «Pedir firma»).
                $this->fail('status', 'La empresa tiene Supervisor SST: el plan necesita su firma antes de aprobarse (usa «Pedir firma»).');
            }
            $extra['approved_by'] = ['?', [(string) auth()->id()]];
            $extra['approved_at'] = ['now()', []];
        }
        if ($to === 'current') {
            DB::update("update annual_plans set status = 'closed' where id <> ? and status = 'current' and year = ? and deleted_at is null", [$current->id, $current->year]);
        }
        // RN-PLA-08: el plan vigente se cierra al terminar su año; el informe de cumplimiento se genera al cerrarlo.
        if ($to === 'closed') {
            $thisYear = (int) now('America/Lima')->format('Y');
            if ((int) $current->year >= $thisYear) {
                $this->fail('status', "El plan {$current->year} se cierra cuando termine su año (desde el 1 de enero de ".((int) $current->year + 1).').');
            }
            $extra['closed_at'] = ['now()', []];
            $extra['closed_by'] = ['?', [(string) auth()->id()]];
        }
    }

    /** RN-PLA-02/05: línea base aprobada, política vigente, al menos un objetivo y el PASST del mismo año. */
    private function planReady(array $values, object $current): void
    {
        $year = (int) $current->year;
        $baseline = $this->value($values, $current, 'baseline_assessment_id');
        if (! $baseline || ! DB::selectOne("select 1 as ok from baseline_assessments where id = ? and status = 'approved' and deleted_at is null", [$baseline])) {
            $this->fail('baseline_assessment_id', 'Vincula un diagnóstico de línea base aprobado.');
        }
        $policy = $this->value($values, $current, 'policy_id');
        if (! $policy || ! DB::selectOne("select 1 as ok from sst_policies where id = ? and status in ('current', 'review_required') and deleted_at is null", [$policy])) {
            $this->fail('policy_id', 'Vincula la política SST vigente.');
        }
        if (! DB::selectOne("select 1 as ok from annual_plan_items where annual_plan_id = ? and objective_id is not null and deleted_at is null", [$current->id])
            && ! DB::selectOne("select 1 as ok from sst_objectives where year = ? and status <> 'cancelled' and deleted_at is null", [$year])) {
            $this->fail('status', "El plan necesita al menos un objetivo SST del año {$year}.");
        }
        $pas = DB::selectOne("select year from annual_programs where annual_plan_id = ? and deleted_at is null order by created_at desc limit 1", [$current->id])
            ?? DB::selectOne("select year from annual_programs where year = ? and deleted_at is null and status <> 'closed' limit 1", [$year]);
        if (! $pas) {
            $this->fail('status', "Falta el Programa Anual (PASST) del año {$year}.");
        }
        if ((int) $pas->year !== $year) {
            $this->fail('status', 'El PASST vinculado es de otro año: el plan y sus programas deben ser del mismo año.');
        }
        $tp = $this->value($values, $current, 'training_program_id');
        if ($tp && (int) (DB::selectOne('select year from training_programs where id = ?', [$tp])?->year ?? $year) !== $year) {
            $this->fail('training_program_id', 'El programa de capacitación vinculado es de otro año.');
        }
    }

    /** RN-MAG-04: conversión registrada de una unidad a otra (primero la del agente, luego la general; vale también la inversa). */
    public static function unitConversion(?string $agent, string $from, string $to): ?object
    {
        $f = mb_strtolower(trim($from));
        $t = mb_strtolower(trim($to));
        $row = DB::selectOne(
            "select id, factor, lower(trim(from_unit)) = ? as direct from unit_conversions
              where deleted_at is null and ((lower(trim(from_unit)) = ? and lower(trim(to_unit)) = ?) or (lower(trim(from_unit)) = ? and lower(trim(to_unit)) = ?))
                and (agent_type_id is null or agent_type_id = ?::uuid)
              order by (agent_type_id is not null) desc, (company_id is not null) desc limit 1",
            [$f, $f, $t, $t, $f, $agent]);

        return $row ? (object) ['id' => $row->id, 'factor' => $row->direct ? (float) $row->factor : 1 / (float) $row->factor] : null;
    }

    private function planItem(array &$values, ?object $current): void
    {
        $plan = DB::selectOne('select status, year from annual_plans where id = ?', [$this->value($values, $current, 'annual_plan_id')]);
        if (! in_array($plan?->status, ['draft', 'in_review'], true)) {
            $this->conflict('El plan ya está aprobado: sus ítems no cambian.');
        }
        $this->planItemReference($values, $current, (int) $plan->year);
    }

    /**
     * RN-PLA-07: un componente de tipo objetivo o programa referencia un registro existente de la misma empresa y año, y nunca una
     * versión obsoleta (objetivo anulado, programa cerrado o reemplazado por una versión nueva). Los demás tipos no llevan referencia.
     */
    private function planItemReference(array &$values, ?object $current, int $year): void
    {
        $objective = $this->value($values, $current, 'objective_id');
        $type = $this->value($values, $current, 'item_type') ?: ($objective ? 'objective' : null);
        $refType = $this->value($values, $current, 'reference_type');
        $refId = $this->value($values, $current, 'reference_id');
        if ($type !== ($current->item_type ?? null)) {
            $values['item_type'] = $type;
        }
        if ($type === 'objective') {
            $objective = $objective ?: ($refType === 'sst_objectives' ? $refId : null);
            if (! $objective) {
                $this->fail('objective_id', 'Un componente de tipo objetivo debe indicar el objetivo SST.');
            }
            $o = \Illuminate\Support\Str::isUuid((string) $objective) ? DB::selectOne('select year, status from sst_objectives where id = ? and deleted_at is null', [$objective]) : null;
            if (! $o) {
                $this->fail('objective_id', 'El objetivo no existe o es de otra empresa.');
            }
            if ((int) $o->year !== $year) {
                $this->fail('objective_id', "El objetivo es de {$o->year}: el plan es de {$year}.");
            }
            if ($o->status === 'cancelled') {
                $this->fail('objective_id', 'El objetivo está anulado: elige uno vigente.');
            }
            $values['objective_id'] = $objective;
            $values['reference_type'] = 'sst_objectives';
            $values['reference_id'] = $objective;

            return;
        }
        if ($type === 'program') {
            if (! in_array($refType, ['annual_programs', 'training_programs'], true) || ! $refId) {
                $this->fail('reference_id', 'Un componente de tipo programa debe vincular el PASST o el programa de capacitación.');
            }
            $p = \Illuminate\Support\Str::isUuid((string) $refId) ? DB::selectOne("select year, status from {$refType} where id = ? and deleted_at is null", [$refId]) : null;
            if (! $p) {
                $this->fail('reference_id', 'El programa no existe o es de otra empresa.');
            }
            if ((int) $p->year !== $year) {
                $this->fail('reference_id', "El programa es de {$p->year}: el plan y sus programas deben ser del mismo año (RN-PLA-05).");
            }
            $replaced = $refType === 'annual_programs'
                && DB::selectOne('select 1 as ok from annual_programs where previous_program_id = ? and deleted_at is null', [$refId]);
            if ($p->status === 'closed' || $replaced) {
                $this->fail('reference_id', 'Ese programa es una versión cerrada o reemplazada: vincula la versión vigente.');
            }
            $values['objective_id'] = null;

            return;
        }
        if ($refType || $refId) {
            $values['reference_type'] = null;
            $values['reference_id'] = null;
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
        // RN-AUI-04: cerrada, la auditoría y su informe no cambian.
        if ($current && $current->status === 'closed' && $values !== []) {
            $this->conflict('La auditoría está cerrada: su informe no se modifica.');
        }
        // RN-AUI-01: la auditoría legal obligatoria registra el número de registro del auditor ante el MTPE desde que se guarda.
        if ($this->value($values, $current, 'audit_type') === 'legal_mandatory' && trim((string) $this->value($values, $current, 'auditor_registration')) === '') {
            $this->fail('auditor_registration', 'La auditoría obligatoria la hace un auditor registrado en el MTPE: indica su número de registro (Ley 29783, art. 43).');
        }
        // RN-AUI-04: se cierra con el informe adjunto.
        if ($to === 'closed' && ! $this->value($values, $current, 'report_file_id')) {
            $this->fail('report_file_id', 'Adjunta el informe de la auditoría para cerrarla.');
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
        // RN-AUI-06: una observación u oportunidad se cierra sin acción con su justificación.
        if ($to === 'dismissed' && trim((string) $this->value($values, $current, 'dismiss_reason')) === '') {
            $this->fail('dismiss_reason', 'Escribe la justificación para cerrar el hallazgo sin acción.');
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
        // RN-RVD-05: firmada y cerrada, la revisión no cambia.
        if ($current->status === 'closed' && $values !== []) {
            $this->conflict('La revisión ya está cerrada y firmada: no se modifica.');
        }
        if ($to && $to !== $current->status) {
            $flow = ['planned' => ['in_progress'], 'in_progress' => ['completed', 'planned'], 'completed' => ['closed', 'in_progress'], 'closed' => []];
            if (! in_array($to, $flow[$current->status] ?? [], true)) {
                $this->fail('status', $current->status === 'closed' ? 'La revisión ya está cerrada.' : 'Ese cambio de estado no está permitido: sigue el orden planificada → en curso → completada → cerrada.');
            }
        }
        if ($to === 'in_progress' && in_array($current->inputs, [null, '', 'null', '{}', '[]'], true)) {
            $values['inputs'] = json_encode($this->reviewInputs((int) $current->year, $this->value($values, $current, 'period_start'), $this->value($values, $current, 'period_end')), JSON_UNESCAPED_UNICODE);
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
        // RN-RVD-03: se cierra con la firma de gerencia (pedida con «Pedir firma» y firmada por un usuario de gerencia).
        if ($to === 'closed' && ! DB::selectOne(
            "select 1 as ok from signatures s join memberships m on m.user_id = s.signer_user_id and m.company_id = s.company_id and m.deleted_at is null
               join roles r on r.id = m.role_id where s.subject_type = 'management_reviews' and s.subject_id = ? and ".\App\Modules\FIR\SignatureService::valid('s')." and coalesce(r.base_role_code, r.code) = 'management'", [$current->id])) {
            $this->fail('status', 'La revisión se cierra con la firma de gerencia: pide la firma a gerencia («Pedir firma») antes de cerrarla.');
        }
    }

    /** RN-RVD-02: una decisión de una revisión cerrada no cambia. */
    private function reviewOutput(array &$values, ?object $current): void
    {
        $st = DB::selectOne('select status from management_reviews where id = ?', [$this->value($values, $current, 'review_id')])?->status;
        if ($st === 'closed') {
            $this->conflict('La revisión está cerrada: sus decisiones no cambian.');
        }
    }

    /** Entradas de la revisión: lo que el sistema ya sabe del año o del periodo (estadísticas, auditorías, acciones, eventos, plan, objetivos y cambios legales). */
    private function reviewInputs(int $year, ?string $from = null, ?string $to = null): array
    {
        $y = [$year];
        $from ??= "{$year}-01-01";
        $to ??= "{$year}-12-31";
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
            'periodo' => ['desde' => $from, 'hasta' => $to],
            // Foto de los objetivos del año y de los cambios legales del periodo (requisitos marcados para revisión por cambio normativo).
            'objetivos' => DB::selectOne("select count(*) as total, count(*) filter (where status = 'achieved') as logrados, count(*) filter (where status = 'not_achieved') as no_logrados,
                                                 count(*) filter (where status in ('draft', 'active')) as en_curso from sst_objectives where deleted_at is null and year = ?", $y),
            'cambios_legales' => DB::selectOne('select count(*) as requisitos_por_revisar, count(*) filter (where reviewed_at is not null and reviewed_at::date between ? and ?) as revisados_en_el_periodo
                                                  from legal_requirements where deleted_at is null and (needs_review or (review_flagged_at::date between ? and ?))', [$from, $to, $from, $to]),
        ];
    }

    private function measurement(array &$values, ?object $current, array &$extra): void
    {
        // RN-MAG-08: aprobada no cambia (solo se anula con motivo); anulada no cambia.
        if ($current && $current->status === 'voided' && $values !== []) {
            $this->conflict('La medición está anulada.');
        }
        if ($current && $current->status === 'approved' && array_diff(array_keys($values), ['status', 'void_reason']) !== []) {
            $this->conflict('La medición ya está aprobada: no se modifica. Si hay un error, anúlala con el motivo y registra una nueva.');
        }
        $to = $values['status'] ?? null;
        if ($current && $to && $to !== $current->status) {
            $flow = ['draft' => ['approved', 'voided'], 'approved' => ['voided']];
            if (! in_array($to, $flow[$current->status] ?? [], true)) {
                $this->fail('status', 'Ese cambio de estado no está permitido: borrador → aprobada → anulada.');
            }
        }
        if ($to === 'voided' && ! trim((string) $this->value($values, $current, 'void_reason'))) {
            $this->fail('void_reason', 'Indica por qué se anula la medición.');
        }
        if ($to === 'approved' && $current?->status !== 'approved') {
            $values['approved_by'] = (string) auth()->id();
            $values['approved_at'] = now()->toIso8601String();
        }
        if (! $current) {
            $values['status'] = in_array($to, ['draft', 'approved'], true) ? $to : 'draft';
            if ($values['status'] === 'approved') {
                $values['approved_by'] = (string) auth()->id();
                $values['approved_at'] = now()->toIso8601String();
            }
        }
        if ($to === 'voided' || ($current && array_diff(array_keys($values), ['status', 'void_reason', 'approved_by', 'approved_at']) === [])) {
            return;
        }
        if ($current && $current->corrective_action_id && array_intersect(array_keys($values), ['value', 'unit', 'exposure_limit_id', 'measured_at']) !== []) {
            $this->conflict('La medición ya originó su acción correctiva: no se cambia su valor. Registra una nueva medición.');
        }
        // RN-MAG-01: sin límite elegido, se usa el límite vigente del agente a la fecha de la medición.
        $limitId = $this->value($values, $current, 'exposure_limit_id');
        $agent = $this->value($values, $current, 'agent_type_id');
        $at = substr((string) ($this->value($values, $current, 'measured_at') ?? date('Y-m-d')), 0, 10);
        if (! $limitId && $agent) {
            $limitId = DB::selectOne('select id from exposure_limits where agent_type_id = ? and deleted_at is null and (valid_from is null or valid_from <= ?::date)
                                        and (valid_to is null or valid_to >= ?::date) order by valid_from desc nulls last limit 1', [$agent, $at, $at])?->id;
            if ($limitId) {
                $values['exposure_limit_id'] = $limitId;
            }
        }
        $value = $this->value($values, $current, 'value');
        if (! $limitId || $value === null) {
            return;
        }
        $limit = DB::selectOne('select limit_value, unit, valid_from, valid_to from exposure_limits where id = ?', [$limitId]);
        if ($limit && (($limit->valid_from && $limit->valid_from > $at) || ($limit->valid_to && $limit->valid_to < $at))) {
            $this->fail('exposure_limit_id', 'Ese límite no estaba vigente en la fecha de la medición: elige el límite vigente.');
        }
        // RN-MAG-04: la medición se compara en la unidad del límite; si viene en otra, se convierte con la tabla de conversión
        // registrada (del agente o general, en un sentido o en el inverso) y se guardan el valor y la unidad originales.
        $unit = $this->value($values, $current, 'unit');
        if ($unit && $limit?->unit && mb_strtolower(trim($unit)) !== mb_strtolower(trim($limit->unit))
            && (array_key_exists('value', $values) || array_key_exists('unit', $values) || array_key_exists('exposure_limit_id', $values))) {
            $conv = self::unitConversion($agent ?? $this->value($values, $current, 'agent_type_id'), $unit, $limit->unit);
            if (! $conv) {
                $this->fail('unit', "La medición está en «{$unit}» y el límite en «{$limit->unit}», y no hay una conversión registrada entre esas unidades: regístrala o anota la medición en «{$limit->unit}».");
            }
            $values['original_value'] = (float) $value;
            $values['original_unit'] = $unit;
            $extra['unit_conversion_id'] = ['?::uuid', [$conv->id]];
            $value = round((float) $value * $conv->factor, 8);
            $values['value'] = $value;
            $values['unit'] = $limit->unit;
        }
        if (! $unit && $limit?->unit) {
            $values['unit'] = $limit->unit;
        }
        // RN-MAG-02: razón medición/límite y nivel de acción (fracción del límite, parámetro a confirmar).
        if ($limit?->limit_value !== null && (float) $limit->limit_value > 0) {
            $ratio = round((float) $value / (float) $limit->limit_value, 4);
            $values['ratio_to_limit'] = $ratio;
            $action = (float) \App\Core\Support\LegalParams::number('action_level_ratio', 0.5);
            $values['result'] = $ratio > 1 ? 'exceeds_limit' : ($ratio >= $action ? 'action_level' : 'within_limit');
        }
    }

    /** RN-MAG-05: la campaña se cierra con el informe del laboratorio y al menos una medición. */
    private function campaign(array &$values, ?object $current): void
    {
        if (! in_array($values['status'] ?? null, ['report_received', 'closed'], true) || ! $current) {
            return;
        }
        if (! $this->value($values, $current, 'report_file_id')) {
            $this->fail('report_file_id', 'Adjunta el informe del laboratorio o proveedor del monitoreo.');
        }
        if (($values['status'] ?? null) === 'closed' && ! DB::selectOne("select 1 as ok from measurements where campaign_id = ? and deleted_at is null and status <> 'voided'", [$current->id])) {
            $this->fail('status', 'La campaña no tiene mediciones registradas.');
        }
        // RN-MAG-05: no se cierra con mediciones en borrador.
        if (($values['status'] ?? null) === 'closed' && ($n = (int) DB::selectOne("select count(*) as n from measurements where campaign_id = ? and deleted_at is null and status = 'draft'", [$current->id])->n) > 0) {
            $this->fail('status', "Hay {$n} medición(es) en borrador: apruébalas o anúlalas antes de cerrar la campaña.");
        }
    }

    /** RN-PSI-03: completar la evaluación exige el nivel de riesgo; alto o muy alto genera su acción (en after). */
    private function ergonomic(array &$values, ?object $current): void
    {
        if (($values['status'] ?? null) === 'completed' && ! $this->value($values, $current, 'risk_level')) {
            $this->fail('risk_level', 'Indica el nivel de riesgo resultante de la evaluación.');
        }
        if ($current && $current->status === 'reviewed' && $values !== []) {
            $this->conflict('La evaluación ya fue revisada: no se modifica.');
        }
        $values['assessor_user_id'] = $this->value($values, $current, 'assessor_user_id') ?? (string) auth()->id();
    }

    public function after(string $op, array $res, string $id, array $values, ?object $current): void
    {
        // RN-PLA-04: al aprobarse, el PDF del plan se genera y se versiona en la gestión documental.
        // RN-PLA-08: al cerrarse, se genera el informe de cumplimiento con el avance final del PASST y de los objetivos.
        if ($res['table'] === 'annual_plans' && $op === 'update') {
            if (($values['status'] ?? null) === 'approved') {
                \App\Modules\REC\AnnualPlanDocs::archive($id);
            }
            if (($values['status'] ?? null) === 'closed') {
                \App\Modules\REC\AnnualPlanDocs::closure($id);
            }
        }
        // RN-RVD-02: toda decisión con responsable genera su acción en ACR al guardarse.
        if ($res['table'] === 'management_review_outputs') {
            $o = DB::selectOne('select o.*, r.company_id as rc from management_review_outputs o join management_reviews r on r.id = o.review_id where o.id = ?', [$id]);
            if ($o && $o->responsible_user_id && ! $o->corrective_action_id && $o->status !== 'cancelled') {
                $action = \App\Modules\ACR\AutoAction::create($o->company_id, 'management_review', $o->review_id, mb_substr('Revisión por la dirección: '.$o->description, 0, 300),
                    $o->description, $o->responsible_user_id, $o->due_on, 'medium');
                DB::update("update management_review_outputs set corrective_action_id = ?, status = case when status = 'open' then 'in_progress' else status end where id = ?", [$action, $id]);
            }
        }
        // RN-MAG-03: una medición sobre el límite crea su acción correctiva (prioridad alta) para el responsable de la campaña.
        if ($res['table'] === 'measurements') {
            $m = DB::selectOne('select m.*, c.responsible_user_id, p.site_id, p.area_id, p.name as point, a.name as agent from measurements m
                                  left join monitoring_campaigns c on c.id = m.campaign_id left join monitoring_points p on p.id = m.monitoring_point_id
                                  left join agent_types a on a.id = m.agent_type_id where m.id = ?', [$id]);
            // RN-MAG-06: aprobada sobre el límite, se avisa al médico ocupacional para la vigilancia de los expuestos (solo la marca).
            if ($m && $m->result === 'exceeds_limit' && $m->status === 'approved' && ($values['status'] ?? null) === 'approved') {
                \App\Modules\NOT\Notify::users(\App\Modules\NOT\Notify::roles(['occupational_doctor']), 'mag.exposed', 'MAG', $id, 'Trabajadores expuestos sobre el límite',
                    'Medición de '.($m->agent ?? 'agente').' en '.($m->point ?? 'un punto').' sobre el límite: revisa la lista de expuestos para la vigilancia médica.', '/m/mag', 'high', 'mag.exposed:'.$id);
            }
            if ($m && $m->result === 'exceeds_limit' && ! $m->corrective_action_id) {
                $action = \App\Modules\ACR\AutoAction::create($m->company_id, 'monitoring', $id,
                    'Controlar la exposición sobre el límite: '.trim(($m->agent ?? 'agente').' en '.($m->point ?? 'el punto medido')),
                    "Medido {$m->value} {$m->unit} (".round((float) $m->ratio_to_limit * 100).' % del límite).', $m->responsible_user_id, null, 'high', $m->site_id, $m->area_id);
                DB::update('update measurements set corrective_action_id = ? where id = ?', [$action, $id]);
            }
            // RN-MAG-03 / criterio 1: una medición aprobada sobre el límite (o en nivel de acción) deja el IPERC del área «por revisar».
            if ($m && $m->status === 'approved' && in_array($m->result, ['exceeds_limit', 'action_level'], true) && ($values['status'] ?? null) === 'approved' && $m->area_id) {
                foreach (DB::select("select id from iperc_matrices where area_id = ? and deleted_at is null and status in ('active', 'review_overdue')", [$m->area_id]) as $mx) {
                    \App\Modules\IPC\Services\IpercReview::flag($mx->id, ($m->result === 'exceeds_limit' ? 'Medición sobre el límite' : 'Medición en nivel de acción').' de '.($m->agent ?? 'un agente').' en '.($m->point ?? 'el área'), $id);
                }
            }
        }
        // RN-PSI-03: una evaluación ergonómica de riesgo alto o muy alto genera su acción correctiva.
        if ($res['table'] === 'ergonomic_assessments') {
            $e = DB::selectOne('select e.*, p.name as position from ergonomic_assessments e left join positions p on p.id = e.position_id where e.id = ?', [$id]);
            if ($e && $e->status !== 'draft' && in_array($e->risk_level, ['high', 'very_high'], true) && ! $e->corrective_action_id) {
                $action = \App\Modules\ACR\AutoAction::create($e->company_id, 'other', $id, 'Reducir el riesgo ergonómico'.($e->position ? " del puesto {$e->position}" : ''),
                    $e->recommendations ?: $e->findings, $e->assessor_user_id, null, $e->risk_level === 'very_high' ? 'critical' : 'high', null, $e->area_id);
                DB::update('update ergonomic_assessments set corrective_action_id = ? where id = ?', [$action, $id]);
            }
            // RN-PSI-03: también deja el IPERC del puesto (o, si no tiene, del área) «por revisar».
            if ($e && $e->status !== 'draft' && in_array($e->risk_level, ['high', 'very_high'], true) && ($current === null || $current->risk_level !== $e->risk_level || $current->status === 'draft')) {
                $mx = DB::select("select id from iperc_matrices where deleted_at is null and status in ('active', 'review_overdue')
                                   and ((?::uuid is not null and position_id = ?::uuid) or (position_id is null and ?::uuid is not null and area_id = ?::uuid))", [$e->position_id, $e->position_id, $e->area_id, $e->area_id]);
                foreach ($mx as $x) {
                    \App\Modules\IPC\Services\IpercReview::flag($x->id, 'Evaluación ergonómica de riesgo '.($e->risk_level === 'very_high' ? 'muy alto' : 'alto').($e->position ? " en el puesto {$e->position}" : ''), $id);
                }
            }
        }
        // RN-MOC-05: las condiciones de una revisión que aprueba con condiciones se vuelven acciones para quien pidió el cambio.
        if ($res['table'] === 'change_reviews' && $op === 'create') {
            $r = DB::selectOne('select r.*, c.company_id as cid, c.code, c.requested_by, c.planned_implementation_on, c.site_id, c.area_id from change_reviews r
                                  join change_requests c on c.id = r.change_request_id where r.id = ?', [$id]);
            if ($r->decision === 'approve_with_conditions' && trim((string) $r->conditions) !== ''
                && ! DB::selectOne("select 1 as ok from corrective_actions where source_type = 'change' and source_id = ? and description = ? and deleted_at is null", [$r->change_request_id, $r->conditions])) {
                \App\Modules\ACR\AutoAction::create($r->cid, 'change', $r->change_request_id, "Cumplir la condición del cambio {$r->code}", $r->conditions,
                    $r->requested_by, $r->planned_implementation_on, 'medium', $r->site_id, $r->area_id);
            }
        }
        // Sin límite comparable, el resultado lo indica quien registra la medición.
        if ($res['table'] === 'measurements' && DB::selectOne('select result from measurements where id = ?', [$id])?->result === null) {
            $this->fail('result', 'Indica el resultado de la medición (no hay un límite de exposición comparable).');
        }
    }

    /**
     * RN-MOC-01: el Jefe SST asigna revisores por especialidad (la revisión queda pendiente, sin decisión); la decisión la registra el
     * revisor asignado (o quien aprueba revisiones). Una revisión resuelta no cambia.
     */
    private function changeReview(string $op, array &$values, ?object $current): void
    {
        if ($current && $current->decision !== null && $values !== []) {
            $this->conflict('La revisión ya fue registrada: no se modifica.');
        }
        if (array_key_exists('decision', $values) && $values['decision'] !== null) {
            $reviewer = $this->value($values, $current, 'reviewer_user_id');
            if ($reviewer && (string) $reviewer !== (string) auth()->id() && ! in_array('moc.change_reviews.approve', \App\Core\Auth\Permissions::effective(), true)) {
                $this->fail('decision', 'Esta revisión la registra el revisor asignado.');
            }
            if ($values['decision'] === 'approve_with_conditions' && ! trim((string) $this->value($values, $current, 'conditions'))) {
                $this->fail('conditions', 'Escribe las condiciones de la aprobación.');
            }
            $values['reviewed_at'] = now()->toIso8601String();
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
        }
        // RN-MOC-04: un cambio temporal tiene fecha de fin.
        if ($this->value($values, $current, 'is_temporary') && ! $this->value($values, $current, 'temporary_until')) {
            $this->fail('temporary_until', 'Un cambio temporal necesita su fecha de fin (cuando se revierte o se vuelve permanente).');
        }
        if ($op === 'create') {
            return;
        }
        // RN-MOC-04: un cambio temporal en curso que se vuelve permanente pasa por una nueva revisión (vuelve a «en revisión»
        // y se aprueba de nuevo con una revisión posterior a su implementación).
        if ($current->is_temporary && array_key_exists('is_temporary', $values) && ! $values['is_temporary']
            && in_array($current->status, ['approved', 'implemented', 'verified'], true)) {
            $values['status'] = 'under_review';
            $values['temporary_until'] = null;
            $values['decision_at'] = null;
            $values['management_approved_by'] = null;
            $values['management_approved_at'] = null;

            return;
        }
        $to = $values['status'] ?? null;
        if (! $to || $to === $current->status) {
            return;
        }
        // RN-MOC-04: un cambio temporal implementado se puede revertir y cerrar, dejando constancia de la reversión.
        $revert = $current->is_temporary && $current->status === 'implemented' && $to === 'closed';
        if ($current->is_temporary && $to === 'closed' && ! trim((string) $this->value($values, $current, 'closure_notes'))) {
            $this->fail('closure_notes', 'Es un cambio temporal: indica cómo se revirtió (o hazlo permanente con una nueva revisión).');
        }
        if (! $revert && ! in_array($to, self::MOC_FLOW[$current->status] ?? [], true)) {
            $this->fail('status', 'Ese cambio de estado no está permitido.');
        }
        if (in_array($to, ['submitted', 'under_review'], true) && ! $this->value($values, $current, 'impact_level')) {
            $this->fail('impact_level', 'Indica el nivel de impacto del cambio (bajo, medio o alto).');
        }
        if ($to === 'approved') {
            // RN-MOC-01: no se aprueba sin todas las revisiones obligatorias resueltas a favor.
            $pending = DB::select("select coalesce(review_area, 'sin especialidad') as area, decision from change_reviews where change_request_id = ? and deleted_at is null and is_required
                                      and (decision is null or decision in ('reject', 'request_info'))", [$current->id]);
            if ($pending) {
                $this->fail('status', 'Faltan revisiones obligatorias resueltas a favor: '.implode(', ', array_unique(array_column($pending, 'area'))).'.');
            }
            if (! DB::selectOne("select 1 as ok from change_reviews where change_request_id = ? and deleted_at is null and decision in ('approve','approve_with_conditions')", [$current->id])) {
                $this->fail('status', 'Registra la revisión que aprueba el cambio (en «Revisiones») antes de aprobarlo.');
            }
            // RN-MOC-04: un temporal que pasa a permanente se aprueba con una revisión nueva (posterior a su implementación).
            if ($current->implemented_at && ! DB::selectOne("select 1 as ok from change_reviews where change_request_id = ? and deleted_at is null
                                                              and decision in ('approve','approve_with_conditions') and reviewed_at > ?", [$current->id, $current->implemented_at])) {
                $this->fail('status', 'El cambio pasa de temporal a permanente: registra una revisión nueva que lo apruebe como permanente.');
            }
            // RN-MOC-03: un cambio de impacto alto lo aprueba gerencia.
            if ($this->value($values, $current, 'impact_level') === 'high') {
                $ctx = app(\App\Core\Tenancy\CompanyContext::class);
                $base = DB::selectOne('select coalesce(base_role_code, code) as c from roles where code = ? and (account_id is null or account_id = (select account_id from companies where id = ?)) limit 1',
                    [$ctx->roleCode, $current->company_id])?->c ?? $ctx->roleCode;
                if ($base !== 'management') {
                    $this->fail('status', 'Es un cambio de impacto alto: queda pendiente de la aprobación de gerencia.');
                }
                $values['management_approved_by'] = (string) auth()->id();
                $values['management_approved_at'] = now()->toIso8601String();
            }
        }
        // M-MOC §9: después de implementarlo se verifica su eficacia antes de cerrarlo.
        if ($to === 'verified') {
            if (! trim((string) $this->value($values, $current, 'verification_notes'))) {
                $this->fail('verification_notes', 'Describe cómo se verificó que el cambio funciona y que los riesgos quedaron controlados.');
            }
            $values['verified_by'] = (string) auth()->id();
            $values['verified_at'] = now()->toIso8601String();
        }
        // RN-MOC-02: si el cambio introduce peligros nuevos, se implementa con la versión del IPERC actualizada y aprobada.
        if ($to === 'implemented' && $this->value($values, $current, 'introduces_new_hazards')) {
            $v = $this->value($values, $current, 'iperc_version_id');
            if (! $v || ! in_array(DB::selectOne('select status from iperc_versions where id = ?', [$v])?->status, ['approved', 'current', 'active'], true)) {
                $this->fail('iperc_version_id', 'El cambio introduce peligros nuevos: vincula la versión del IPERC actualizada y aprobada antes de implementarlo.');
            }
        }
        // RN-MOC-05: no se cierra con condiciones pendientes.
        if ($to === 'closed' && DB::selectOne("select 1 as ok from corrective_actions where source_type = 'change' and source_id = ? and deleted_at is null and status not in ('verified', 'closed', 'cancelled')", [$current->id])) {
            $this->fail('status', 'Hay condiciones del cambio (acciones correctivas) sin cumplir.');
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
