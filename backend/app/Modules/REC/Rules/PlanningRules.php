<?php

namespace App\Modules\REC\Rules;

use Illuminate\Support\Facades\DB;

/**
 * Reglas de objetivos SST y brechas de la línea base según sus fichas:
 * - M-POL RN-POL-05: el objetivo se liga a la política vigente y, para activarse, tiene meta con valor y unidad.
 * - M-LBA §9: una brecha aceptada como riesgo la acepta la gerencia con su motivo; cerrada exige su acción verificada o el motivo.
 */
class PlanningRules extends RecordRules
{
    public function prepare(string $op, array $res, array &$values, ?object $current, array &$extra): void
    {
        match ($res['table']) {
            'sst_objectives' => $this->objective($op, $values, $current),
            'baseline_gaps' => $this->gap($values, $current),
            default => null,
        };
    }

    private function objective(string $op, array &$values, ?object $current): void
    {
        if ($op === 'create' && ! isset($values['policy_id'])) {
            $values['policy_id'] = DB::selectOne("select id from sst_policies where status = 'current' and deleted_at is null order by approved_at desc nulls last limit 1")?->id;
        }
        $policy = $this->value($values, $current, 'policy_id');
        if (! $policy) {
            $this->fail('policy_id', 'Liga el objetivo a la política SST vigente (aprueba primero la política).');
        }
        if (array_key_exists('policy_id', $values) && DB::selectOne('select status from sst_policies where id = ?', [$policy])?->status !== 'current') {
            $this->fail('policy_id', 'El objetivo se liga a la política vigente, no a un borrador ni a una versión anterior.');
        }
        if (($values['status'] ?? null) === 'active') {
            if ($this->value($values, $current, 'target_value') === null) {
                $this->fail('target_value', 'Para activar el objetivo indica su meta (valor).');
            }
            if (! trim((string) $this->value($values, $current, 'unit'))) {
                $this->fail('unit', 'Indica la unidad de la meta (%, número, días…).');
            }
        }
    }

    private function gap(array &$values, ?object $current): void
    {
        $to = $values['status'] ?? null;
        if ($to === 'accepted_risk' && ($current?->status !== 'accepted_risk')) {
            $role = app(\App\Core\Tenancy\CompanyContext::class)->roleCode;
            if (! in_array($role, ['management', 'account_admin'], true)) {
                $this->fail('status', 'Aceptar una brecha como riesgo lo decide la gerencia.');
            }
            if (! trim((string) $this->value($values, $current, 'proposed_action'))) {
                $this->fail('proposed_action', 'Explica por qué se acepta el riesgo y cómo se controla mientras tanto.');
            }
        }
        if ($to === 'closed' && $current && $current->status !== 'closed') {
            $action = $this->value($values, $current, 'corrective_action_id');
            if (! $action || ! in_array(DB::selectOne('select status from corrective_actions where id = ?', [$action])?->status, ['verified', 'closed'], true)) {
                $this->fail('status', 'La brecha se cierra cuando su acción correctiva está verificada.');
            }
            $values['closed_at'] = now()->toIso8601String();
        }
    }
}
