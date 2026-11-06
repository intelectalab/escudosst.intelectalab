<?php

namespace App\Modules\SUS\Services;

use App\Core\Http\ApiProblem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * SCR-SUS-01 · Matriz plan × módulo (docs/modulos/M-SUS.md).
 *
 * Reglas:
 * - Los planes son acumulativos (plans.includes_plan_codes): Corporativo ⊇ Profesional ⊇ Esencial.
 *   Activar un módulo en un plan lo activa también en los planes superiores; quitarlo de un plan lo
 *   quita también de los inferiores. inclusion = native en el plan más bajo que lo tiene, inherited en los demás.
 * - Solo se asignan a planes los módulos contratables (plan_code E, P o C). Los complementos (+),
 *   el plan Consultora (K) y los internos (I) se gestionan aparte.
 * - El núcleo (TEN, USR, AUD) no se puede quitar de ningún plan: sin él la empresa no funciona.
 * - Cada cambio recalcula feature_flags de las empresas suscritas (refresh_feature_flags). Si un
 *   módulo sale del plan, las empresas que lo usaban lo ven en solo lectura: sus datos no se borran.
 * - Todo cambio queda en audit_logs como evento de plataforma (company_id NULL).
 */
class PlanModulesService
{
    public const PLAN_CODES = ['E', 'P', 'C'];

    public const CORE_MODULES = ['TEN', 'USR', 'AUD'];

    public function __construct(private readonly PlanCatalogService $catalog) {}

    private function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    /** @return array<string, mixed> */
    public function matrix(): array
    {
        $included = [];
        foreach ($this->db()->select(
            "select p.code as plan, pm.module_code, pm.inclusion from plan_modules pm join plans p on p.id = pm.plan_id where p.code in ('E', 'P', 'C')"
        ) as $row) {
            $included[trim($row->module_code)][$row->plan] = $row->inclusion;
        }

        $modules = array_map(function ($m) use ($included) {
            $code = trim($m->code);
            $assignable = in_array($m->plan_code, self::PLAN_CODES, true);

            return [
                'code' => $code,
                'number' => $m->number,
                'name' => $m->name,
                'group_number' => (int) $m->group_number,
                'plan_code' => $m->plan_code,
                'phase' => (int) $m->phase,
                'assignable' => $assignable,
                'core' => in_array($code, self::CORE_MODULES, true),
                'plans' => array_combine(self::PLAN_CODES, array_map(
                    fn ($p) => $included[$code][$p] ?? null, self::PLAN_CODES)),
            ];
        }, $this->db()->select('select code, number, name, group_number, plan_code, phase from modules where deleted_at is null order by group_number, number'));

        return ['plans' => $this->catalog->plans(), 'modules' => $modules, 'addons' => $this->catalog->addons()];
    }

