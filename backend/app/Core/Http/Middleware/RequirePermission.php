<?php

namespace App\Core\Http\Middleware;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige un permiso module.resource.action del rol en la empresa activa.
 * Uso en rutas: ->middleware('permission:trb.workers.read'). Requiere SetTenantContext antes.
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! in_array($permission, Permissions::effective(), true)) {
            throw ApiProblem::forbidden();
        }

        return $next($request);
    }
}
