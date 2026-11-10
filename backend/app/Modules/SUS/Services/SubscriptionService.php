<?php

namespace App\Modules\SUS\Services;

use App\Core\Http\ApiProblem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Acciones del dueño de la plataforma sobre una suscripción (SCR-SUS-02, docs/06 §20).
 *
 *  - Cambiar plan (E/P/C). Subir es inmediato; bajar desde el panel también se aplica al momento
 *    (corrección administrativa) y los módulos que salen pasan a solo lectura (RN-SUS-03).
 *  - Suspender (→ read_only: toda la empresa en solo lectura, exportación disponible) y reactivar (→ active).
 *  - Agregar o quitar complementos (RN-SUS-05: requieren un plan base activo).
 *
 * Cada acción recalcula los feature flags de la empresa (RN-SUS-02) y queda en audit_logs.
 */
class SubscriptionService
{
    private const SUSPENDABLE = ['trialing', 'active', 'past_due'];

    private const REACTIVABLE = ['read_only', 'past_due', 'trial_expired'];

    private const PLAN_LEVEL = ['E' => 1, 'P' => 2, 'C' => 3];

    public function __construct(
        private readonly AccountsService $accounts,
        private readonly SubscriptionNotifier $notifier,
    ) {}

    private function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    /** @return array{direction: string, modules: array<string, int>} */
    public function changePlan(string $subscriptionId, string $planCode, ?string $reason, string $actor): array
    {
        $sub = $this->find($subscriptionId);
        $this->assertOpen($sub);
        if ($sub->plan_code === 'K') {
            throw $this->conflict('El plan Consultora se gestiona aparte.');
        }
        if ($sub->plan_code === $planCode) {
            throw $this->validation('plan', 'La empresa ya tiene ese plan.');
        }
        $plan = $this->db()->selectOne("select id, code, name from plans where code = ? and code <> 'K' and is_active and deleted_at is null", [$planCode]);
        if (! $plan) {
            throw $this->validation('plan', 'Ese plan no está activo.');
        }

        $direction = self::PLAN_LEVEL[$planCode] > self::PLAN_LEVEL[$sub->plan_code] ? 'upgrade' : 'downgrade';

        return $this->db()->transaction(function () use ($sub, $plan, $reason, $actor, $direction) {
            $this->db()->update('update subscriptions set plan_id = ?, updated_by = ? where id = ?', [$plan->id, $actor, $sub->id]);
            $modules = $this->refresh($sub->company_id);
            $this->accounts->audit($actor, 'subscription_plan_changed', 'subscriptions', $sub->id, [
                'account_id' => $sub->account_id,
                'company' => $sub->company_name,
                'before' => ['plan' => $sub->plan_code],
                'after' => ['plan' => $plan->code],
                'direction' => $direction,
                'modules' => $modules,
            ], $reason);

            return ['direction' => $direction, 'modules' => $modules];
        });
    }

    public function suspend(string $subscriptionId, string $reason, string $actor): void
    {
        $sub = $this->find($subscriptionId);
        if (! in_array($sub->status, self::SUSPENDABLE, true)) {
            throw $this->conflict('Solo se puede suspender una suscripción en prueba, activa o con pago pendiente.');
        }
        $this->setStatus($sub, 'read_only', 'subscription_suspended', $reason, $actor);
    }

    public function reactivate(string $subscriptionId, ?string $reason, string $actor): void
    {
        $sub = $this->find($subscriptionId);
        if (! in_array($sub->status, self::REACTIVABLE, true)) {
            throw $this->conflict('Esta suscripción no está suspendida.');
        }
        $this->setStatus($sub, 'active', 'subscription_reactivated', $reason, $actor);
    }

    /**
     * Agrega uno o varios complementos de una vez (todo o nada). Devuelve los nombres agregados.
     *
     * @param  list<string>  $addonCodes
     * @return list<string>
     */
    public function addAddons(string $subscriptionId, array $addonCodes, string $actor): array
    {
        $sub = $this->find($subscriptionId);
        // RN-SUS-05: plan base activo (E, P o C). En prueba no hay complementos (docs/03 §9).
        if ($sub->status !== 'active' || ! isset(self::PLAN_LEVEL[$sub->plan_code])) {
            throw $this->conflict('Un complemento requiere un plan base activo (Esencial, Profesional o Corporativo) en la empresa.');
        }

        $addons = [];
        foreach (array_unique(array_map(Str::upper(...), $addonCodes)) as $code) {
            $addon = $this->db()->selectOne('select id, code, name, available_in_v1 from addons where code = ? and is_active and deleted_at is null', [$code]);
            if (! $addon) {
                throw $this->validation('addons', "El complemento {$code} no existe o no está activo.");
            }
            if (! $addon->available_in_v1) {
                throw $this->validation('addons', "{$addon->name} aún no está disponible (Próximamente): no se puede vender todavía.");
            }
            if ($this->activeAddon($sub->id, $addon->id)) {
                throw $this->validation('addons', "La empresa ya tiene {$addon->name}.");
            }
            $addons[] = $addon;
        }

        return $this->db()->transaction(function () use ($sub, $addons, $actor) {
            foreach ($addons as $addon) {
                $this->db()->insert(
                    "insert into subscription_addons (account_id, subscription_id, addon_id, company_id, status, created_by)
                     values (?, ?, ?, ?, 'active', ?)",
                    [$sub->account_id, $sub->id, $addon->id, $sub->company_id, $actor]
                );
            }
            $modules = $this->refresh($sub->company_id);
            $this->accounts->audit($actor, 'subscription_addon_added', 'subscriptions', $sub->id, [
                'account_id' => $sub->account_id,
                'company' => $sub->company_name,
                'addons' => array_column($addons, 'code'),
                'addon_name' => implode(', ', array_column($addons, 'name')),
                'modules' => $modules,
            ]);

            return array_column($addons, 'name');
        });
    }