    /**
     * Incluye o quita un módulo de un plan (con la regla acumulativa) y recalcula las empresas.
     *
     * @return array{changed: list<array{plan: string, included: bool}>, companies_refreshed: int}
     */
    public function setIncluded(string $planCode, string $moduleCode, bool $included, string $actorUserId): array
    {
        $planCode = strtoupper($planCode);
        $moduleCode = strtoupper($moduleCode);

        if (! in_array($planCode, self::PLAN_CODES, true)) {
            throw ApiProblem::notFound('Ese plan no existe o no admite módulos.');
        }

        $module = $this->db()->selectOne('select code, name, plan_code from modules where code = ? and deleted_at is null', [$moduleCode]);

        if ($module === null) {
            throw ApiProblem::notFound('Ese módulo no existe.');
        }

        if (! in_array($module->plan_code, self::PLAN_CODES, true)) {
            throw new ApiProblem(409, 'conflict', 'Este módulo no se asigna a planes',
                $module->plan_code === '+' ? "«{$module->name}» es un complemento: se contrata aparte en la suscripción."
                    : "«{$module->name}» no pertenece a los planes Esencial, Profesional ni Corporativo.");
        }

        if (! $included && in_array($moduleCode, self::CORE_MODULES, true)) {
            throw new ApiProblem(409, 'conflict', 'Módulo del núcleo',
                "«{$module->name}» es parte del núcleo y debe estar en todos los planes.");
        }

        return $this->db()->transaction(function () use ($planCode, $moduleCode, $included, $actorUserId, $module) {
            $plans = $this->db()->select("select id, code, includes_plan_codes from plans where code in ('E', 'P', 'C') and deleted_at is null order by sort_order");
            $includes = fn ($p) => $this->pgArray($p->includes_plan_codes);

            // Planes afectados: al activar, el plan y los que lo incluyen; al quitar, el plan y los que él incluye.
            $target = $included
                ? array_values(array_filter($plans, fn ($p) => in_array($planCode, $includes($p), true)))
                : array_values(array_filter($plans, fn ($p) => in_array($p->code, $includes($this->planBy($plans, $planCode)), true)));

            $before = $this->currentPlans($moduleCode);
            $changed = [];

            foreach ($target as $plan) {
                $has = in_array($plan->code, $before, true);

                if ($included && ! $has) {
                    $this->db()->insert('insert into plan_modules (plan_id, module_code, inclusion, created_by) values (?, ?, ?, ?)',
                        [$plan->id, $moduleCode, 'native', $actorUserId]);
                    $changed[] = ['plan' => $plan->code, 'included' => true];
                } elseif (! $included && $has) {
                    // Borrado físico: refresh_feature_flags no filtra deleted_at en plan_modules.
                    $this->db()->delete('delete from plan_modules where plan_id = ? and module_code = ?', [$plan->id, $moduleCode]);
                    $changed[] = ['plan' => $plan->code, 'included' => false];
                }
            }

            if ($changed === []) {
                return ['changed' => [], 'companies_refreshed' => 0];
            }

            $this->normalizeInclusion($moduleCode, $plans);

            // Recalcula los módulos de cada empresa suscrita a los planes que cambiaron.
            $companies = $this->db()->select(
                "select distinct s.company_id from subscriptions s join plans p on p.id = s.plan_id
                  where p.code = any(?::text[]) and s.company_id is not null and s.deleted_at is null
                    and s.status not in ('cancelled', 'trial_expired')",
                ['{'.implode(',', array_column($changed, 'plan')).'}']
            );

            foreach ($companies as $c) {
                $this->db()->select('select refresh_feature_flags(?)', [$c->company_id]);
            }

            $this->db()->insert(
                "insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, changes)
                 values (null, ?, 'platform_owner', 'user', ?, 'SUS', 'plan_modules', ?::jsonb)",
                [$actorUserId, $included ? 'plan_module_included' : 'plan_module_removed', json_encode([
                    'module' => $moduleCode,
                    'module_name' => $module->name,
                    'before' => $before,
                    'after' => $this->currentPlans($moduleCode),
                    'companies_refreshed' => count($companies),
                ], JSON_UNESCAPED_UNICODE)]
            );

            return ['changed' => $changed, 'companies_refreshed' => count($companies)];
        });
    }

    /** @return list<string> Planes (E/P/C) que hoy incluyen el módulo. */
    private function currentPlans(string $moduleCode): array
    {
        return array_map(fn ($r) => $r->code, $this->db()->select(
            "select p.code from plan_modules pm join plans p on p.id = pm.plan_id
              where pm.module_code = ? and p.code in ('E', 'P', 'C') order by p.sort_order",
            [$moduleCode]
        ));
    }

    /** native en el plan más bajo que lo incluye; inherited en los superiores. */
    private function normalizeInclusion(string $moduleCode, array $plans): void
    {
        $first = true;

        foreach ($plans as $plan) {
            $updated = $this->db()->update(
                'update plan_modules set inclusion = ? where plan_id = ? and module_code = ?',
                [$first ? 'native' : 'inherited', $plan->id, $moduleCode]
            );

            if ($updated > 0) {
                $first = false;
            }
        }
    }

    private function planBy(array $plans, string $code): object
    {
        foreach ($plans as $p) {
            if ($p->code === $code) {
                return $p;
            }
        }

        throw ApiProblem::notFound('Ese plan no existe.');
    }

    /** @return list<string> */
    private function pgArray(string $value): array
    {
        return array_values(array_filter(explode(',', trim($value, '{}'))));
    }
}
