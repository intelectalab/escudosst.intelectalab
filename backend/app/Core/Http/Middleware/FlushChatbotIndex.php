<?php

namespace App\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cuando el panel guarda algo del sitio (preguntas frecuentes, ayuda, recursos, casos, páginas, planes…), el índice del
 * asistente se rehace al instante en la próxima pregunta en vez de esperar sus 5 minutos de caché.
 */
class FlushChatbotIndex
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $request->isMethodSafe() && $response->getStatusCode() < 400) {
            Cache::forget('chatbot:index');
        }

        return $response;
    }
}
