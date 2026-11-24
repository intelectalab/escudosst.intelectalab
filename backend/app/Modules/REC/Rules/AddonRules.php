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
            'vendor_evaluation_templates' => $this->vendorTemplate($values, $current, $extra),
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
        // RN-ING-06: evento registrado sin conexión y sincronizado después (lo marca GateController::sync).
        if ($off = request()->attributes->get('ing_offline')) {
            $extra['is_offline'] = ['true', []];
            $extra['idempotency_key'] = ['?', [$off['key']]];
        }
        $type = $values['subject_type'] ?? 'worker';
        // Motivos neutros (RN-ING-02): el texto y el código se guardan sin términos médicos; la aptitud médica queda
        // como «Requisito pendiente» en portería, en la lista de ingresos y en cualquier reporte.
        $reasons = [];
        $codes = [];
        $point = ($values['access_point_id'] ?? null) ? DB::selectOne('select is_active, allows_visitors, requires_credential, site_id from access_points where id = ?', [$values['access_point_id']]) : null;
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
            // RN-CTR-06 / M-ING: la acreditación vigente debe cubrir la sede del punto de ingreso y, si lista trabajadores, a esta persona.
            if ($point?->site_id && ! DB::selectOne(
                "select 1 as ok from contractor_accreditations a join contractor_workers w on w.contractor_id = a.contractor_id
                  where w.id = ? and a.deleted_at is null and a.status in ('accredited', 'expiring') and (a.expires_on is null or a.expires_on >= current_date)
                    and (a.starts_on is null or a.starts_on <= current_date) and (a.ends_on is null or a.ends_on >= current_date)
                    and (a.site_ids = '{}' or ?::uuid = any(a.site_ids)) and (a.contractor_worker_ids = '{}' or w.id = any(a.contractor_worker_ids))", [$person, $point->site_id])) {
                $reasons[] = 'Sin acreditación vigente para esta sede';
                $codes[] = 'site_not_accredited';
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
            if (($live ? 'blocked' : 'active') !== $cred->status) {
                \App\Modules\API\Webhooks::queue('credentials', 'update', $cred->id, ['status' => $live ? 'blocked' : 'active']);
            }
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

    /** RN-HOM-01: los pesos de los criterios suman 100. */
    private function vendorTemplate(array &$values, ?object $current, array &$extra): void
    {
        if ($current === null && ! array_key_exists('criteria', $values)) {
            $extra['criteria'] = ["'[]'::jsonb", []];

            return;
        }
        $c = $values['criteria'] ?? null;
        $c = is_string($c) ? json_decode($c, true) : $c;
        if (is_array($c) && $c !== []) {
            $sum = array_sum(array_map(fn ($x) => (float) ($x['weight'] ?? 0), $c));
            if (abs($sum - 100) > 0.01) {
                $this->fail('criteria', "Los pesos de los criterios deben sumar 100 (suman {$sum}).");
            }
        }
    }

    private function vendor(array &$values, ?object $current, array &$extra): void
    {
        // RN-HOM-04: una evaluación aprobada no se edita (solo puede pasar a vencida).
        if ($current && in_array($current->status, ['approved', 'rejected'], true) && array_diff(array_keys($values), ['status']) !== []) {
            $this->conflict('La evaluación ya fue resuelta por gerencia: no se edita. Crea una nueva para volver a evaluar al proveedor.');
        }
        if ($current && $current->status === 'approved' && ($values['status'] ?? 'approved') !== 'expired' && ($values['status'] ?? 'approved') !== 'approved') {
            $this->fail('status', 'Una evaluación aprobada solo puede pasar a vencida.');
        }
        $to = $values['status'] ?? null;
        if ($current && $to && $to !== $current->status) {
            $flow = ['draft' => ['submitted', 'completed'], 'submitted' => ['completed', 'draft'], 'completed' => ['approved', 'rejected', 'submitted'], 'approved' => ['expired'], 'rejected' => [], 'expired' => []];
            if (! in_array($to, $flow[$current->status] ?? [], true)) {
                $this->fail('status', 'Ese cambio de estado no está permitido: borrador → autoevaluación enviada → validada por SST → aprobada o rechazada por gerencia.');
            }
            // Flujo 2: la homologación la aprueba (o rechaza) gerencia.
            if (in_array($to, ['approved', 'rejected'], true)) {
                if (! in_array('hom.vendor_evaluations.approve', \App\Core\Auth\Permissions::effective(), true)) {
                    $this->fail('status', 'La homologación la aprueba la gerencia.');
                }
                $extra['approved_by'] = ['?', [(string) auth()->id()]];
                $extra['approved_at'] = ['now()', []];
            }
        }
        // RN-HOM-03: al aprobarse vale hasta la fecha de evaluación + validity_months de la plantilla (o vendor_validity_months).
        if ($to === 'approved' && $current?->status !== 'approved' && ! $this->value($values, $current, 'valid_until')) {
            $months = (int) (DB::selectOne('select validity_months from vendor_evaluation_templates where id = ?', [$this->value($values, $current, 'template_id')])?->validity_months
                ?? \App\Core\Support\LegalParams::number('vendor_validity_months', 12));
            $values['valid_until'] = date('Y-m-d', strtotime(($this->value($values, $current, 'evaluated_on') ?? date('Y-m-d'))." +{$months} months"));
        }
        if ($current === null) {
            $extra['scores'] = ["'{}'::jsonb", []];   // se califican después con «Calificar» o con la autoevaluación del proveedor
        }
        $scores = $this->value($values, $current, 'scores');
        if (is_string($scores)) {
            $scores = json_decode($scores, true);
        }
        // RN-HOM-01: puntaje total = suma ponderada por los pesos de la plantilla (sin plantilla o sin pesos, promedio simple).
        if (is_array($scores) && $scores !== []) {
            $tpl = DB::selectOne('select criteria, passing_score from vendor_evaluation_templates where id = ?', [$this->value($values, $current, 'template_id')]);
            $weights = [];
            foreach (json_decode((string) ($tpl->criteria ?? '[]'), true) ?: [] as $c) {
                $weights[$c['name'] ?? ''] = (float) ($c['weight'] ?? 0);
            }
            $num = 0.0;
            $den = 0.0;
            $plain = [];
            foreach ($scores as $name => $v) {
                $sc = is_array($v) ? ($v['score'] ?? null) : $v;
                if (! is_numeric($sc)) {
                    continue;
                }
                $plain[] = (float) $sc;
                $w = $weights[$name] ?? 0;
                $num += (float) $sc * $w;
                $den += $w;
            }
            if ($plain) {
                $total = round($den > 0 ? $num / $den : array_sum($plain) / count($plain), 2);
                $values['total_score'] = $total;
                if ($tpl?->passing_score !== null && ! array_key_exists('rating', $values)) {
                    // RN-HOM-02: aprueba si llega al puntaje mínimo (A); B «con condiciones» la pone SST; C no aprueba.
                    $values['rating'] = $total >= (float) $tpl->passing_score ? 'approved' : 'rejected';
                }
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
        // RN-AMB-01: escalas y umbral son criterios de la empresa (Medio ambiente › Criterios de significancia).
        $c = \App\Modules\AMB\AmbToolsController::criteria();
        if ($sev > $c['severity_max'] || $sev < 0) {
            $this->fail('severity_value', "La severidad va de 1 a {$c['severity_max']}.");
        }
        if ($prob > $c['probability_max'] || $prob < 0) {
            $this->fail('probability_value', "La probabilidad va de 1 a {$c['probability_max']}.");
        }
        $significant = $sev > 0 && $prob > 0 && $sev * $prob >= $c['threshold'];
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
