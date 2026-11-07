<?php

namespace App\Modules\USR\Http\Controllers;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\USR\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Perfil, empresas y permisos del usuario (docs/08-api.md §2 y §3).
 */
class MeController extends Controller
{
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /** Selector de empresa: membresías activas del usuario en todas sus empresas. */
    public function companies(): JsonResponse
    {
        $rows = DB::select(
            'select company_id, company_name, ruc, role_code, r.name as role_name, expires_at
               from app_my_memberships() m
               join roles r on r.code = m.role_code and r.account_id is null
              order by company_name'
        );

        return response()->json(['data' => array_map(fn ($r) => [
            'company_id' => $r->company_id,
            'name' => $r->company_name,
            'ruc' => trim($r->ruc),
            'role' => ['code' => $r->role_code, 'name' => $r->role_name],
            'expires_at' => $r->expires_at,
        ], $rows)]);
    }

    /**
     * Cambia la empresa activa. Valida la membresía; el cliente envía luego X-Company-Id.
     * Pendiente: invalidar el token médico cuando exista la re-autenticación.
     */
    public function switchCompany(Request $request): JsonResponse
    {
        $companyId = (string) $request->input('company_id');

        if (! Str::isUuid($companyId)) {
            throw new ApiProblem(422, 'validation_failed', 'Datos inválidos', null,
                ['errors' => ['company_id' => ['Elige una empresa válida.']]]);
        }

        $membership = DB::selectOne('select membership_id from app_my_memberships() where company_id = ?', [$companyId]);

        if ($membership === null) {
            throw ApiProblem::notFound('No tienes acceso a esta empresa.');
        }

        DB::transaction(function () use ($companyId, $membership) {
            DB::select("select set_config('app.company_id', ?, true)", [$companyId]);
            DB::update('update memberships set last_access_at = now() where id = ?', [$membership->membership_id]);
        });

        return response()->json(['company_id' => $companyId]);
    }

    /** Permisos efectivos en la empresa activa (requiere X-Company-Id). */
    public function permissions(): JsonResponse
    {
        $context = app(CompanyContext::class);

        return response()->json([
            'company_id' => $context->companyId,
            'role_code' => $context->roleCode,
            'data' => Permissions::effective(),
        ]);
    }

    /**
     * Módulos para el menú (docs/07-pantallas-y-ux.md §2 y §3.7):
     *  - mode: enabled / read_only / locked / disabled_by_company (feature_flags de la empresa)
     *  - required_plan: plan más bajo que hoy lo incluye (según plan_modules), o '+' si es complemento
     *  - role_can_use: el rol tiene algún permiso del módulo (si no, el módulo no se muestra)
     */
    public function modules(): JsonResponse
    {
        $context = app(CompanyContext::class);

        $rows = DB::select(
            "select trim(m.code) as code, m.number, m.name, m.solves, m.group_number, m.plan_code,
                    coalesce(f.mode, 'locked') as mode,
                    coalesce((select p.code from plan_modules pm join plans p on p.id = pm.plan_id
                               where pm.module_code = m.code and p.code in ('E', 'P', 'C')
                               order by p.sort_order limit 1), m.plan_code) as required_plan,
                    case when (select is_support_session from memberships where id = ?)
                         -- Sesión de soporte: ve (solo lectura) los módulos de la empresa, nunca los médicos.
                         then m.code <> all(?::text[]) and coalesce(f.mode, 'locked') in ('enabled', 'read_only')
                         else exists (select 1 from memberships ms
                                        join role_permissions rp on rp.role_id = ms.role_id
                                        join permissions pe on pe.id = rp.permission_id and pe.deleted_at is null
                                       where ms.id = ? and pe.module_code = m.code) end as role_can_use
               from modules m
               left join feature_flags f on f.module_code = m.code and f.deleted_at is null
              where m.deleted_at is null and m.plan_code <> 'I'
              order by m.group_number, m.number",
            [$context->membershipId, '{'.implode(',', Permissions::MEDICAL_MODULES).'}', $context->membershipId]
        );

        return response()->json(['data' => $rows]);
    }
}
