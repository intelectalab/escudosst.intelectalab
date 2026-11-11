<?php

namespace App\Core\Http\Middleware;

use App\Core\Auth\Roles;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lee X-Company-Id y valida que el usuario tenga una membresía activa en esa
 * empresa (docs/01-arquitectura.md §4 paso 3; docs/08-api.md §3).
 */
class ResolveCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $companyId = $request->header('X-Company-Id');

        if (! $companyId) {
            throw ApiProblem::badRequest('Falta la cabecera X-Company-Id con la empresa activa.');
        }

        if (! Str::isUuid($companyId)) {
            throw ApiProblem::badRequest('X-Company-Id no es un identificador válido.');
        }

        // app_my_memberships() es SECURITY DEFINER y solo devuelve las del usuario actual (app.user_id).
        $membership = DB::selectOne(
            'select membership_id, company_id, company_name, ruc, role_code from app_my_memberships() where company_id = ?',
            [$companyId]
        );

        if ($membership === null) {
            // Igual que un registro de otra empresa: no revelamos si existe.
            throw ApiProblem::notFound('No tienes acceso a esta empresa.');
        }

        app()->instance(CompanyContext::class, new CompanyContext(
            companyId: $membership->company_id,
            membershipId: $membership->membership_id,
            // Un rol personalizado se comporta como su rol base (recortado a sus permisos): las reglas usan el código base.
            roleCode: Roles::base($membership->role_code),
            companyName: $membership->company_name,
            ruc: trim($membership->ruc),
            customRoleCode: Roles::isCustom($membership->role_code) ? $membership->role_code : null,
        ));

        return $next($request);
    }
}
