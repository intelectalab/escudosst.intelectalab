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
            default => null,
        };
    }

    public function after(string $op, array $res, string $id, array $values, ?object $current): void
    {
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
        if ($to === 'cancelled' && ! $this->value($values, $current, 'cancel_reason')) {
            $this->fail('cancel_reason', 'Indica el motivo de la anulación.');
        }
        if ($to === 'attended') {
            $values['attended_at'] = $this->value($values, $current, 'attended_at') ?? now()->toIso8601String();
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
            $values['days'] = (int) ((strtotime($e) - strtotime($s)) / 86400) + 1;
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
        $to = $values['status'] ?? null;
        if ($to === 'submitted') {
            $values['submitted_at'] = now()->toIso8601String();
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
            $extra['reviewed_by'] = ['?', [(string) auth()->id()]];
            $extra['reviewed_at'] = ['now()', []];
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
            $token = self::credentialToken($num);
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
        $reasons = [];
        foreach (DB::select('select * from credential_requirements where credential_type_id = ? and deleted_at is null and is_blocking', [$type]) as $r) {
            $ok = match ($r->requirement_type) {
                'emo_fitness' => $worker
                    ? (bool) DB::selectOne("select 1 as ok from fitness_status where worker_id = ? and is_current and status in ('fit','fit_with_restrictions') and (valid_until is null or valid_until >= current_date) and deleted_at is null", [$worker])
                    : (bool) DB::selectOne("select 1 as ok from contractor_workers where id = ? and emo_fitness in ('fit','fit_with_restrictions') and (emo_valid_until is null or emo_valid_until >= current_date)", [$cworker]),
                'training', 'induction' => $r->training_id && $worker
                    ? (bool) DB::selectOne("select 1 as ok from training_certificates where worker_id = ? and training_id = ? and status = 'validated' and (expires_on is null or expires_on >= current_date) and deleted_at is null", [$worker, $r->training_id])
                    : false,
                'sctr' => (bool) DB::selectOne("select 1 as ok from sctr_coverages where (worker_id = ? or contractor_worker_id = ?) and status = 'covered' and (covered_until is null or covered_until >= current_date) and deleted_at is null", [$worker, $cworker]),
                'accreditation' => $cworker
                    ? (bool) DB::selectOne("select 1 as ok from contractor_accreditations a join contractor_workers w on w.contractor_id = a.contractor_id where w.id = ? and a.status = 'accredited' and (a.expires_on is null or a.expires_on >= current_date) and a.deleted_at is null", [$cworker])
                    : true,
                default => true,
            };
            if (! $ok) {
                $reasons[] = $r->description ?: ['emo_fitness' => 'Aptitud médica vigente', 'training' => 'Capacitación vigente', 'induction' => 'Inducción',
                    'sctr' => 'SCTR vigente', 'accreditation' => 'Contratista acreditado'][$r->requirement_type] ?? $r->requirement_type;
            }
        }
        $expires = $this->value($values, $current, 'expires_on');
        if ($expires && $expires < date('Y-m-d')) {
            $reasons[] = 'Fotocheck vencido';
        }
        $values['status'] = $reasons ? 'blocked' : 'active';
        $values['blocking_reasons'] = $reasons;
        $extra['last_evaluated_at'] = ['now()', []];
    }

    public static function credentialToken(string $number): string
    {
        return strtoupper(substr(hash_hmac('sha256', 'credential|'.$number, (string) config('app.key')), 0, 32));
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
            if (DB::selectOne("select 1 as ok from permit_approvals where work_permit_id = ? and decision <> 'approved' and deleted_at is null", [$current->id])) {
                $this->fail('status', 'Hay aprobaciones pendientes o rechazadas en «Aprobaciones».');
            }
            if (DB::selectOne("select 1 as ok from permit_checks where work_permit_id = ? and check_phase = 'pre_start' and answer = 'no' and deleted_at is null", [$current->id])) {
                $this->fail('status', 'Hay verificaciones previas respondidas «No»: corrígelas antes de aprobar.');
            }
        }
        if ($to === 'active') {
            $until = $this->value($values, $current, 'valid_until');
            if ($until && strtotime($until) < time()) {
                $this->fail('status', 'El permiso ya venció: solicita uno nuevo.');
            }
            $type = DB::selectOne('select requires_ats from permit_types where id = ?', [$current->permit_type_id]);
            if ($type?->requires_ats && ! $this->value($values, $current, 'ats_form_id')) {
                $this->fail('ats_form_id', 'Este tipo de permiso exige vincular el ATS de la tarea.');
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
            $values['closed_at'] = now()->toIso8601String();
            $extra['closed_by'] = ['?', [(string) auth()->id()]];
        }
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
