<?php

namespace App\Core\Http\Middleware;

use App\Core\Http\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Solo personal de la plataforma (users.is_platform_staff) entra al panel interno /internal
 * (docs/08-api.md §16). Para ellos el MFA es obligatorio en el ingreso.
 * Lo reservado al Dueño (aprobar, publicar, exportar, equipo) lleva además «platform.owner».
 */
class RequirePlatformStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_platform_staff || $request->user()->status === 'disabled') {
            throw ApiProblem::forbidden('Esta sección es solo para el equipo de EscudoSST.');
        }

        return $next($request);
    }
}
