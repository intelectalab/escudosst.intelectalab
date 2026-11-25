<?php

namespace App\Modules\REC\Rules;

use App\Core\Support\LegalParams;
use App\Core\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Protección de datos personales de la empresa (docs/10-seguridad-y-privacidad.md):
 *
 * arco_requests (§1.4) — registro de solicitudes ARCO con su plazo de respuesta:
 *  - plazo (due_on) = fecha de recepción + días hábiles del parámetro: arco_access_response_business_days para acceso y
 *    arco_response_business_days para rectificación, cancelación, oposición y portabilidad (D.S. 016-2024-JUS, a confirmar);
 *  - estados received → in_review → (extension_requested) → resolved | rejected; el solicitante puede retirarla (withdrawn);
 *  - la prórroga exige el motivo y solo amplía el plazo si existe el parámetro arco_extension_business_days (no hay valor por
 *    defecto: es un plazo legal a confirmar); atender o denegar exige la respuesta; una solicitud cerrada no cambia;
 *  - el trabajador registra la suya desde su sesión (rol worker): queda a su nombre, por la app, y solo puede retirarla.
 *
 * data_breaches (§1.5) — registro de brechas de seguridad:
 *  - plazo para comunicar a la ANPD (anpd_due_at) = detección + data_breach_notification_hours (parámetro a confirmar);
 *  - estados detected → contained → notified → closed (se puede comunicar antes de contener); «Comunicada» registra la
 *    fecha de la comunicación; cerrar exige causa y medidas correctivas y, si hay que avisar a los titulares, la fecha del
 *    aviso; cerrada solo admite completar la revisión posterior (lecciones aprendidas).
 * Al registrar una solicitud o una brecha se avisa en la campana al administrador de la cuenta (responsable de los datos).
 */
class PrivacyRules extends RecordRules
{
    private const ARCO_FLOW = [
        'received' => ['in_review', 'extension_requested', 'resolved', 'rejected', 'withdrawn'],
        'in_review' => ['extension_requested', 'resolved', 'rejected', 'withdrawn'],
        'extension_requested' => ['in_review', 'resolved', 'rejected', 'withdrawn'],
    ];

    private const ARCO_LABEL = ['received' => 'Recibida', 'in_review' => 'En revisión', 'extension_requested' => 'Con prórroga',
        'resolved' => 'Atendida', 'rejected' => 'Denegada', 'withdrawn' => 'Retirada'];

    private const BREACH_FLOW = [
        'detected' => ['contained', 'notified'],
        'contained' => ['notified', 'closed'],
        'notified' => ['closed'],
    ];

    private const BREACH_LABEL = ['detected' => 'Detectada', 'contained' => 'Contenida', 'notified' => 'Comunicada', 'closed' => 'Cerrada'];

    public function prepare(string $op, array $res, array &$values, ?object $current, array &$extra): void
    {
        match ($res['table']) {
            'arco_requests' => $this->arco($op, $values, $current, $extra),
            'data_breaches' => $this->breach($op, $values, $current, $extra),
            default => null,
        };
    }

    public function prepareDelete(array $res, object $current): void
    {
        if ($res['table'] === 'arco_requests' && $current->status !== 'received') {
            $this->invalidTransition('La solicitud ya se está atendiendo o se cerró: se conserva como constancia (no se borra).');
        }
    }

    public function after(string $op, array $res, string $id, array $values, ?object $current): void
    {
        if ($op !== 'create') {
            return;
        }
        $row = DB::selectOne("select * from {$res['table']} where id = ?", [$id]);
        if ($res['table'] === 'arco_requests') {
            $this->notifyAdmins('trb.arco_received', $id, 'Nueva solicitud ARCO',
                'Solicitud de '.mb_strtolower($this->arcoType($row->request_type)).' recibida: respóndela antes del '.date('d/m/Y', strtotime($row->due_on)).'.',
                '/m/trb?t=arco_requests&open='.$id, 'high', $row->due_on);
        } else {
            $this->notifyAdmins('trb.breach_registered', $id, 'Brecha de seguridad registrada',
                "{$row->code} · {$row->title}. Plazo para comunicarla a la ANPD: ".date('d/m/Y H:i', strtotime($row->anpd_due_at)).'.',
                '/m/trb?t=data_breaches&open='.$id, 'critical', $row->anpd_due_at);
        }
    }

    // ---------------------------------------------------------------- ARCO

