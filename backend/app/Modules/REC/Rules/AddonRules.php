<?php

namespace App\Modules\REC\Rules;

use Illuminate\Support\Facades\DB;

/**
 * SCT SCTR, ING ingreso por QR, HOM homologación, AMB aspectos ambientales, VUL ajustes de trabajo y FIR firma
 * (docs/modulos §8 de cada ficha).
 * - SCT: la póliza pasa a «por vencer» (30 días) o «vencida» según su fecha; la cobertura vence con la póliza.
 * - ING: cada ingreso se evalúa contra el fotocheck vigente (y la acreditación del contratista): permitido o
 *   negado con motivos; el vigilante puede autorizar por excepción con motivo. El registro no se edita.
 * - HOM: el puntaje total es el promedio de los criterios; aprueba si alcanza el mínimo de la plantilla.
 * - AMB: la significancia = severidad × probabilidad.
 * - VUL: el ajuste activo queda con quién lo aprobó.
 * - FIR: solo el firmante firma o rechaza (con motivo) su solicitud; firmada no cambia.
 */
class AddonRules extends RecordRules
{
    public function prepare(string $op, array $res, array &$values, ?object $current, array &$extra): void
    {
        match ($res['table']) {
            'sctr_policies' => $this->policy($values, $current),
            'sctr_coverages' => $this->coverage($values, $current),
            'access_events' => $this->access($values, $extra),
            'vendor_evaluations' => $this->vendor($values, $current, $extra),
            'environmental_aspects' => $this->aspect($values, $current, $extra),
            'work_adjustments' => ($values['status'] ?? null) === 'active' ? $extra['approved_by'] = ['?', [(string) auth()->id()]] : null,
            'signature_requests' => $this->signature($op, $values, $current, $extra),
            default => null,
        };
    }

    private function policy(array &$values, ?object $current): void
    {
        $s = $this->value($values, $current, 'starts_on');
        $e = $this->value($values, $current, 'ends_on');
        if ($s && $e && $e < $s) {
            $this->fail('ends_on', 'La fecha de fin no puede ser anterior a la de inicio.');
        }
        if ($e && ($this->value($values, $current, 'status') ?? 'active') !== 'cancelled') {
            $values['status'] = $e < date('Y-m-d') ? 'expired' : ($e <= date('Y-m-d', strtotime('+30 days')) ? 'expiring' : 'active');
        }
    }

    private function coverage(array &$values, ?object $current): void
    {
        $policy = DB::selectOne('select ends_on, status from sctr_policies where id = ?', [$this->value($values, $current, 'sctr_policy_id')]);
        if (! $this->value($values, $current, 'worker_id') && ! $this->value($values, $current, 'contractor_worker_id')) {
            $this->fail('worker_id', 'Elige al trabajador cubierto.');
        }
        $values['covered_until'] = $this->value($values, $current, 'covered_until') ?? $policy?->ends_on;
        if ($policy && $values['covered_until'] && $policy->ends_on && $values['covered_until'] > $policy->ends_on) {
            $this->fail('covered_until', 'La cobertura no puede pasar la vigencia de la póliza.');
        }
        $until = $values['covered_until'];
        $values['status'] = $policy?->status === 'cancelled' || ($until && $until < date('Y-m-d')) ? 'expired' : 'covered';
    }

