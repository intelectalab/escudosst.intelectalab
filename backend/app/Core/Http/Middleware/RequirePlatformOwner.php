<?php

namespace App\Core\Http\Middleware;

use App\Core\Http\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Acciones reservadas al Dueño de la plataforma (docs/05 §4: permisos especiales). Soporte prepara, pero no
 * aprueba, publica, exporta ni administra el equipo. Va después de «platform».
 */
class RequirePlatformOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->platform_role !== 'owner') {
            throw ApiProblem::forbidden('Esta acción es solo para el Dueño de la plataforma. Pídesela a un Dueño.');
        }

        return $next($request);
    }
}