    private function arco(string $op, array &$values, ?object $current, array &$extra): void
    {
        $ctx = app(CompanyContext::class);
        $isWorker = $ctx->roleCode === 'worker';

        if ($op === 'create') {
            if ($isWorker) {
                $me = DB::selectOne('select w.id, w.full_name from memberships m join workers w on w.id = m.worker_id where m.id = ?', [$ctx->membershipId]);
                if (! $me) {
                    $this->fail('worker_id', 'Tu usuario no está vinculado a un trabajador: pide la solicitud a Recursos Humanos.');
                }
                $values['worker_id'] = $me->id;
                $values['requester_name'] = $me->full_name;
                $values['channel'] = 'app';
                $values['status'] = 'received';
                unset($values['assigned_to'], $values['resolution'], $values['extension_reason']);
                $extra['received_at'] = ['now()', []];
            } else {
                if (empty($values['requester_name']) && ! empty($values['worker_id'])) {
                    $values['requester_name'] = DB::selectOne('select full_name from workers where id = ?', [$values['worker_id']])?->full_name;
                }
                if (empty($values['requester_name'])) {
                    $this->fail('requester_name', 'Indica quién hace la solicitud (o elige al trabajador).');
                }
                if (empty($values['channel'])) {
                    $this->fail('channel', 'Indica por qué canal llegó la solicitud.');
                }
                if (($values['status'] ?? 'received') !== 'received') {
                    $this->invalidTransition('La solicitud se registra como «Recibida»; luego se cambia su estado al atenderla.');
                }
                if (empty($values['received_at'])) {
                    $extra['received_at'] = ['now()', []];
                } elseif (strtotime((string) $values['received_at']) > time() + 300) {
                    $this->fail('received_at', 'La fecha de recepción no puede ser futura.');
                }
            }
            $type = (string) ($values['request_type'] ?? '');
            $received = isset($values['received_at']) ? date('Y-m-d', strtotime((string) $values['received_at'])) : now('America/Lima')->toDateString();
            [$key, $due] = $this->arcoDue($type, $received);
            $values['legal_parameter_key'] = $key;
            $values['due_on'] = $due;

            return;
        }

        // Edición.
        $open = isset(self::ARCO_FLOW[$current->status]);
        if (! $open) {
            $this->invalidTransition('La solicitud está «'.self::ARCO_LABEL[$current->status].'»: ya no se modifica.');
        }
        if ($isWorker) {
            if (array_diff(array_keys($values), ['status']) !== [] || ($values['status'] ?? null) !== 'withdrawn') {
                $this->invalidTransition('Desde tu cuenta solo puedes retirar tu solicitud; la responde la empresa.');
            }
        }
        // El solicitante, el tipo y la recepción definen el plazo: con la solicitud en curso ya no se cambian.
        if ($current->status !== 'received' && array_intersect(array_keys($values), ['request_type', 'worker_id', 'requester_name']) !== []) {
            $this->invalidTransition('La solicitud ya se está atendiendo: su tipo y su solicitante no cambian.');
        }
        if (isset($values['request_type']) && $values['request_type'] !== $current->request_type) {
            [$key, $due] = $this->arcoDue($values['request_type'], date('Y-m-d', strtotime((string) $current->received_at)));
            $values['legal_parameter_key'] = $key;
            $values['due_on'] = $due;
        }
        $to = $values['status'] ?? null;
        if ($to !== null && $to !== $current->status) {
            if (! in_array($to, self::ARCO_FLOW[$current->status], true)) {
                $this->invalidTransition('No se puede pasar de «'.self::ARCO_LABEL[$current->status].'» a «'.(self::ARCO_LABEL[$to] ?? $to).'».');
            }
            if ($to === 'extension_requested') {
                if (blank($this->value($values, $current, 'extension_reason'))) {
                    $this->fail('extension_reason', 'Indica el motivo de la prórroga (se comunica al solicitante).');
                }
                $days = (int) LegalParams::number('arco_extension_business_days', 0);
                if ($days > 0) {
                    $values['due_on'] = DB::selectOne('select add_business_days(?::date, ?) as d', [$current->due_on, $days])->d;
                }
            }
            if (in_array($to, ['resolved', 'rejected'], true)) {
                if (blank($this->value($values, $current, 'resolution'))) {
                    $this->fail('resolution', $to === 'resolved' ? 'Describe cómo se atendió la solicitud (lo que se entregó, corrigió o canceló).'
                        : 'Indica el motivo de la denegación (se comunica al solicitante).');
                }
            }
            if (in_array($to, ['resolved', 'rejected', 'withdrawn'], true)) {
                $values['resolved_at'] = now()->toIso8601String();
            }
        }
    }

    /** @return array{0: string, 1: string} parámetro usado y fecha límite (días hábiles desde la recepción) */
    private function arcoDue(string $type, string $received): array
    {
        $key = $type === 'access' ? 'arco_access_response_business_days' : 'arco_response_business_days';
        $days = max(0, (int) LegalParams::number($key, $type === 'access' ? 20 : 10, $received));

        return [$key, DB::selectOne('select add_business_days(?::date, ?) as d', [$received, $days])->d];
    }