    public function removeAddon(string $subscriptionId, string $addonCode, ?string $reason, string $actor): void
    {
        $sub = $this->find($subscriptionId);
        $addon = $this->db()->selectOne('select id, code, name from addons where code = ? and deleted_at is null', [Str::upper($addonCode)]);
        $row = $addon ? $this->activeAddon($sub->id, $addon->id) : null;
        if (! $row) {
            throw ApiProblem::notFound('La empresa no tiene ese complemento activo.');
        }

        $this->db()->transaction(function () use ($sub, $addon, $row, $reason, $actor) {
            $this->db()->update("update subscription_addons set status = 'ended', ended_at = now(), updated_by = ? where id = ?", [$actor, $row->id]);
            $modules = $this->refresh($sub->company_id);   // el módulo del complemento pasa a solo lectura
            $this->accounts->audit($actor, 'subscription_addon_removed', 'subscriptions', $sub->id, [
                'account_id' => $sub->account_id, 'company' => $sub->company_name, 'addon' => $addon->code, 'addon_name' => $addon->name, 'modules' => $modules,
            ], $reason);
        });
    }

    /**
     * Registra un pago (SCR-SUS-04, manual mientras no haya pasarela ni OSE): crea el comprobante (pagado,
     * con estado SUNAT «pendiente» hasta integrar el OSE) y el pago confirmado, extiende el periodo y
     * deja la suscripción activa; si estaba con pago pendiente, vencida o suspendida, se desbloquea al momento.
     *
     * Desde cuándo corre el nuevo periodo:
     *  - activa o en prueba: desde el fin del periodo vigente (pagar antes no hace perder días);
     *  - pago pendiente (en gracia): desde que venció (los días de gracia no son gratis);
     *  - suspendida o prueba vencida: desde hoy.
     *
     * @param  array{amount: float|string, method: string, paid_at: string, reference?: ?string, periods: int, note?: ?string}  $data
     * @return array{current_period_end: string, status: string}
     */
    public function registerPayment(string $subscriptionId, array $data, string $actor, bool $notify = true): array
    {
        $sub = $this->find($subscriptionId);
        if (in_array($sub->status, ['cancelled', 'pending_cancellation'], true) || $sub->plan_code === 'K') {
            throw $this->conflict('Esta suscripción no admite pagos desde aquí.');
        }

        $result = $this->db()->transaction(function () use ($sub, $data, $actor) {
            $step = $sub->billing_period === 'annual' ? 'years' : 'months';
            $period = $this->db()->selectOne(
                "select base, base + make_interval({$step} => ?) as until from (
                    select case
                             when ? in ('active', 'trialing') then greatest(now(), coalesce(case when ? = 'trialing' then ?::timestamptz else ?::timestamptz end, now()))
                             when ? = 'past_due' then coalesce(?::timestamptz, now())
                             else now() end as base
                 ) x",
                [(int) $data['periods'], $sub->status, $sub->status, $sub->trial_ends_at, $sub->current_period_end, $sub->status, $sub->current_period_end]
            );

            $amount = round((float) $data['amount'], 2);
            $plural = $sub->billing_period === 'annual' ? ((int) $data['periods'] === 1 ? 'año' : 'años') : ((int) $data['periods'] === 1 ? 'mes' : 'meses');
            $invoiceId = $this->db()->selectOne(
                "insert into invoices (account_id, subscription_id, document_type, issue_on, due_on, customer_tax_id, customer_name,
                                       subtotal, tax, total, lines, status, sunat_status, created_by)
                 values (?, ?, 'invoice', ?::date, ?::date, ?, ?, ?, 0, ?, ?::jsonb, 'paid', 'pending', ?) returning id",
                [
                    $sub->account_id, $sub->id, $data['paid_at'], $period->base, $sub->ruc, $sub->legal_name, $amount, $amount,
                    json_encode([['description' => "Plan {$sub->plan_name} · {$data['periods']} {$plural}", 'amount' => $amount, 'note' => $data['note'] ?? null]], JSON_UNESCAPED_UNICODE),
                    $actor,
                ]
            )->id;

            $paymentId = $this->db()->selectOne(
                "insert into payments (account_id, invoice_id, method, reference, amount, paid_at, status, created_by)
                 values (?, ?, ?, ?, ?, ?::date, 'confirmed', ?) returning id",
                [$sub->account_id, $invoiceId, $data['method'], $data['reference'] ?? null, $amount, $data['paid_at'], $actor]
            )->id;

            $this->db()->update(
                "update subscriptions set status = 'active', current_period_start = ?, current_period_end = ?, grace_until = null,
                        cancel_reason = null, updated_by = ? where id = ?",
                [$period->base, $period->until, $actor, $sub->id]
            );
            $modules = $this->refresh($sub->company_id);
            $this->accounts->audit($actor, 'payment_registered', 'subscriptions', $sub->id, [
                'account_id' => $sub->account_id,
                'company' => $sub->company_name,
                'payment_id' => $paymentId,
                'amount' => $amount,
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'periods' => (int) $data['periods'],
                'before' => ['status' => $sub->status, 'period_end' => $sub->current_period_end],
                'after' => ['status' => 'active', 'period_end' => $period->until],
                'modules' => $modules,
            ], $data['note'] ?? null);

            return ['current_period_end' => $period->until, 'status' => 'active', 'payment_id' => $paymentId];
        });

        // Desde una cotización el cliente recibe un solo correo de confirmación (no uno por empresa).
        if ($notify) {
            $this->notifier->notify($sub, 'paid', ['end' => $result['current_period_end']], 'paid:'.$result['payment_id']);
        }

        return ['current_period_end' => $result['current_period_end'], 'status' => 'active'];
    }

    private function setStatus(object $sub, string $status, string $action, ?string $reason, string $actor): void
    {
        $this->db()->transaction(function () use ($sub, $status, $action, $reason, $actor) {
            $this->db()->update(
                "update subscriptions
                    set status = ?, updated_by = ?,
                        cancel_reason = case when ? = 'read_only' then ? else null end,
                        grace_until = null,
                        current_period_start = case when ? = 'active' and (current_period_end is null or current_period_end < now()) then now() else current_period_start end,
                        current_period_end = case when ? = 'active' and (current_period_end is null or current_period_end < now())
                                                  then now() + case when billing_period = 'annual' then interval '1 year' else interval '1 month' end
                                                  else current_period_end end
                  where id = ?",
                [$status, $actor, $status, $reason, $status, $status, $sub->id]
            );
            $modules = $this->refresh($sub->company_id);
            $this->accounts->audit($actor, $action, 'subscriptions', $sub->id, [
                'account_id' => $sub->account_id,
                'company' => $sub->company_name,
                'before' => ['status' => $sub->status],
                'after' => ['status' => $status],
                'modules' => $modules,
            ], $reason);
        });
    }

    /** Recalcula los feature flags de la empresa y devuelve el conteo por modo. */
    private function refresh(string $companyId): array
    {
        $this->db()->select('select refresh_feature_flags(?)', [$companyId]);
        $counts = ['enabled' => 0, 'read_only' => 0, 'locked' => 0, 'disabled_by_company' => 0];
        foreach ($this->db()->select('select mode, count(*) as n from feature_flags where company_id = ? and deleted_at is null group by mode', [$companyId]) as $r) {
            $counts[$r->mode] = (int) $r->n;
        }

        return $counts;
    }

    private function activeAddon(string $subscriptionId, string $addonId): ?object
    {
        return $this->db()->selectOne(
            "select id from subscription_addons where subscription_id = ? and addon_id = ? and status = 'active' and deleted_at is null",
            [$subscriptionId, $addonId]
        );
    }

    private function find(string $id): object
    {
        $sub = Str::isUuid($id) ? $this->db()->selectOne(
            'select s.*, p.code as plan_code, p.name as plan_name, coalesce(c.trade_name, c.legal_name) as company_name,
                    c.legal_name, c.ruc, a.billing_email
               from subscriptions s join plans p on p.id = s.plan_id left join companies c on c.id = s.company_id
               join accounts a on a.id = s.account_id
              where s.id = ? and s.deleted_at is null',
            [$id]
        ) : null;

        return $sub ?? throw ApiProblem::notFound('La suscripción no existe.');
    }

    private function assertOpen(object $sub): void
    {
        if (in_array($sub->status, ['cancelled', 'trial_expired', 'read_only'], true)) {
            throw $this->conflict('Reactiva la suscripción antes de cambiar el plan.');
        }
    }

    private function conflict(string $detail): ApiProblem
    {
        return new ApiProblem(409, 'conflict', 'No se puede realizar la acción', $detail);
    }

    private function validation(string $field, string $message): ApiProblem
    {
        return new ApiProblem(422, 'validation_failed', 'Revisa los datos ingresados', $message, ['errors' => [$field => [$message]]]);
    }
}
