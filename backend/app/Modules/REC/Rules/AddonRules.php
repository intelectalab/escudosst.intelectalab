<?php

namespace App\Modules\REC\Rules;

use App\Core\Support\LegalParams;

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
            'vendor_evaluation_templates' => $current === null ? $extra['criteria'] = ["'[]'::jsonb", []] : null,
            // Pregunta nueva: alternativas vacías; se completan con «Alternativas» (editor del aula virtual).
            'questions' => $current === null ? $extra[($values['question_type'] ?? '') === 'short_answer' ? 'accepted_answers' : 'options'] = ["'[]'::jsonb", []] : null,
            'environmental_aspects' => $this->aspect($values, $current, $extra),
            'waste_records' => $this->waste($values, $current),
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
            $notice = (int) \App\Core\Support\LegalParams::number('sctr_policy_notice_days', 15);
            $values['status'] = $e < date('Y-m-d') ? 'expired' : ($e <= date('Y-m-d', strtotime("+{$notice} days")) ? 'expiring' : 'active');
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
        // RN-SCT-08: una sola cobertura por trabajador, tipo (salud / pensión) y periodo.
        $who = $this->value($values, $current, 'worker_id') ? 'worker_id' : 'contractor_worker_id';
        $dup = DB::selectOne(
            "select p.policy_number from sctr_coverages c join sctr_policies p on p.id = c.sctr_policy_id and p.deleted_at is null
              where c.{$who} = ? and c.id <> ? and c.deleted_at is null and c.status = 'covered'
                and p.coverage_kind = (select coverage_kind from sctr_policies where id = ?)
                and daterange(c.covered_from, c.covered_until, '[]') && daterange(?::date, ?::date, '[]') limit 1",
            [$this->value($values, $current, $who), $current?->id ?? '00000000-0000-0000-0000-000000000000', $this->value($values, $current, 'sctr_policy_id'),
                $this->value($values, $current, 'covered_from'), $until]
        );
        if ($dup) {
            $this->fail($who, "El trabajador ya tiene cobertura de este tipo en ese periodo (póliza N.° {$dup->policy_number}).");
        }
    }

    private function access(array &$values, array &$extra): void
    {
        $values['occurred_at'] ??= now()->toIso8601String();
        $extra['guard_user_id'] = ['?', [(string) auth()->id()]];
        $type = $values['subject_type'] ?? 'worker';
        // Motivos neutros (RN-ING-02): el texto y el código se guardan sin términos médicos; la aptitud médica queda
        // como «Requisito pendiente» en portería, en la lista de ingresos y en cualquier reporte.
        $reasons = [];
        $codes = [];
        $point = ($values['access_point_id'] ?? null) ? DB::selectOne('select is_active, allows_visitors, requires_credential from access_points where id = ?', [$values['access_point_id']]) : null;
        if ($point && ! $point->is_active) {
            $reasons[] = 'Punto de acceso inactivo';
            $codes[] = 'point_inactive';
        }
        if ($type === 'visitor' || $type === 'vehicle') {
            if ($point && $type === 'visitor' && ! $point->allows_visitors) {
                $reasons[] = 'Este punto no admite visitas';
                $codes[] = 'visitors_not_allowed';
            }
            if (($values['result'] ?? null) === 'overridden') {
                if (empty($values['override_reason'])) {
                    $this->fail('override_reason', 'Para autorizar por excepción indica el motivo.');
                }
            } else {
                $values['result'] = $reasons ? 'denied' : 'granted';
            }
            $values['deny_reasons'] = $reasons;
            $extra['deny_codes'] = ['?::jsonb', [json_encode($codes)]];

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
                $codes[] = 'contractor_worker_blocked';
            }
            if ($cw && $cw->contractor_status !== 'active') {
                $reasons[] = 'Contratista '.($cw->contractor_status === 'suspended' ? 'suspendido' : 'bloqueado');
                $codes[] = $cw->contractor_status === 'suspended' ? 'contractor_suspended' : 'contractor_blocked';
            }
        }
        $cred = DB::selectOne("select id, status, credential_type_id, worker_id, contractor_worker_id, expires_on from credentials where {$col} = ? and deleted_at is null and status <> 'revoked'
                                order by issued_at desc nulls last limit 1", [$person]);
        if (! $cred) {
            if (! $point || $point->requires_credential) {
                $reasons[] = 'Sin fotocheck';
                $codes[] = 'no_credential';
            }
        } else {
            // Se evalúan los requisitos en este momento (un SCTR o una aptitud pudo vencer desde la última revisión).
            $items = PeopleRules::credentialReasonItems($cred->credential_type_id, $cred->worker_id, $cred->contractor_worker_id, $cred->expires_on);
            $live = array_column($items, 'text');
            if ($items) {
                $neutral = [];
                foreach ($items as $it) {
                    [$code, $text] = self::neutralReason($it['type'], $it['text']);
                    $neutral[] = $text;
                    $codes[] = $code;
                }
                $reasons[] = 'Fotocheck no habilitado: '.implode(', ', array_values(array_unique($neutral)));
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
        $extra['deny_codes'] = ['?::jsonb', [json_encode(array_values(array_unique($codes)))]];
    }

    /**
     * Código y texto neutros de un requisito que falta (RN-ING-02): lo médico (aptitud, EMO, examen) nunca sale de la
     * base médica; en portería se ve y se guarda como «Requisito pendiente».
     *
     * @return array{0: string, 1: string}
     */
    public static function neutralReason(string $type, string $text): array
    {
        if ($type === 'emo_fitness' || preg_match('/m[ée]dic|aptitud|\bEMO\b|examen|salud ocupacional|diagn/iu', $text)) {
            return ['requirement_pending', 'Requisito pendiente'];
        }

        return [match ($type) {
            'training' => 'training_pending', 'induction' => 'induction_pending', 'sctr' => 'sctr_pending',
            'accreditation' => 'accreditation_pending', 'expired' => 'credential_expired', default => 'requirement_pending',
        }, $text];
    }

    /**
     * Ingreso negado de un trabajador propio: aviso inmediato al jefe de su área (M-ING §10). Jefe = el trabajador
     * a cargo del área (areas.manager_worker_id) con usuario, y los jefes de área con alcance en esa área o su sede.
     */
    public function after(string $op, array $res, string $id, array $values, ?object $current): void
    {
        if ($res['table'] !== 'access_events' || $op !== 'create' || ($values['result'] ?? null) !== 'denied' || empty($values['worker_id'])) {
            return;
        }
        if (! empty($values['credential_id'])) {
            $c = DB::selectOne('select c.credential_number, w.full_name from credentials c left join workers w on w.id = c.worker_id where c.id = ?', [$values['credential_id']]);
            \App\Modules\NOT\Notify::users(\App\Modules\NOT\Notify::roles(['sst_manager']), 'fot.gate_not_enabled', 'FOT', $id, 'Fotocheck no habilitado en portería',
                "Se verificó en portería el fotocheck {$c?->credential_number} de {$c?->full_name}, que no está habilitado.", '/m/fot?t=credentials&open='.$values['credential_id'], 'high', 'fot.gate:'.$id);
        }
        $w = DB::selectOne('select w.full_name, w.current_area_id, a.name as area, a.site_id, a.manager_worker_id
                              from workers w left join areas a on a.id = w.current_area_id where w.id = ?', [$values['worker_id']]);
        if (! $w || ! $w->current_area_id) {
            return;
        }
        $company = (string) DB::selectOne("select current_setting('app.company_id', true) as c")->c;
        $to = DB::select(
            "select distinct u.user_id from (
                select coalesce(mw.user_id, m.user_id) as user_id from workers mw
                  left join memberships m on m.worker_id = mw.id and m.status = 'active' and m.deleted_at is null
                 where mw.id = ?
                union
                select m.user_id from memberships m join roles r on r.id = m.role_id join membership_scopes s on s.membership_id = m.id and s.deleted_at is null
                 where coalesce(r.base_role_code, r.code) = 'area_lead' and m.status = 'active' and m.deleted_at is null
                   and ((s.scope_type = 'area' and s.area_id = ?) or (s.scope_type = 'site' and s.site_id = ?))
             ) u where u.user_id is not null",
            [$w->manager_worker_id, $w->current_area_id, $w->site_id]);
        $point = ($values['access_point_id'] ?? null) ? DB::selectOne('select name from access_points where id = ?', [$values['access_point_id']])?->name : null;
        foreach ($to as $u) {
            DB::insert(
                "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, recipient_worker_id, title, body, link, priority, dedupe_key)
                 values (?, 'ing.access_denied', 'access_events', ?, ?, ?, 'Ingreso negado en portería', ?, '/m/ing', 'high', ?)",
                [$company, $id, $u->user_id, $values['worker_id'],
                    mb_substr("{$w->full_name} ({$w->area}) intentó ingresar".($point ? " por {$point}" : '').' y no estaba habilitado: '.implode('; ', (array) ($values['deny_reasons'] ?? [])).'.', 0, 500),
                    'ing.denied.'.$id.'.'.$u->user_id]);
        }
    }

    private function vendor(array &$values, ?object $current, array &$extra): void
    {
        // RN-HOM-04: una evaluación aprobada no se edita (solo puede pasar a vencida).
        if ($current && $current->status === 'approved' && array_diff(array_keys($values), ['status']) !== []) {
            $this->conflict('La evaluación está aprobada: no se edita. Crea una nueva para volver a evaluar al proveedor.');
        }
        if ($current && $current->status === 'approved' && ($values['status'] ?? 'approved') !== 'expired' && ($values['status'] ?? 'approved') !== 'approved') {
            $this->fail('status', 'Una evaluación aprobada solo puede pasar a vencida.');
        }
        // RN-HOM-03: al aprobarse vale hasta la fecha de evaluación + vendor_validity_months.
        if (($values['status'] ?? null) === 'approved' && $current?->status !== 'approved' && ! $this->value($values, $current, 'valid_until')) {
            $months = (int) \App\Core\Support\LegalParams::number('vendor_validity_months', 12);
            $values['valid_until'] = date('Y-m-d', strtotime(($this->value($values, $current, 'evaluated_on') ?? date('Y-m-d'))." +{$months} months"));
        }
        if ($current === null) {
            $extra['scores'] = ["'{}'::jsonb", []];   // se califican después con «Calificar»
        }
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

    /**
     * M-AMB. RN-AMB-01: la significancia se calcula (severidad × probabilidad contra el umbral configurable
     * «amb_significance_threshold» de los parámetros); RN-AMB-03: un aspecto significativo exige sus controles.
     */
    private function aspect(array &$values, ?object $current, array &$extra): void
    {
        unset($values['significance_score'], $values['is_significant']);   // los calcula el sistema
        $sev = (int) ($this->value($values, $current, 'severity_value') ?? 0);
        $prob = (int) ($this->value($values, $current, 'probability_value') ?? 0);
        $significant = $sev > 0 && $prob > 0 && $sev * $prob >= LegalParams::number('amb_significance_threshold', 9);
        $extra['is_significant'] = ['?', [$significant ? 'true' : 'false']];
        if ($significant && trim((string) ($this->value($values, $current, 'controls') ?? '')) === '') {
            $this->fail('controls', 'Este aspecto es significativo: describe los controles que se aplican.');
        }
    }

    /**
     * M-AMB. RN-AMB-02: un residuo peligroso no se transfiere ni se da por dispuesto sin número de manifiesto y la EO-RS
     * (nombre y RUC); RN-AMB-05: un registro cerrado («Dispuesto») ya no se edita.
     */
    private function waste(array &$values, ?object $current): void
    {
        if ($current && $current->status === 'disposed' && $values !== []) {
            $this->conflict('Este residuo ya se dispuso: el registro está cerrado y no se edita.');
        }
        $type = $this->value($values, $current, 'waste_type');
        $status = $this->value($values, $current, 'status') ?? 'stored';
        if (in_array($type, ['hazardous', 'biocontaminated'], true) && in_array($status, ['transferred', 'disposed'], true)) {
            foreach (['manifest_number' => 'Indica el número de manifiesto del residuo peligroso.', 'eo_rs_name' => 'Indica la EO-RS que recoge el residuo peligroso.',
                'eo_rs_ruc' => 'Indica el RUC de la EO-RS.'] as $field => $msg) {
                if (trim((string) ($this->value($values, $current, $field) ?? '')) === '') {
                    $this->fail($field, $msg);
                }
            }
        }
    }

    private function signature(string $op, array &$values, ?object $current, array &$extra): void
    {
        if ($op === 'create') {
            $this->conflict('Las firmas se piden desde el registro que se firma, con «Pedir firma».');
        }
        if (in_array($values['status'] ?? null, ['signed', 'declined'], true)) {
            $this->conflict('Para firmar o rechazar usa el botón «Firmar» de la solicitud: deja la evidencia de la firma.');
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
