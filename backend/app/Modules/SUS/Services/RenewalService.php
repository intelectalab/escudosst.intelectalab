<?php

namespace App\Modules\SUS\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Proceso diario de vencimientos (docs/06 §20, docs/03 §8). Lo corre el programador de tareas
 * (php artisan escudo:subscriptions-daily, todos los días a las 07:00 de Lima).
 *
 *  1. Avisos antes del vencimiento: 5, 3 y 0 días (escudo.subscriptions.reminder_days), por correo y en el sistema.
 *     Cada aviso se envía una sola vez por periodo (queda en audit_logs con su clave).
 *  2. Periodo vencido sin pago: active → past_due, con gracia de N días (escudo.subscriptions.grace_days).
 *     Durante la gracia la empresa sigue trabajando normalmente.
 *  3. Prueba terminada: trialing → trial_expired (solo lectura).
 *  4. Gracia terminada sin pago: past_due → read_only (toda la empresa en solo lectura; exportación disponible).
 *
 * Registrar el pago (SubscriptionService::registerPayment) devuelve la suscripción a active al momento.
 * Es idempotente: correrlo dos veces el mismo día no repite avisos ni cambios.
 */
class RenewalService
{
    public function __construct(
        private readonly SubscriptionNotifier $notifier,
        private readonly AccountsService $accounts,
    ) {}

    private function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    /** @return array{reminders: int, past_due: int, trial_expired: int, blocked: int} */
    public function run(): array
    {
        $summary = ['reminders' => 0, 'past_due' => 0, 'trial_expired' => 0, 'blocked' => 0];

        // 1. Avisos antes del vencimiento.
        $days = '{'.implode(',', config('escudo.subscriptions.reminder_days')).'}';
        foreach ($this->rows("s.status in ('active', 'trialing') and days_left = any(?::int[])", [$days]) as $sub) {
            $kind = $sub->status === 'trialing' ? 'trial_reminder' : 'reminder';
            $key = "{$kind}:{$sub->period_end}:{$sub->days_left}";
            if ($this->alreadySent($sub->id, $key)) {
                continue;
            }
            $this->notifier->notify($sub, $kind, ['end' => $sub->period_end, 'days' => (int) $sub->days_left], $key);
            $this->accounts->audit(null, 'subscription_reminder', 'subscriptions', $sub->id, [
                'account_id' => $sub->account_id, 'company' => $sub->company_name, 'key' => $key, 'days' => (int) $sub->days_left, 'end' => $sub->period_end,
            ]);
            $summary['reminders']++;
        }

        // 2. Periodo vencido: pago pendiente con días de gracia.
        $grace = (int) config('escudo.subscriptions.grace_days');
        foreach ($this->rows("s.status = 'active' and s.current_period_end <= now()") as $sub) {
            $graceUntil = $this->db()->selectOne('select (?::timestamptz + make_interval(days => ?)) as g', [$sub->current_period_end, $grace])->g;
            $this->transition($sub, 'past_due', 'subscription_past_due', ['grace_until' => $graceUntil], 'past_due', ['end' => $sub->current_period_end, 'grace_until' => $graceUntil]);
            $summary['past_due']++;
        }

        // 3. Prueba terminada.
        foreach ($this->rows("s.status = 'trialing' and s.trial_ends_at <= now()") as $sub) {
            $this->transition($sub, 'trial_expired', 'subscription_trial_expired', [], 'trial_expired', ['end' => $sub->trial_ends_at]);
            $summary['trial_expired']++;
        }

        // 4. Gracia terminada sin pago: solo lectura.
        foreach ($this->rows("s.status = 'past_due' and s.grace_until <= now()") as $sub) {
            $this->transition($sub, 'read_only', 'subscription_blocked', ['cancel_reason' => 'Pago no registrado al terminar el periodo de gracia'], 'blocked', ['end' => $sub->current_period_end]);
            $summary['blocked']++;
        }

        return $summary;
    }

    private function transition(object $sub, string $status, string $action, array $extra, string $kind, array $data): void
    {
        $this->db()->transaction(function () use ($sub, $status, $action, $extra) {
            $sets = ['status = ?'];
            $bindings = [$status];
            foreach ($extra as $col => $value) {
                $sets[] = "{$col} = ?";
                $bindings[] = $value;
            }
            $bindings[] = $sub->id;
            $this->db()->update('update subscriptions set '.implode(', ', $sets).' where id = ?', $bindings);
            $this->db()->select('select refresh_feature_flags(?)', [$sub->company_id]);
            $this->accounts->audit(null, $action, 'subscriptions', $sub->id, [
                'account_id' => $sub->account_id,
                'company' => $sub->company_name,
                'before' => ['status' => $sub->status],
                'after' => ['status' => $status] + $extra,
            ]);
        });
        $this->notifier->notify($sub, $kind, $data, "{$kind}:".substr($data['end'], 0, 10));
    }

    private function alreadySent(string $subscriptionId, string $key): bool
    {
        return (bool) $this->db()->selectOne(
            "select 1 from audit_logs where company_id is null and entity_id = ? and action = 'subscription_reminder' and changes->>'key' = ?",
            [$subscriptionId, $key]
        );
    }

    /** Suscripciones de empresa con los datos que necesitan los avisos. */
    private function rows(string $where, array $bindings = []): array
    {
        return $this->db()->select(
            "select * from (
                select s.*, p.code as plan_code, p.name as plan_name, coalesce(c.trade_name, c.legal_name) as company_name, a.billing_email,
                       case when s.status = 'trialing' then s.trial_ends_at else s.current_period_end end as period_end,
                       ((case when s.status = 'trialing' then s.trial_ends_at else s.current_period_end end) at time zone 'America/Lima')::date
                         - (now() at time zone 'America/Lima')::date as days_left
                  from subscriptions s
                  join plans p on p.id = s.plan_id
                  join companies c on c.id = s.company_id and c.deleted_at is null
                  join accounts a on a.id = s.account_id and a.deleted_at is null
                 where s.deleted_at is null and s.company_id is not null and p.code <> 'K'
             ) s where {$where}",
            $bindings
        );
    }
}
