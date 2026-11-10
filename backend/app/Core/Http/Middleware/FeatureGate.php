<?php

namespace App\Core\Http\Middleware;

use App\Core\Http\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica que el módulo esté habilitado para la empresa activa según
 * feature_flags (docs/08-api.md §3 «Plan y feature flags»).
 * Uso en rutas: ->middleware('module:TRB'). Requiere SetTenantContext antes.
 */
class FeatureGate
{
    private const PLAN_NAMES = ['E' => 'Esencial', 'P' => 'Profesional', 'C' => 'Corporativo', 'K' => 'Consultora'];

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $flag = DB::selectOne(
            'select f.mode, m.name, m.plan_code from modules m
               left join feature_flags f on f.module_code = m.code and f.deleted_at is null
              where m.code = ?',
            [$module]
        );

        $mode = $flag?->mode ?? 'locked';

        if ($mode === 'enabled') {
            return $next($request);
        }

        if ($mode === 'read_only') {
            if ($request->isMethodSafe()) {
                return $next($request);
            }

            throw new ApiProblem(403, 'module_read_only', 'Módulo en solo lectura',
                "{$flag->name} está en solo lectura porque el plan de la empresa ya no lo incluye.",
                ['module' => $module]);
        }

        $planName = self::PLAN_NAMES[$flag?->plan_code] ?? 'superior';

        throw new ApiProblem(403, 'module_not_in_plan', 'El módulo no está incluido en tu plan',
            $mode === 'disabled_by_company'
                ? "{$flag->name} está desactivado por la empresa."
                : (! $flag ? "El módulo {$module} no existe."
                    : (isset(self::PLAN_NAMES[$flag->plan_code]) ? "{$flag->name} requiere el plan {$planName}."
                        : "{$flag->name} es un complemento: solicítalo a EscudoSST para activarlo en tu empresa.")),
            ['module' => $module, 'required_plan' => $flag?->plan_code, 'mode' => $mode]);
    }
}
