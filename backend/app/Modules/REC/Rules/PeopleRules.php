<?php

namespace App\Modules\REC\Rules;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Salud, contratistas, permisos y presupuesto (docs/modulos M-EMO, M-DES, M-CTR, M-FOT, M-PTR, M-PRE §8).
 * - EMO: una sola aptitud vigente por trabajador; «apto» necesita fecha de vencimiento; la orden sigue su ciclo.
 * - DES: días = fin − inicio + 1; los tipos ocupacionales quedan como relacionados al trabajo.
 * - CTR: documento del trabajador del contratista cifrado con huella para no duplicar; la acreditación solo
 *   se otorga con todos los requisitos obligatorios aprobados y vigentes, y vence con el primero que venza.
 * - FOT: el fotocheck se evalúa contra sus requisitos (aptitud médica, capacitación, SCTR, acreditación):
 *   activo si cumple todo; bloqueado con los motivos si falta algo.
 * - PTR: código automático; no se aprueba con aprobaciones pendientes ni verificaciones previas en «no»;
 *   no se activa vencido ni sin ATS si el tipo lo exige; se cierra con el área dejada segura.
 * - PRE: el total planificado y ejecutado del presupuesto se recalcula con sus partidas y ejecuciones.
 */
class PeopleRules extends RecordRules
{
    private const PTR_FLOW = ['draft' => ['pending_approval'], 'pending_approval' => ['approved', 'rejected', 'draft'], 'approved' => ['active', 'rejected'],
        'active' => ['suspended', 'closed'], 'suspended' => ['active', 'closed'], 'rejected' => [], 'closed' => [], 'expired' => []];

    public function prepare(string $op, array $res, array &$values, ?object $current, array &$extra): void
    {
        if (in_array($res['table'], ['notifications', 'notification_deliveries'], true) && $op === 'create') {
            $this->conflict('Los avisos los genera el sistema: no se crean a mano.');
        }
        match ($res['table']) {
            'fitness_status' => $this->fitness($op, $values, $current, $extra),
            'medical_exam_orders' => $this->examOrder($op, $values, $current, $extra),
            'absences' => $this->absence($values, $current, $extra),
            'contractor_workers' => $this->contractorWorker($values, $current, $extra),
            'contractor_documents' => $this->contractorDocument($op, $values, $current, $extra),
            'contractor_accreditations' => $this->accreditation($values, $current, $extra),
            'credentials' => $this->credential($op, $values, $current, $extra),
            'work_permits' => $this->permit($op, $values, $current, $extra),
            'budget_executions' => $this->budgetExecution($values, $current, $extra),
            'budgets' => $this->budget($op, $values, $current, $extra),
            'permit_approvals' => $this->permitApproval($values, $current, $extra),
            'permit_checks' => $this->permitCheck($values, $current, $extra),
            'budget_lines' => $this->budgetLine($values, $current),
            default => null,
        };
    }

    // ── Presupuesto SST ──

    private function budget(string $op, array &$values, ?object $current, array &$extra): void
    {
        unset($values['approved_by'], $values['approved_at']);   // los pone la aprobación
        if ($op === 'create') {
            $values['status'] = 'draft';

            return;
        }
        $to = $values['status'] ?? null;
        if ($to && $to !== $current->status) {
            $flow = ['draft' => ['in_review', 'approved'], 'in_review' => ['approved', 'draft'], 'approved' => ['in_execution', 'closed'], 'in_execution' => ['closed'], 'closed' => []];
            if (! in_array($to, $flow[$current->status] ?? [], true)) {
                $this->fail('status', in_array($current->status, ['approved', 'in_execution', 'closed'], true)
                    ? 'El presupuesto ya fue aprobado: no vuelve a borrador. Si cambia, registra un presupuesto nuevo o una línea adicional aprobada.'
                    : 'Ese cambio de estado no está permitido.');
            }
            if ($to === 'approved') {
                if (! DB::selectOne('select 1 as ok from budget_lines where budget_id = ? and deleted_at is null and planned_amount > 0', [$current->id])) {
                    $this->fail('status', 'Agrega al menos una línea con monto antes de aprobar el presupuesto.');
                }
                $extra['approved_by'] = ['?', [(string) auth()->id()]];
                $extra['approved_at'] = ['now()', []];
            }
        }
    }

