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
        // M-PTR §10: aprobación pendiente (a quien tiene el rol del primer paso) y permiso suspendido (al solicitante y SST).
        if ($res['table'] === 'work_permits' && ($values['status'] ?? null) && $values['status'] !== $current?->status) {
            $p = DB::selectOne('select id, code, work_description, requested_by, suspended_reason from work_permits where id = ?', [$id]);
            $link = '/m/ptr?t=work_permits&open='.$id;
            if ($values['status'] === 'pending_approval') {
                $role = DB::selectOne("select approver_role from permit_approvals where work_permit_id = ? and decision = 'pending' and deleted_at is null order by step_order limit 1", [$id])?->approver_role;
                if ($role) {
                    \App\Modules\NOT\Notify::users(\App\Modules\NOT\Notify::roles([$role]), 'ptr.approval_pending', 'PTR', $id, 'Permiso de trabajo por aprobar',
                        "El permiso {$p->code} · ".mb_substr((string) $p->work_description, 0, 120).' espera tu aprobación.', $link, 'high', 'ptr.approval_pending:'.$id);
                }
            }
            if ($values['status'] === 'suspended') {
                \App\Modules\NOT\Notify::users([$p->requested_by, ...\App\Modules\NOT\Notify::roles(['sst_manager', 'sst_supervisor'])], 'ptr.suspended', 'PTR', $id, 'Permiso de trabajo suspendido',
                    "Se suspendió el permiso {$p->code}: ".mb_substr((string) $p->suspended_reason, 0, 200), $link, 'high');
            }
        }
        // M-CTR §10: acreditación aprobada o suspendida → aviso al administrador del contratista y al equipo SST.
        if ($res['table'] === 'contractor_accreditations' && in_array($values['status'] ?? null, ['accredited', 'suspended', 'observed', 'rejected'], true) && $values['status'] !== $current?->status) {
            $a = DB::selectOne('select a.id, a.contractor_id, a.service_description, a.suspended_reason, a.observations, a.rejection_reason, c.legal_name from contractor_accreditations a
                                  join contractors c on c.id = a.contractor_id where a.id = ?', [$id]);
            $portal = array_column(DB::select("select m.user_id from membership_scopes s join memberships m on m.id = s.membership_id and m.status = 'active' and m.deleted_at is null
                                                where s.scope_type = 'contractor' and s.contractor_id = ? and s.deleted_at is null", [$a->contractor_id]), 'user_id');
            $what = ['accredited' => 'quedó acreditada', 'suspended' => 'quedó suspendida', 'observed' => 'tiene observaciones', 'rejected' => 'fue rechazada'][$values['status']];
            $why = $a->suspended_reason ?? $a->observations ?? $a->rejection_reason;
            \App\Modules\NOT\Notify::users([...$portal, ...\App\Modules\NOT\Notify::roles(['sst_supervisor'])], 'ctr.accreditation_'.$values['status'], 'CTR', $id,
                'Acreditación de contratista: '.$what, "La acreditación de {$a->legal_name} para «".mb_substr((string) $a->service_description, 0, 80)."» {$what}".($why && $values['status'] !== 'accredited' ? ": {$why}" : '').'.',
                '/m/ctr?t=contractor_accreditations&open='.$id, $values['status'] === 'accredited' ? 'normal' : 'high', 'ctr.accreditation:'.$id.':'.$values['status']);
        }
        if ($res['table'] === 'absences') {
            self::syncLeave(DB::connection(), DB::selectOne('select worker_id from absences where id = ?', [$id])->worker_id);
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
        // RN-DES-02: un descanso por accidente de trabajo se vincula al evento registrado en ACC.
        if ($type === 'occupational_accident' && ! $this->value($values, $current, 'sst_event_id')) {
            $this->fail('sst_event_id', 'Vincula el accidente de trabajo registrado que originó este descanso.');
        }
        $worker = $this->value($values, $current, 'worker_id');
        $status = $this->value($values, $current, 'status') ?? 'registered';
        // RN-DES-06: la prórroga continúa el descanso anterior del mismo trabajador, desde el día siguiente a su fin.
        if ($prev = $this->value($values, $current, 'previous_absence_id')) {
            $p = DB::selectOne('select worker_id, ends_on, status from absences where id = ? and deleted_at is null', [$prev]);
            if (! $p || $p->worker_id !== $worker || $p->status === 'cancelled') {
                $this->fail('previous_absence_id', 'La prórroga debe continuar un descanso vigente del mismo trabajador.');
            }
            if ($s && $s !== date('Y-m-d', strtotime($p->ends_on.' +1 day'))) {
                $this->fail('starts_on', 'La prórroga empieza el día siguiente al fin del descanso anterior ('.date('d/m/Y', strtotime($p->ends_on.' +1 day')).').');
            }
        }
        // RN-DES-04: los descansos del mismo trabajador no se superponen.
        if ($worker && $s && $e && $status !== 'cancelled') {
            $other = DB::selectOne(
                "select starts_on, ends_on from absences where worker_id = ? and id <> ? and status <> 'cancelled' and deleted_at is null
                    and daterange(starts_on, ends_on, '[]') && daterange(?::date, ?::date, '[]') limit 1",
                [$worker, $current?->id ?? '00000000-0000-0000-0000-000000000000', $s, $e]
            );
            if ($other) {
                $this->fail('starts_on', 'Se cruza con otro descanso del trabajador (del '.date('d/m/Y', strtotime($other->starts_on)).' al '.date('d/m/Y', strtotime($other->ends_on)).'). Si es una prórroga, regístrala desde el día siguiente.');
            }
        }
        if ($current === null) {
            $extra['registered_by'] = ['?', [(string) auth()->id()]];
        }
    }

    /**
     * RN-DES-05: con un descanso vigente el trabajador queda «en licencia»; al terminar su último descanso vuelve a «activo».
     * Sin $worker revisa a todos (lo usa la revisión diaria). No toca a cesados ni inactivos.
     */
    public static function syncLeave(\Illuminate\Database\ConnectionInterface $db, ?string $worker = null): void
    {
        $today = "(now() at time zone 'America/Lima')::date";
        $current = "select 1 from absences a where a.worker_id = w.id and a.deleted_at is null and a.status in ('registered', 'validated')
                      and a.starts_on <= {$today} and a.ends_on >= {$today}";
        $filter = $worker ? ' and w.id = ?' : '';
        $args = $worker ? [$worker] : [];
        $db->update("update workers w set status = 'on_leave', updated_at = now() where w.status = 'active' and w.deleted_at is null and exists ({$current}){$filter}", $args);
        $db->update("update workers w set status = 'active', updated_at = now() where w.status = 'on_leave' and w.deleted_at is null and not exists ({$current})
                       and exists (select 1 from absences a where a.worker_id = w.id and a.deleted_at is null){$filter}", $args);
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
        $explicitExpiry = ! empty($values['expires_on']);
        if (! $worker && ! $cworker) {
            $this->fail('worker_id', 'Elige al trabajador (propio o del contratista).');
        }
        if ($op === 'create') {
            // RN-FOT-09: varias credenciales de tipos distintos, pero una sola vigente por tipo.
            if (DB::selectOne("select 1 as ok from credentials where credential_type_id = ? and (worker_id = ? or contractor_worker_id = ?)
                                 and status in ('draft', 'issued', 'active', 'blocked') and deleted_at is null", [$type, $worker, $cworker])) {
                $this->fail('credential_type_id', 'El trabajador ya tiene un fotocheck vigente de este tipo: revoca el anterior antes de emitir otro.');
            }
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
            $values['valid_from'] ??= date('Y-m-d');
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
        // RN-FOT-02: vence con el primero de sus requisitos que venza (sin pasar la vigencia del tipo), salvo fecha indicada a mano.
        $expires = $explicitExpiry ? $values['expires_on'] : self::credentialExpiry($type, $this->value($values, $current, 'valid_from'), $worker, $cworker);
        $values['expires_on'] = $expires;
        $reasons = self::credentialReasons($type, $worker, $cworker, $expires);
        $values['status'] = $reasons ? 'blocked' : 'active';
        $values['blocking_reasons'] = $reasons;
        $extra['last_evaluated_at'] = ['now()', []];
    }

    /** RN-FOT-02: fin de vigencia del fotocheck = lo que vence primero entre la vigencia del tipo y sus requisitos cumplidos. */
    public static function credentialExpiry(?string $type, ?string $validFrom, ?string $worker, ?string $cworker): string
    {
        $months = (int) (DB::selectOne('select validity_months from credential_types where id = ?', [$type])?->validity_months ?? 12);
        $base = date('Y-m-d', strtotime(($validFrom ?: date('Y-m-d'))." +{$months} months"));
        $until = self::credentialEvaluation($type, $worker, $cworker, null)['until'];

        return $until && $until < $base ? $until : $base;
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
        return self::credentialEvaluation($type, $worker, $cworker, $expires)['reasons'];
    }

    /**
     * Evalúa los requisitos bloqueantes del tipo de fotocheck:
     *  - RN-FOT-01: solo los que aplican al puesto del trabajador (sin puesto en el requisito = aplica a todos).
     *  - RN-FOT-13: un requisito con días de gracia no bloquea a quien ingresó hace menos de esos días.
     *  - RN-FOT-02: «until» = el vencimiento más próximo de los requisitos cumplidos (null si ninguno vence).
     * @return array{reasons: list<array{type: string, text: string}>, until: ?string}
     */
    public static function credentialEvaluation(?string $type, ?string $worker, ?string $cworker, ?string $expires): array
    {
        $reasons = [];
        $dates = [];
        $w = $worker ? DB::selectOne('select current_position_id, hire_on from workers where id = ?', [$worker]) : null;
        $today = date('Y-m-d');
        $reqs = DB::select('select * from credential_requirements where credential_type_id = ? and deleted_at is null and is_blocking
                              and (position_id is null or position_id = ?)', [$type, $w?->current_position_id]);
        foreach ($reqs as $r) {
            $until = null;
            $ok = match ($r->requirement_type) {
                'emo_fitness' => (function () use ($worker, $cworker, &$until) {
                    $f = $worker
                        ? DB::selectOne("select valid_until from fitness_status where worker_id = ? and is_current and status in ('fit','fit_with_restrictions') and (valid_until is null or valid_until >= current_date) and deleted_at is null", [$worker])
                        : DB::selectOne("select emo_valid_until as valid_until from contractor_workers where id = ? and emo_fitness in ('fit','fit_with_restrictions') and (emo_valid_until is null or emo_valid_until >= current_date)", [$cworker]);
                    $until = $f?->valid_until;

                    return (bool) $f;
                })(),
                'training', 'induction' => (function () use ($r, $worker, $cworker, &$until) {
                    if (! $r->training_id) {
                        return false;
                    }
                    $c = DB::selectOne("select count(*) as n, bool_or(expires_on is null) as forever, max(expires_on) as until from training_certificates
                                         where (worker_id = ? or contractor_worker_id = ?) and training_id = ? and status = 'validated'
                                           and (expires_on is null or expires_on >= current_date) and deleted_at is null", [$worker, $cworker, $r->training_id]);
                    $until = $c->forever ? null : $c->until;

                    return (int) $c->n > 0;
                })(),
                'sctr' => (function () use ($worker, $cworker, &$until) {
                    $kinds = DB::select("select p.coverage_kind, max(least(coalesce(c.covered_until, 'infinity'::date), coalesce(p.ends_on, 'infinity'::date))) as until
                                           from sctr_coverages c join sctr_policies p on p.id = c.sctr_policy_id
                                          where (c.worker_id = ? or c.contractor_worker_id = ?) and c.status = 'covered' and c.deleted_at is null
                                            and (c.covered_from is null or c.covered_from <= current_date) and (c.covered_until is null or c.covered_until >= current_date)
                                            and p.deleted_at is null and p.status in ('active', 'expiring') and (p.ends_on is null or p.ends_on >= current_date)
                                            and p.coverage_kind in ('health', 'pension') group by p.coverage_kind", [$worker, $cworker]);
                    $ends = array_filter(array_column($kinds, 'until'), fn ($d) => $d && $d !== 'infinity');
                    $until = $ends ? min($ends) : null;

                    return count($kinds) === 2;
                })(),
                'accreditation' => (function () use ($cworker, &$until) {
                    if (! $cworker) {
                        return true;
                    }
                    $a = DB::selectOne("select bool_or(a.expires_on is null) as forever, max(a.expires_on) as until, count(*) as n from contractor_accreditations a join contractor_workers w on w.contractor_id = a.contractor_id
                                         where w.id = ? and a.status = 'accredited' and (a.expires_on is null or a.expires_on >= current_date) and a.deleted_at is null", [$cworker]);
                    $until = $a->forever ? null : $a->until;

                    return (int) $a->n > 0;
                })(),
                default => true,
            };
            if ($ok) {
                if ($until) {
                    $dates[] = substr((string) $until, 0, 10);
                }

                continue;
            }
            if ((int) $r->grace_days > 0 && $w?->hire_on && date('Y-m-d', strtotime($w->hire_on.' +'.(int) $r->grace_days.' days')) >= $today) {
                continue;
            }
            $reasons[] = ['type' => (string) $r->requirement_type, 'text' => $r->description ?: ['emo_fitness' => 'Aptitud médica vigente', 'training' => 'Capacitación vigente', 'induction' => 'Inducción',
                'sctr' => 'SCTR salud y pensión vigentes', 'accreditation' => 'Contratista acreditado'][$r->requirement_type] ?? $r->requirement_type];
        }
        if ($expires && $expires < $today) {
            $reasons[] = ['type' => 'expired', 'text' => 'Fotocheck vencido'];
        }

        return ['reasons' => $reasons, 'until' => $dates ? min($dates) : null];
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
            $this->permitOverlap($values, null);

            return;
        }
        if (in_array($current->status, ['closed', 'rejected', 'expired'], true) && $values !== []) {
            $this->conflict('El permiso está cerrado: no se modifica.');
        }
        if (array_intersect(array_keys($values), ['executor_leader_worker_id', 'crew', 'valid_from', 'valid_until']) !== []) {
            $this->permitOverlap($values, $current);
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
            // RN-PTR-01: cada ejecutante (líder y cuadrilla) cumple sus requisitos SST: inducción, aptitud, SCTR, capacitaciones y EPP.
            $people = $this->permitWorkers($values, $current);
            if ($people === []) {
                $this->fail('executor_leader_worker_id', 'Indica al líder ejecutante del trabajo antes de aprobar el permiso.');
            }
            $blocked = [];
            foreach (app(\App\Modules\TRB\WorkerRequirements::class)->evaluate($people) as $w) {
                if (! $w['enabled']) {
                    $bad = array_column(array_filter($w['checks'], fn ($c) => $c['status'] === 'bad'), 'label');
                    $blocked[] = $w['name'].($bad ? ' ('.implode(', ', $bad).')' : ($w['worker_status'] === 'terminated' ? ' (cesado)' : ''));
                }
            }
            if ($blocked) {
                $this->fail('status', 'Hay ejecutantes que no cumplen sus requisitos SST: '.implode('; ', array_slice($blocked, 0, 4)).(count($blocked) > 4 ? '…' : '').'.');
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
            // RN-PTR-06: reanudar un trabajo suspendido exige una nueva verificación y lo autoriza la supervisión SST.
            if ($current->status === 'suspended') {
                $mine = app(\App\Core\Tenancy\CompanyContext::class)->roleCode;
                if (! in_array($mine, ['sst_supervisor', 'sst_manager', 'account_admin'], true)) {
                    $this->fail('status', 'Un trabajo suspendido lo reanuda el supervisor o el jefe SST.');
                }
                if ($type?->requires_gas_test && ! array_key_exists('gas_measurements', $values)) {
                    $this->fail('gas_measurements', 'Para reanudar el trabajo registra una nueva medición de gases.');
                }
            }
            // RN-PTR-03: los gases medidos deben estar dentro de los rangos permitidos.
            foreach ($this->gasOutOfRange($gas) as $msg) {
                $this->fail('gas_measurements', $msg);
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

    /** Trabajadores del permiso: el líder ejecutante y la cuadrilla (incluye vigía). */
    private function permitWorkers(array $values, ?object $current): array
    {
        $crew = $this->value($values, $current, 'crew');
        $crew = is_string($crew) ? (json_decode($crew, true) ?: []) : ($crew ?: []);
        $ids = array_merge([$this->value($values, $current, 'executor_leader_worker_id')], array_column(is_array($crew) ? $crew : [], 'worker_id'));

        return array_values(array_unique(array_filter($ids, fn ($i) => is_string($i) && \Illuminate\Support\Str::isUuid($i))));
    }

    /** RN-PTR-09: un trabajador no está en dos permisos vigentes con horarios que se cruzan. */
    private function permitOverlap(array $values, ?object $current): void
    {
        $from = $this->value($values, $current, 'valid_from');
        $until = $this->value($values, $current, 'valid_until');
        $people = $this->permitWorkers($values, $current);
        if (! $from || ! $until || $people === []) {
            return;
        }
        foreach ($people as $w) {
            $other = DB::selectOne(
                "select code from work_permits where deleted_at is null and id <> coalesce(?::uuid, '00000000-0000-0000-0000-000000000000'::uuid)
                    and status in ('pending_approval', 'approved', 'active', 'suspended')
                    and tstzrange(valid_from, valid_until) && tstzrange(?::timestamptz, ?::timestamptz)
                    and (executor_leader_worker_id = ?::uuid or coalesce(crew, '[]'::jsonb) @> jsonb_build_array(jsonb_build_object('worker_id', ?::text)))
                  limit 1", [$current?->id, $from, $until, $w, $w]);
            if ($other) {
                $name = DB::selectOne('select full_name from workers where id = ?', [$w])?->full_name ?? 'Un trabajador';
                $this->fail('crew', "{$name} ya está en el permiso {$other->code} en ese mismo horario.");
            }
        }
    }

    /** Gases fuera de rango (parámetros legales a confirmar: O₂ 19.5–22.5 %, LEL < 10 %, CO < 25 ppm, H₂S < 10 ppm). */
    private function gasOutOfRange(mixed $gas): array
    {
        $gas = is_string($gas) ? (json_decode($gas, true) ?: []) : ($gas ?: []);
        if (! is_array($gas) || $gas === []) {
            return [];
        }
        $read = [];
        $put = function ($k, $v) use (&$read) {
            $k = mb_strtolower(str_replace(['₂', ' ', '%', '_pct', '_ppm'], ['2', '', '', '', ''], (string) $k));
            $k = ['oxygen' => 'o2', 'oxigeno' => 'o2', 'oxígeno' => 'o2', 'lie' => 'lel'][$k] ?? $k;
            if (in_array($k, ['o2', 'lel', 'co', 'h2s'], true) && is_numeric($v)) {
                $read[$k] = (float) $v;
            }
        };
        foreach ($gas as $k => $v) {
            if (is_array($v)) {
                $put($v['gas'] ?? $v['code'] ?? $v['name'] ?? '', $v['value'] ?? $v['reading'] ?? null);
            } else {
                $put($k, $v);
            }
        }
        $P = \App\Core\Support\LegalParams::class;
        $out = [];
        if (isset($read['o2'])) {
            [$min, $max] = [$P::number('gas_o2_min_pct', 19.5), $P::number('gas_o2_max_pct', 22.5)];
            if ($read['o2'] < $min || $read['o2'] > $max) {
                $out[] = "El oxígeno medido ({$read['o2']} %) está fuera del rango permitido ({$min}–{$max} %): no se inicia el trabajo.";
            }
        }
        foreach (['lel' => ['gas_lel_max_pct', 10, '%', 'LEL'], 'co' => ['gas_co_max_ppm', 25, 'ppm', 'CO'], 'h2s' => ['gas_h2s_max_ppm', 10, 'ppm', 'H₂S']] as $g => [$key, $def, $unit, $label]) {
            $max = $P::number($key, $def);
            if (isset($read[$g]) && $read[$g] >= $max) {
                $out[] = "{$label} medido ({$read[$g]} {$unit}) alcanza o supera el límite ({$max} {$unit}): no se inicia el trabajo.";
            }
        }

        return $out;
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
            // RN-PTR-04: los pasos se aprueban en orden; una misma persona no firma dos pasos ni aprueba si es ejecutante.
            $step = (int) $this->value($values, $current, 'step_order');
            $permitId = $this->value($values, $current, 'work_permit_id');
            if (DB::selectOne("select 1 as ok from permit_approvals where work_permit_id = ? and step_order < ? and decision = 'pending' and deleted_at is null", [$permitId, $step])) {
                $this->fail('decision', 'Antes se debe decidir el paso anterior de la cadena de aprobación.');
            }
            if (DB::selectOne("select 1 as ok from permit_approvals where work_permit_id = ? and approver_user_id = ? and decision <> 'pending' and deleted_at is null
                                and id <> coalesce(?::uuid, '00000000-0000-0000-0000-000000000000'::uuid)", [$permitId, (string) auth()->id(), $current?->id])) {
                $this->fail('decision', 'Ya firmaste otro paso de este permiso: este paso lo aprueba otra persona.');
            }
            $w = DB::selectOne('select executor_leader_worker_id, crew from work_permits where id = ?', [$permitId]);
            $executors = $this->permitWorkers(['executor_leader_worker_id' => $w->executor_leader_worker_id, 'crew' => $w->crew], null);
            if ($executors && DB::selectOne('select 1 as ok from workers where user_id = ? and id in ('.implode(',', array_fill(0, count($executors), '?')).')', [(string) auth()->id(), ...$executors])) {
                $this->fail('decision', 'Eres ejecutante de este trabajo: el permiso lo aprueba otra persona.');
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
        // RN-PRE-04: un gasto que hace pasar la línea de su monto se confirma con un motivo y queda marcado como sobreejecución.
        $line = DB::selectOne('select planned_amount, (select coalesce(sum(amount), 0) from budget_executions e where e.budget_line_id = l.id and e.deleted_at is null
                                  and e.id <> coalesce(?::uuid, \'00000000-0000-0000-0000-000000000000\'::uuid)) as spent from budget_lines l where l.id = ?',
            [$current?->id, $this->value($values, $current, 'budget_line_id')]);
        $over = $line && (float) $line->spent + (float) $this->value($values, $current, 'amount') > (float) $line->planned_amount;
        unset($values['is_overrun']);
        $extra['is_overrun'] = ['?', [$over ? 'true' : 'false']];
        if ($over && ! trim((string) $this->value($values, $current, 'overrun_reason'))) {
            $this->fail('overrun_reason', 'Este gasto supera el monto de la línea (S/ '.number_format((float) $line->planned_amount, 2).'): confírmalo indicando el motivo de la sobreejecución.');
        }
        $values['executed_on'] ??= date('Y-m-d');
        if ($current === null) {
            $extra['recorded_by'] = ['?', [(string) auth()->id()]];
        }
    }
}
