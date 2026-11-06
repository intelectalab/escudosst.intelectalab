<?php

namespace App\Core\Http\Middleware;

use App\Core\Http\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Solo personal de la plataforma (users.is_platform_staff) entra al panel interno /internal
 * (docs/08-api.md §16). Para ellos el MFA es obligatorio en el ingreso.
 * Pendiente: distinguir platform_owner de platform_support (soporte solo lee).
 */
class RequirePlatformStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_platform_staff) {
            throw ApiProblem::forbidden('Esta sección es solo para el equipo de EscudoSST.');
        }

        return $next($request);
    }
}
