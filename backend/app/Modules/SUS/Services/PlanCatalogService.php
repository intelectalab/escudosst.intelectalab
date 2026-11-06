<?php

namespace App\Modules\SUS\Services;

use App\Core\Http\ApiProblem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Datos de los planes y complementos (docs/modulos/M-SUS.md §5, SCR-SUS-01).
 *
 * - Los planes son fijos (E, P, C, K): la base no admite otros códigos. Se editan, no se crean.
 * - price_reference nulo = «Por definir» (RN-SUS-01). Límites nulos = sin límite.
 * - Desactivar un plan solo impide ofrecerlo en suscripciones nuevas; las actuales siguen igual.
 * - Cada cambio queda en audit_logs con el antes y el después (RN-SUS-02).
 */
class PlanCatalogService
{
    public const LIMIT_FIELDS = [
        'max_companies', 'max_workers', 'max_sites', 'max_management_users',
        'storage_gb', 'ai_requests_month', 'qr_report_points',
    ];

    private function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    /** @return list<array<string, mixed>> */
    public function plans(): array
    {
        $rows = $this->db()->select(
            'select p.code, p.name, p.description, p.sort_order, p.price_reference, p.currency, p.billing_periods,
                    p.is_public, p.is_active, p.updated_at, '.implode(', ', array_map(fn ($f) => 'p.'.$f, self::LIMIT_FIELDS)).",
                    (select count(*) from plan_modules pm where pm.plan_id = p.id) as modules_count,
                    (select count(distinct s.company_id) from subscriptions s
                      where s.plan_id = p.id and s.deleted_at is null and s.status not in ('cancelled', 'trial_expired')) as companies_count
               from plans p where p.deleted_at is null and p.code in ('E', 'P', 'C', 'K') order by p.sort_order"
        );

        return array_map(fn ($p) => [
            'code' => $p->code,
            'name' => $p->name,
            'description' => $p->description,
            'sort_order' => (int) $p->sort_order,
            'price_reference' => $p->price_reference,
            'currency' => trim($p->currency),
            'billing_periods' => $this->pgArray($p->billing_periods),
            'is_public' => (bool) $p->is_public,
            'is_active' => (bool) $p->is_active,
            'limits' => array_combine(self::LIMIT_FIELDS, array_map(
                fn ($f) => $p->{$f} === null ? null : (int) $p->{$f}, self::LIMIT_FIELDS)),
            'modules_count' => (int) $p->modules_count,
            'companies_count' => (int) $p->companies_count,
            'updated_at' => $p->updated_at,
        ], $rows);
    }

    /** @param array<string, mixed> $data Ya validado */
    public function updatePlan(string $code, array $data, string $actorUserId): void
    {
        $code = strtoupper($code);
        $before = $this->db()->selectOne('select * from plans where code = ? and deleted_at is null', [$code]);

        if ($before === null || ! in_array($code, ['E', 'P', 'C', 'K'], true)) {
            throw ApiProblem::notFound('Ese plan no existe.');
        }

        $set = [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price_reference' => $data['price_reference'] ?? null,
            'billing_periods' => '{'.implode(',', $data['billing_periods']).'}',
            'is_public' => $data['is_public'],
            'is_active' => $data['is_active'],
            'updated_by' => $actorUserId,
        ];
        foreach (self::LIMIT_FIELDS as $f) {
            $set[$f] = $data['limits'][$f] ?? null;
        }

        $this->db()->transaction(function () use ($code, $set, $before, $actorUserId) {
            $this->db()->table('plans')->where('code', $code)->update($set);
            $this->audit($actorUserId, 'plan_updated', 'plans', $before->id, $this->diff((array) $before, $set));
        });
    }

    /** @return list<object> */
    public function addons(): array
    {
        return $this->db()->select(
            'select a.code, a.name, a.description, trim(a.module_code) as module_code, a.available_in_v1, a.is_per_sector,
                    a.price_reference, a.currency, a.is_active,
                    (select count(*) from subscription_addons sa where sa.addon_id = a.id and sa.status = \'active\' and sa.deleted_at is null) as subscriptions_count
               from addons a where a.deleted_at is null order by a.code'
        );
    }

    /** @param array<string, mixed> $data Ya validado */
    public function updateAddon(string $code, array $data, string $actorUserId): void
    {
        $code = strtoupper($code);
        $before = $this->db()->selectOne('select * from addons where code = ? and deleted_at is null', [$code]);

        if ($before === null) {
            throw ApiProblem::notFound('Ese complemento no existe.');
        }

        $set = [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price_reference' => $data['price_reference'] ?? null,
            'is_active' => $data['is_active'],
            'updated_by' => $actorUserId,
        ];

        $this->db()->transaction(function () use ($code, $set, $before, $actorUserId) {
            $this->db()->table('addons')->where('code', $code)->update($set);
            $this->audit($actorUserId, 'addon_updated', 'addons', $before->id, $this->diff((array) $before, $set));
        });
    }

    /** Solo los campos que cambiaron, con su valor anterior y el nuevo. */
    private function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $k => $v) {
            if ($k === 'updated_by') {
                continue;
            }
            $old = $before[$k] ?? null;
            if (is_bool($v)) {
                $old = (bool) $old;
            }
            if ((string) json_encode($old) !== (string) json_encode($v) && ! (is_numeric($old) && is_numeric($v) && (float) $old === (float) $v)) {
                $changes[$k] = ['antes' => $old, 'despues' => $v];
            }
        }

        return $changes;
    }

    private function audit(string $actor, string $action, string $entity, string $entityId, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $this->db()->insert(
            "insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes)
             values (null, ?, 'platform_owner', 'user', ?, 'SUS', ?, ?, ?::jsonb)",
            [$actor, $action, $entity, $entityId, json_encode($changes, JSON_UNESCAPED_UNICODE)]
        );
    }

    /** @return list<string> */
    private function pgArray(string $value): array
    {
        return array_values(array_filter(explode(',', trim($value, '{}'))));
    }
}