    private function access(array &$values, array &$extra): void
    {
        $values['occurred_at'] ??= now()->toIso8601String();
        $extra['guard_user_id'] = ['?', [(string) auth()->id()]];
        $type = $values['subject_type'] ?? 'worker';
        $reasons = [];
        $point = ($values['access_point_id'] ?? null) ? DB::selectOne('select is_active, allows_visitors, requires_credential from access_points where id = ?', [$values['access_point_id']]) : null;
        if ($point && ! $point->is_active) {
            $reasons[] = 'Punto de acceso inactivo';
        }
        if ($type === 'visitor' || $type === 'vehicle') {
            if ($point && $type === 'visitor' && ! $point->allows_visitors) {
                $reasons[] = 'Este punto no admite visitas';
            }
            if (($values['result'] ?? null) === 'overridden') {
                if (empty($values['override_reason'])) {
                    $this->fail('override_reason', 'Para autorizar por excepción indica el motivo.');
                }
            } else {
                $values['result'] = $reasons ? 'denied' : 'granted';
            }
            $values['deny_reasons'] = $reasons;

            return;
        }
        $col = $type === 'contractor_worker' ? 'contractor_worker_id' : 'worker_id';
        $person = $values[$col] ?? null;
        if (! $person) {
            $this->fail($col, 'Elige a la persona que ingresa.');
        }
        if ($type === 'contractor_worker') {
            $cw = DB::selectOne('select w.status, w.access_enabled, c.status as contractor_status from contractor_workers w join contractors c on c.id = w.contractor_id where w.id = ?', [$person]);
            if ($cw && ($cw->status !== 'active' || ! $cw->access_enabled)) {
                $reasons[] = 'Trabajador del contratista bloqueado o sin acceso';
            }
            if ($cw && $cw->contractor_status !== 'active') {
                $reasons[] = 'Contratista '.($cw->contractor_status === 'suspended' ? 'suspendido' : 'bloqueado');
            }
        }
        $cred = DB::selectOne("select id, status, credential_type_id, worker_id, contractor_worker_id, expires_on from credentials where {$col} = ? and deleted_at is null and status <> 'revoked'
                                order by issued_at desc nulls last limit 1", [$person]);
        if (! $cred) {
            if (! $point || $point->requires_credential) {
                $reasons[] = 'Sin fotocheck';
            }
        } else {
            // Se evalúan los requisitos en este momento (un SCTR o una aptitud pudo vencer desde la última revisión).
            $live = PeopleRules::credentialReasons($cred->credential_type_id, $cred->worker_id, $cred->contractor_worker_id, $cred->expires_on);
            if ($live) {
                $reasons[] = 'Fotocheck no habilitado: '.implode(', ', $live);
            }
            DB::update('update credentials set status = ?, blocking_reasons = ?::jsonb, last_evaluated_at = now() where id = ?',
                [$live ? 'blocked' : 'active', json_encode($live, JSON_UNESCAPED_UNICODE), $cred->id]);
        }
        $values['credential_id'] ??= $cred?->id;
        if (($values['result'] ?? null) === 'overridden') {
            if (empty($values['override_reason'])) {
                $this->fail('override_reason', 'Para autorizar por excepción indica el motivo.');
            }
        } else {
            $values['result'] = $reasons ? 'denied' : 'granted';
        }
        $values['deny_reasons'] = $reasons;
    }

    private function vendor(array &$values, ?object $current, array &$extra): void
    {
        $scores = $this->value($values, $current, 'scores');
        if (is_string($scores)) {
            $scores = json_decode($scores, true);
        }
        $nums = is_array($scores) ? array_values(array_filter(array_map(fn ($v) => is_array($v) ? ($v['score'] ?? null) : $v, $scores), 'is_numeric')) : [];
        if ($nums) {
            $total = round(array_sum($nums) / count($nums), 2);
            $values['total_score'] = $total;
            $passing = DB::selectOne('select passing_score from vendor_evaluation_templates where id = ?', [$this->value($values, $current, 'template_id')])?->passing_score;
            if ($passing !== null && ! array_key_exists('rating', $values)) {
                $values['rating'] = $total >= (float) $passing ? 'approved' : 'rejected';
            }
        }
        if ($current === null) {
            $values['evaluator_user_id'] ??= (string) auth()->id();
            $values['evaluated_on'] ??= date('Y-m-d');
        }
    }

    private function aspect(array &$values, ?object $current, array &$extra): void
    {
        unset($values['significance_score']);   // columna calculada por la base (severidad × probabilidad)
    }

    private function signature(string $op, array &$values, ?object $current, array &$extra): void
    {
        if ($op === 'create') {
            $values['status'] = 'pending';
            $extra['requested_by'] = ['?', [(string) auth()->id()]];

            return;
        }
        if (in_array($current->status, ['signed', 'declined', 'expired', 'cancelled'], true) && $values !== []) {
            $this->conflict('La solicitud de firma ya está cerrada.');
        }
        $to = $values['status'] ?? null;
        if (in_array($to, ['signed', 'declined'], true)) {
            if ($current->signer_user_id !== (string) auth()->id()) {
                $this->fail('status', 'Solo la persona a quien se pidió la firma puede firmar o rechazar.');
            }
            if ($current->expires_at && strtotime($current->expires_at) < time()) {
                $this->fail('status', 'La solicitud de firma venció.');
            }
            if ($to === 'declined' && ! $this->value($values, $current, 'declined_reason')) {
                $this->fail('declined_reason', 'Indica por qué rechazas la firma.');
            }
            if ($to === 'signed') {
                $values['signed_at'] = now()->toIso8601String();
            }
        }
    }
}