    private function arcoType(string $t): string
    {
        return ['access' => 'Acceso', 'rectification' => 'Rectificación', 'cancellation' => 'Cancelación', 'opposition' => 'Oposición', 'portability' => 'Portabilidad'][$t] ?? $t;
    }

    // ---------------------------------------------------------------- Brechas

    private function breach(string $op, array &$values, ?object $current, array &$extra): void
    {
        $hours = fn (string $at) => (int) LegalParams::number('data_breach_notification_hours', 48, date('Y-m-d', strtotime($at)));

        if ($op === 'create') {
            if (($values['status'] ?? 'detected') !== 'detected') {
                $this->invalidTransition('La brecha se registra como «Detectada»; luego se registran la contención y la comunicación.');
            }
            $at = (string) ($values['detected_at'] ?? now()->toIso8601String());
            if (strtotime($at) > time() + 300) {
                $this->fail('detected_at', 'La fecha de detección no puede ser futura.');
            }
            $values['detected_at'] = $at;
            $values['legal_parameter_key'] = 'data_breach_notification_hours';
            $values['anpd_due_at'] = date(DATE_ATOM, strtotime($at) + $hours($at) * 3600);
            $year = date('Y', strtotime($at));
            $n = (int) DB::selectOne("select count(*) as n from data_breaches where code like ?", ["BRE-{$year}-%"])->n + 1;
            do {
                $values['code'] = sprintf('BRE-%s-%03d', $year, $n++);
            } while (DB::selectOne('select 1 as ok from data_breaches where code = ?', [$values['code']]));
            if (isset($values['anpd_notified_at'])) {
                $this->fail('anpd_notified_at', 'La comunicación a la ANPD se registra al pasar la brecha a «Comunicada».');
            }

            return;
        }

        if ($current->status === 'closed') {
            if (array_diff(array_keys($values), ['lessons_learned']) !== []) {
                $this->invalidTransition('La brecha está cerrada: solo se completa la revisión posterior (lecciones aprendidas).');
            }

            return;
        }
        if (isset($values['detected_at']) && $values['detected_at'] !== $current->detected_at) {
            $values['anpd_due_at'] = date(DATE_ATOM, strtotime((string) $values['detected_at']) + $hours((string) $values['detected_at']) * 3600);
        }
        $to = $values['status'] ?? null;
        if ($to !== null && $to !== $current->status) {
            if (! in_array($to, self::BREACH_FLOW[$current->status] ?? [], true)) {
                $this->invalidTransition('No se puede pasar de «'.self::BREACH_LABEL[$current->status].'» a «'.(self::BREACH_LABEL[$to] ?? $to).'».');
            }
            if ($to === 'notified' && blank($this->value($values, $current, 'anpd_notified_at'))) {
                $values['anpd_notified_at'] = now()->toIso8601String();
            }
            if ($to === 'closed') {
                foreach (['cause' => 'Registra la causa de la brecha antes de cerrarla.', 'corrective_measures' => 'Registra las medidas correctivas antes de cerrarla.'] as $f => $msg) {
                    if (blank($this->value($values, $current, $f))) {
                        $this->fail($f, $msg);
                    }
                }
                if ($this->value($values, $current, 'holders_notification_required') && blank($this->value($values, $current, 'holders_notified_at'))) {
                    $this->fail('holders_notified_at', 'Indica cuándo se avisó a los titulares de los datos.');
                }
                $extra['closed_at'] = ['now()', []];
            }
        }
        $notified = $this->value($values, $current, 'anpd_notified_at');
        if ($notified && strtotime((string) $notified) < strtotime((string) $this->value($values, $current, 'detected_at'))) {
            $this->fail('anpd_notified_at', 'La comunicación no puede ser anterior a la detección.');
        }
    }

    // ---------------------------------------------------------------- Aviso

    private function notifyAdmins(string $event, string $id, string $title, string $body, string $link, string $priority, ?string $due): void
    {
        $company = app(CompanyContext::class)->companyId;
        $admins = DB::select(
            "select m.user_id from memberships m join roles r on r.id = m.role_id
              where m.company_id = ? and m.status = 'active' and m.deleted_at is null and coalesce(r.base_role_code, r.code) = 'account_admin'",
            [$company]
        );
        foreach ($admins as $a) {
            DB::insert(
                'insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, due_at, dedupe_key)
                 values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) on conflict do nothing',
                [$company, $event, 'TRB', $id, $a->user_id, $title, mb_substr($body, 0, 500), $link, $priority, $due, "{$event}:{$id}:{$a->user_id}"]
            );
        }
    }
}
