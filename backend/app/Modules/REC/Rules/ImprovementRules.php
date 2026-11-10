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
        if ($to === 'completed' && ! $this->value($values, $current, 'conclusions')) {
            $this->fail('conclusions', 'Escribe las conclusiones de la auditoría para completarla.');
        }
        if ($to === 'closed' && $current
            && DB::selectOne("select 1 as ok from audit_findings where audit_id = ? and deleted_at is null and status in ('open','action_assigned')", [$current->id])) {
            $this->fail('status', 'Hay hallazgos de la auditoría sin atender.');
        }
    }

    private function review(array &$values, ?object $current, array &$extra): void
    {
        if (($values['status'] ?? null) === 'completed') {
            if (! $this->value($values, $current, 'conclusions')) {
                $this->fail('conclusions', 'Escribe las conclusiones de la revisión por la dirección.');
            }
            $extra['approved_by'] = ['?', [(string) auth()->id()]];
            $extra['approved_at'] = ['now()', []];
        }
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