    private function budgetLine(array &$values, ?object $current): void
    {
        $st = DB::selectOne('select status from budgets where id = ?', [$this->value($values, $current, 'budget_id')])?->status;
        if (in_array($st, ['approved', 'in_execution', 'closed'], true) && array_diff(array_keys($values), ['executed_amount']) !== []) {
            $this->conflict('El presupuesto ya está aprobado: sus líneas no se cambian. Registra los gastos en «Ejecución».');
        }
        if ((float) ($values['planned_amount'] ?? 0) < 0) {
            $this->fail('planned_amount', 'El monto no puede ser negativo.');
        }
    }

    public function after(string $op, array $res, string $id, array $values, ?object $current): void
    {
        if ($res['table'] === 'work_permits' && $op === 'create') {
            $p = DB::selectOne('select w.company_id, t.approval_chain from work_permits w join permit_types t on t.id = w.permit_type_id where w.id = ?', [$id]);
            foreach (json_decode($p->approval_chain ?? '[]', true) ?: [] as $i => $step) {
                if (! empty($step['optional']) || empty($step['role'])) {
                    continue;
                }
                DB::insert("insert into permit_approvals (company_id, work_permit_id, step_order, approver_role, decision, created_by, updated_by) values (?, ?, ?, ?, 'pending', ?, ?)",
                    [$p->company_id, $id, (int) ($step['step'] ?? $i + 1), $step['role'], (string) auth()->id(), (string) auth()->id()]);
            }
        }
        if ($res['table'] === 'fitness_status' && ($op === 'create' || ($values['is_current'] ?? false) === true)) {
            $f = DB::selectOne('select worker_id, medical_exam_order_id from fitness_status where id = ?', [$id]);
            DB::update('update fitness_status set is_current = false where worker_id = ? and id <> ? and is_current', [$f->worker_id, $id]);
            if ($f->medical_exam_order_id) {
                DB::update("update medical_exam_orders set status = 'fitness_issued' where id = ? and status <> 'cancelled'", [$f->medical_exam_order_id]);
            }
        }
        if (in_array($res['table'], ['budget_lines', 'budget_executions'], true)) {
            $line = $res['table'] === 'budget_lines' ? $id : DB::selectOne('select budget_line_id from budget_executions where id = ?', [$id])->budget_line_id;
            DB::update('update budget_lines set executed_amount = (select coalesce(sum(amount), 0) from budget_executions where budget_line_id = ? and deleted_at is null) where id = ?', [$line, $line]);
            DB::update(
                "update budgets b set total_planned = x.p, total_executed = x.e,
                        status = case when b.status = 'approved' and x.e > 0 then 'in_execution' else b.status end
                   from (select coalesce(sum(planned_amount), 0) as p, coalesce(sum(executed_amount), 0) as e from budget_lines
                          where budget_id = (select budget_id from budget_lines where id = ?) and deleted_at is null) x
                  where b.id = (select budget_id from budget_lines where id = ?)",
                [$line, $line]
            );
        }
    }

    // ── Salud ──

    private function fitness(string $op, array &$values, ?object $current, array &$extra): void
    {
        $status = $this->value($values, $current, 'status');
        if (in_array($status, ['fit', 'fit_with_restrictions'], true) && ! $this->value($values, $current, 'valid_until')) {
            $this->fail('valid_until', 'Indica hasta cuándo es válida la aptitud.');
        }
        if ($status === 'fit_with_restrictions' && ! $this->value($values, $current, 'work_restrictions')) {
            $this->fail('work_restrictions', 'Describe las restricciones de trabajo.');
        }
        $order = $this->value($values, $current, 'medical_exam_order_id');
        if ($order && in_array(DB::selectOne('select status from medical_exam_orders where id = ?', [$order])?->status, ['scheduled', 'no_show', 'cancelled'], true)) {
            $this->fail('medical_exam_order_id', 'La orden aún no figura como atendida: registra la atención del examen antes de emitir la aptitud.');
        }
        if ($op === 'create') {
            $values['is_current'] ??= true;
            $values['valid_from'] ??= date('Y-m-d');
            $extra['issued_by'] = ['?', [(string) auth()->id()]];
            $extra['issued_at'] = ['now()', []];
        }
    }

    private function examOrder(string $op, array &$values, ?object $current, array &$extra): void
    {
        if ($op === 'create') {
            $values['status'] ??= 'scheduled';
            $extra['requested_by'] = ['?', [(string) auth()->id()]];
        }
        $to = $values['status'] ?? null;
        if ($current && $to && $to !== $current->status) {
            $flow = ['scheduled' => ['attended', 'no_show', 'cancelled'], 'no_show' => ['scheduled', 'cancelled'], 'attended' => ['results_received', 'fitness_issued', 'cancelled'],
                'results_received' => ['fitness_issued'], 'fitness_issued' => [], 'cancelled' => []];
            if (! in_array($to, $flow[$current->status] ?? [], true)) {
                $this->fail('status', 'Ese cambio de estado no está permitido para la orden de examen.');
            }
        }
        if ($to === 'cancelled' && ! $this->value($values, $current, 'cancel_reason')) {
            $this->fail('cancel_reason', 'Indica el motivo de la anulación.');
        }
        if ($to === 'attended') {
            $at = $this->value($values, $current, 'attended_at') ?? now()->toIso8601String();
            $sched = $this->value($values, $current, 'scheduled_at');
            if ($sched && strtotime($sched) > time() + 12 * 3600) {
                $this->fail('status', 'El examen está programado para el '.date('d/m/Y', strtotime($sched)).': márcalo como atendido ese día.');
            }
            $values['attended_at'] = $at;
        }
    }

    private function absence(array &$values, ?object $current, array &$extra): void
    {
        $s = $this->value($values, $current, 'starts_on');
        $e = $this->value($values, $current, 'ends_on');
        if ($s && $e) {
            if ($e < $s) {
                $this->fail('ends_on', 'La fecha de fin no puede ser anterior a la de inicio.');
            }
        }
        $type = $this->value($values, $current, 'absence_type');
        if (in_array($type, ['occupational_accident', 'occupational_disease'], true)) {
            $values['is_work_related'] = true;
        }
        if ($current === null) {
            $extra['registered_by'] = ['?', [(string) auth()->id()]];
        }
    }

    // ── Contratistas y fotocheck ──

    private function contractorWorker(array &$values, ?object $current, array &$extra): void
    {
        if (! array_key_exists('document_number', $values) && ! array_key_exists('document_type', $values)) {
            return;
        }
        $type = $this->value($values, $current, 'document_type') ?? 'DNI';
        $number = strtoupper(preg_replace('/[\s.\-]/', '', (string) ($values['document_number'] ?? '')));
        if ($number === '') {
            $this->fail('document_number', 'Completa el número de documento.');
        }
        if ($type === 'DNI' && ! preg_match('/^\d{8}$/', $number)) {
            $this->fail('document_number', 'El DNI tiene 8 dígitos.');
        }
        $hash = hash_hmac('sha256', 'contractor-doc|'.$type.'|'.$number, (string) config('app.key'));
        $contractor = $this->value($values, $current, 'contractor_id');
        if (DB::selectOne("select 1 as ok from contractor_workers where contractor_id = ? and document_number_hash = decode(?, 'hex') and deleted_at is null".($current ? ' and id <> ?' : ''),
            $current ? [$contractor, $hash, $current->id] : [$contractor, $hash])) {
            $this->fail('document_number', 'Ese trabajador ya está registrado para este contratista.');
        }
        $values['document_number'] = $number;
        $extra['document_number_hash'] = ["decode(?, 'hex')", [$hash]];
        $extra['document_last4'] = ['?', [substr($number, -4)]];
    }

    private function contractorDocument(string $op, array &$values, ?object $current, array &$extra): void
    {
        if ($op === 'create') {
            $values['status'] ??= 'submitted';
            $extra['uploaded_by'] = ['?', [(string) auth()->id()]];
        }
        if (in_array($values['status'] ?? null, ['approved', 'observed', 'rejected'], true)) {
            if (in_array($values['status'], ['observed', 'rejected'], true) && ! $this->value($values, $current, 'review_comments')) {
                $this->fail('review_comments', 'Explica qué se debe corregir.');
            }
            $extra['reviewed_by'] = ['?', [(string) auth()->id()]];
            $extra['reviewed_at'] = ['now()', []];
        }
        $exp = $this->value($values, $current, 'expires_on');
        if (($values['status'] ?? null) === 'approved' && $exp && $exp < date('Y-m-d')) {
            $this->fail('expires_on', 'El documento está vencido: no se puede aprobar.');
        }
    }

    private function accreditation(array &$values, ?object $current, array &$extra): void
    {
        if ($current === null && in_array($values['status'] ?? 'draft', ['accredited', 'expiring', 'expired', 'suspended', 'observed', 'rejected', 'under_review'], true)) {
            $values['status'] = 'draft';   // se acredita después de revisar sus documentos, no al crearla
        }
        $to = $values['status'] ?? null;
        if ($to === 'submitted') {
            $values['submitted_at'] = now()->toIso8601String();
        }
        if ($current && $to === 'accredited' && $current->status === 'suspended' && ! $this->value($values, $current, 'observations')) {
            $this->fail('observations', 'Para levantar la suspensión indica qué se corrigió.');
        }
        if ($to === 'accredited' && $current && (string) $current->reviewed_by === (string) auth()->id() && $current->status === 'observed') {
            $this->fail('status', 'Tú registraste las observaciones: otra persona del área SST debe aprobar la acreditación (separación de funciones).'.\App\Core\Support\Approvers::hint('ctr.contractor_accreditations.update'));
        }
        if ($to === 'accredited' && $current) {
            $missing = DB::select(
                "select r.name from contractor_requirements r
                  where r.deleted_at is null and r.is_active and r.is_mandatory and r.applies_to = 'company'
                    and not exists (select 1 from contractor_documents d where d.contractor_id = ? and d.requirement_id = r.id and d.deleted_at is null
                                     and d.status = 'approved' and (d.expires_on is null or d.expires_on >= current_date))",
                [$current->contractor_id]
            );
            if ($missing) {
                $this->fail('status', 'Faltan requisitos aprobados y vigentes: '.implode(', ', array_column($missing, 'name')).'.');
            }
            $first = DB::selectOne("select min(expires_on) as d from contractor_documents where contractor_id = ? and status = 'approved' and deleted_at is null and expires_on is not null",
                [$current->contractor_id])->d;
            $values['expires_on'] = $this->value($values, $current, 'expires_on') ?? $first;
            $extra['approved_by'] = ['?', [(string) auth()->id()]];
            $extra['approved_at'] = ['now()', []];
        }
        if (in_array($to, ['observed', 'rejected', 'suspended'], true)) {
            $field = ['observed' => 'observations', 'rejected' => 'rejection_reason', 'suspended' => 'suspended_reason'][$to];
            if (! $this->value($values, $current, $field)) {
                $this->fail($field, 'Indica el motivo.');
            }
            if ($to !== 'suspended') {
                $extra['reviewed_by'] = ['?', [(string) auth()->id()]];
                $extra['reviewed_at'] = ['now()', []];
            }
        }
    }

    private function credential(string $op, array &$values, ?object $current, array &$extra): void
    {
        $type = $this->value($values, $current, 'credential_type_id');
        $worker = $this->value($values, $current, 'worker_id');
        $cworker = $this->value($values, $current, 'contractor_worker_id');
        if (! $worker && ! $cworker) {
            $this->fail('worker_id', 'Elige al trabajador (propio o del contratista).');
        }
        if ($op === 'create') {
            $n = (int) DB::selectOne('select count(*) + 1 as n from credentials')->n;
            do {
                $num = sprintf('FOT-%s-%05d', date('Y'), $n++);
            } while (DB::selectOne('select 1 as ok from credentials where credential_number = ?', [$num]));
            $extra['credential_number'] = ['?', [$num]];
            // Token del QR derivado del número (se puede volver a imprimir); en la base solo queda su hash.
            $token = self::credentialToken(app(\App\Core\Tenancy\CompanyContext::class)->companyId, $num);
            $extra['token_hash'] = ["decode(?, 'hex')", [hash('sha256', $token)]];
            $extra['token_last4'] = ['?', [substr($token, -4)]];
            $extra['qr_payload_version'] = ['?', [1]];
            $extra['issued_by'] = ['?', [(string) auth()->id()]];
            $extra['issued_at'] = ['now()', []];
            $months = (int) (DB::selectOne('select validity_months from credential_types where id = ?', [$type])?->validity_months ?? 12);
            $values['valid_from'] ??= date('Y-m-d');
            $values['expires_on'] ??= date('Y-m-d', strtotime("+{$months} months"));
        }
        if (($values['status'] ?? null) === 'revoked') {
            if (! $this->value($values, $current, 'revoked_reason')) {
                $this->fail('revoked_reason', 'Indica el motivo de la revocación.');
            }
            $extra['revoked_at'] = ['now()', []];

            return;
        }
        if ($current && in_array($current->status, ['revoked'], true)) {
            $this->conflict('El fotocheck está revocado.');
        }
        // Evaluación de requisitos (RN-FOT): activo si cumple todos los bloqueantes; si no, bloqueado con motivos.
        $reasons = self::credentialReasons($type, $worker, $cworker, $this->value($values, $current, 'expires_on'));
        $values['status'] = $reasons ? 'blocked' : 'active';
        $values['blocking_reasons'] = $reasons;
        $extra['last_evaluated_at'] = ['now()', []];
    }

    /** Token del QR: depende de la empresa y del número (dos empresas pueden tener el mismo número). */
    public static function credentialToken(string $companyId, string $number): string
    {
        return strtoupper(substr(hash_hmac('sha256', 'credential|'.$companyId.'|'.$number, (string) config('app.key')), 0, 32));
    }

    /**
     * Motivos por los que un fotocheck no habilita (vacío = habilitado). La usan el registro y la revisión diaria.
     * @return list<string>
     */
    public static function credentialReasons(?string $type, ?string $worker, ?string $cworker, ?string $expires): array
    {
        return array_column(self::credentialReasonItems($type, $worker, $cworker, $expires), 'text');
    }

    /**
     * Igual que credentialReasons, con el tipo de cada requisito que falta (emo_fitness, training, induction, sctr,
     * accreditation, expired): portería lo usa para guardar un motivo neutro, sin términos médicos (RN-ING-02).
     * @return list<array{type: string, text: string}>
     */
    public static function credentialReasonItems(?string $type, ?string $worker, ?string $cworker, ?string $expires): array
    {
        $reasons = [];
        foreach (DB::select('select * from credential_requirements where credential_type_id = ? and deleted_at is null and is_blocking', [$type]) as $r) {
            $ok = match ($r->requirement_type) {
                'emo_fitness' => $worker
                    ? (bool) DB::selectOne("select 1 as ok from fitness_status where worker_id = ? and is_current and status in ('fit','fit_with_restrictions') and (valid_until is null or valid_until >= current_date) and deleted_at is null", [$worker])
                    : (bool) DB::selectOne("select 1 as ok from contractor_workers where id = ? and emo_fitness in ('fit','fit_with_restrictions') and (emo_valid_until is null or emo_valid_until >= current_date)", [$cworker]),
                'training', 'induction' => $r->training_id
                    && (bool) DB::selectOne("select 1 as ok from training_certificates where (worker_id = ? or contractor_worker_id = ?) and training_id = ? and status = 'validated'
                                              and (expires_on is null or expires_on >= current_date) and deleted_at is null", [$worker, $cworker, $r->training_id]),
                'sctr' => (int) DB::selectOne("select count(distinct p.coverage_kind) as n from sctr_coverages c join sctr_policies p on p.id = c.sctr_policy_id
                                                 where (c.worker_id = ? or c.contractor_worker_id = ?) and c.status = 'covered' and c.deleted_at is null
                                                   and (c.covered_from is null or c.covered_from <= current_date) and (c.covered_until is null or c.covered_until >= current_date)
                                                   and p.deleted_at is null and p.status in ('active', 'expiring') and (p.ends_on is null or p.ends_on >= current_date)
                                                   and p.coverage_kind in ('health', 'pension')", [$worker, $cworker])->n === 2,
                'accreditation' => $cworker
                    ? (bool) DB::selectOne("select 1 as ok from contractor_accreditations a join contractor_workers w on w.contractor_id = a.contractor_id where w.id = ? and a.status = 'accredited' and (a.expires_on is null or a.expires_on >= current_date) and a.deleted_at is null", [$cworker])
                    : true,
                default => true,
            };
            if (! $ok) {
                $reasons[] = ['type' => (string) $r->requirement_type, 'text' => $r->description ?: ['emo_fitness' => 'Aptitud médica vigente', 'training' => 'Capacitación vigente', 'induction' => 'Inducción',
                    'sctr' => 'SCTR salud y pensión vigentes', 'accreditation' => 'Contratista acreditado'][$r->requirement_type] ?? $r->requirement_type];
            }
        }
        if ($expires && $expires < date('Y-m-d')) {
            $reasons[] = ['type' => 'expired', 'text' => 'Fotocheck vencido'];
        }

        return $reasons;
    }

    // ── Permisos de trabajo ──

    private function permit(string $op, array &$values, ?object $current, array &$extra): void
    {
        if ($op === 'create') {
            if (! isset($values['code'])) {
                $n = (int) DB::selectOne('select count(*) + 1 as n from work_permits where extract(year from created_at) = extract(year from now())')->n;
                do {
                    $values['code'] = sprintf('PTR-%s-%04d', date('Y'), $n++);
                } while (DB::selectOne('select 1 as ok from work_permits where code = ? and deleted_at is null', [$values['code']]));
            }
            $values['status'] = 'draft';
            $extra['requested_by'] = ['?', [(string) auth()->id()]];
            if (($values['valid_from'] ?? null) && ! isset($values['valid_until'])) {
                $hours = (int) (DB::selectOne('select validity_hours from permit_types where id = ?', [$values['permit_type_id']])?->validity_hours ?? 12);
                $values['valid_until'] = date(DATE_ATOM, strtotime($values['valid_from']) + $hours * 3600);
            }

            return;
        }
        if (in_array($current->status, ['closed', 'rejected', 'expired'], true) && $values !== []) {
            $this->conflict('El permiso está cerrado: no se modifica.');
        }
        $to = $values['status'] ?? null;
        if (! $to || $to === $current->status) {
            return;
        }
        if (! in_array($to, self::PTR_FLOW[$current->status] ?? [], true)) {
            $this->fail('status', 'Ese cambio de estado no está permitido.');
        }
        if ($to === 'approved') {
            if (DB::selectOne("select 1 as ok from permit_checks where work_permit_id = ? and check_phase = 'pre_start' and answer = 'no' and deleted_at is null", [$current->id])) {
                $this->fail('status', 'Hay verificaciones previas respondidas «No»: corrígelas antes de aprobar.');
            }
            $missing = $this->permitChecksMissing($current, 'pre_start');
            if ($missing) {
                $this->fail('status', 'Faltan verificaciones previas: '.implode('; ', array_slice($missing, 0, 4)).(count($missing) > 4 ? '…' : '').'.');
            }
            if ((string) $current->requested_by === (string) auth()->id()) {
                $this->fail('status', 'Quien solicita el permiso no lo aprueba: lo aprueban los responsables de «Aprobaciones».'.\App\Core\Support\Approvers::hint('ptr.work_permits.update'));
            }
            if (! DB::selectOne("select 1 as ok from permit_approvals where work_permit_id = ? and decision = 'approved' and deleted_at is null", [$current->id])) {
                $this->fail('status', 'El permiso no tiene ninguna aprobación registrada.');
            }
            if (DB::selectOne("select 1 as ok from permit_approvals where work_permit_id = ? and decision <> 'approved' and deleted_at is null", [$current->id])) {
                $this->fail('status', 'Hay aprobaciones pendientes o rechazadas en «Aprobaciones».');
            }
        }
        if ($to === 'active') {
            $until = $this->value($values, $current, 'valid_until');
            if ($until && strtotime($until) < time()) {
                $this->fail('status', 'El permiso ya venció: solicita uno nuevo.');
            }
            $from = $this->value($values, $current, 'valid_from');
            if ($from && strtotime($from) > time() + 300) {
                $this->fail('status', 'El permiso todavía no está vigente: empieza el '.date('d/m/Y H:i', strtotime($from)).'.');
            }
            $type = DB::selectOne('select requires_ats, requires_gas_test from permit_types where id = ?', [$current->permit_type_id]);
            $ats = $this->value($values, $current, 'ats_form_id');
            if ($type?->requires_ats && ! $ats) {
                $this->fail('ats_form_id', 'Este tipo de permiso exige vincular el ATS de la tarea.');
            }
            if ($ats && ! DB::selectOne("select 1 as ok from ats_forms where id = ? and status in ('signed', 'in_execution') and deleted_at is null", [$ats])) {
                $this->fail('ats_form_id', 'El ATS vinculado debe estar firmado (o en ejecución), no cerrado ni en borrador.');
            }
            $gas = $this->value($values, $current, 'gas_measurements');
            if ($type?->requires_gas_test && (empty($gas) || $gas === '[]' || $gas === '{}')) {
                $this->fail('gas_measurements', 'Este trabajo exige medición de gases (O₂, LEL, CO, H₂S) antes de iniciarlo.');
            }
            $values['activated_at'] = now()->toIso8601String();
        }
        if ($to === 'suspended' && ! $this->value($values, $current, 'suspended_reason')) {
            $this->fail('suspended_reason', 'Indica por qué se suspende el trabajo.');
        }
        if ($to === 'closed') {
            if (! $this->value($values, $current, 'area_left_safe')) {
                $this->fail('area_left_safe', 'Para cerrar el permiso confirma que el área quedó segura.');
            }
            $missing = $this->permitChecksMissing($current, 'closure');
            if ($missing) {
                $this->fail('status', 'Faltan verificaciones de cierre: '.implode('; ', $missing).'.');
            }
            $values['closed_at'] = now()->toIso8601String();
            $extra['closed_by'] = ['?', [(string) auth()->id()]];
        }
    }

    /** Preguntas de la lista del tipo de permiso (fase dada) que no tienen respuesta «Sí» o «No aplica». */
    private function permitChecksMissing(object $permit, string $phase): array
    {
        $list = json_decode(DB::selectOne('select checklist from permit_types where id = ?', [$permit->permit_type_id])?->checklist ?? '[]', true) ?: [];
        $missing = [];
        foreach ($list as $q) {
            if (($q['phase'] ?? 'pre_start') !== $phase) {
                continue;
            }
            $ok = DB::selectOne("select 1 as ok from permit_checks where work_permit_id = ? and check_phase = ? and (item_code = ? or question = ?) and answer in ('yes','na') and deleted_at is null",
                [$permit->id, $phase, (string) ($q['code'] ?? ''), (string) ($q['question'] ?? '')]);
            if (! $ok) {
                $missing[] = $q['question'] ?? ($q['code'] ?? '');
            }
        }

        return $missing;
    }

    private function permitApproval(array &$values, ?object $current, array &$extra): void
    {
        $permit = DB::selectOne('select status, requested_by from work_permits where id = ?', [$this->value($values, $current, 'work_permit_id')]);
        if (! in_array($permit?->status, ['draft', 'pending_approval'], true)) {
            $this->conflict('Las aprobaciones se registran mientras el permiso espera aprobación.');
        }
        if (in_array($values['decision'] ?? null, ['approved', 'rejected'], true)) {
            // Firma quien está conectado, en este momento; no se firma por otra persona.
            if ((string) $permit->requested_by === (string) auth()->id()) {
                $this->fail('decision', 'Quien solicita el permiso no puede aprobarlo.');
            }
            $role = $this->value($values, $current, 'approver_role');
            $mine = app(\App\Core\Tenancy\CompanyContext::class)->roleCode;
            if ($role && $role !== $mine && ! in_array($mine, ['sst_manager', 'account_admin'], true)) {
                $this->fail('decision', 'Este paso lo aprueba el rol «'.$role.'».');
            }
            $values['approver_user_id'] = (string) auth()->id();
            $values['decided_at'] = now()->toIso8601String();
        }
    }

    private function permitCheck(array &$values, ?object $current, array &$extra): void
    {
        $st = DB::selectOne('select status from work_permits where id = ?', [$this->value($values, $current, 'work_permit_id')])?->status;
        if (in_array($st, ['closed', 'rejected', 'expired'], true)) {
            $this->conflict('El permiso está cerrado: no se agregan verificaciones.');
        }
        $values['checked_by'] = (string) auth()->id();
        $values['checked_at'] = now()->toIso8601String();
    }

    private function budgetExecution(array &$values, ?object $current, array &$extra): void
    {
        $st = DB::selectOne('select b.status from budget_lines l join budgets b on b.id = l.budget_id where l.id = ?', [$this->value($values, $current, 'budget_line_id')])?->status;
        if (! in_array($st, ['approved', 'in_execution'], true)) {
            $this->conflict('Los gastos se registran cuando el presupuesto está aprobado.');
        }
        if ((float) $this->value($values, $current, 'amount') <= 0) {
            $this->fail('amount', 'El monto debe ser mayor que cero.');
        }
        $values['executed_on'] ??= date('Y-m-d');
        if ($current === null) {
            $extra['recorded_by'] = ['?', [(string) auth()->id()]];
        }
    }
}
