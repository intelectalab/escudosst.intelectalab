<?php

namespace App\Core\Http\Middleware;

use App\Core\Tenancy\CompanyContext;
use App\Core\Tenancy\DbContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Abre una transacción y fija SET LOCAL app.company_id / app.user_id para que
 * RLS filtre todo por la empresa activa (docs/01-arquitectura.md §4 paso 4).
 * Si la respuesta es un error, la transacción se revierte; lo registrado con afterRequest() se guarda igual al final
 * (por ejemplo, el registro de un acceso médico denegado, M-EMO RN-EMO-03 / M-AUD §10).
 */
class SetTenantContext
{
    /** @var list<callable>|null acciones pendientes de la petición en curso (null: no hay petición con contexto) */
    private static ?array $after = null;

    public function handle(Request $request, Closure $next): Response
    {
        $context = app(CompanyContext::class);
        self::$after = [];

        DB::beginTransaction();

        try {
            DbContext::setCompany($context->companyId, local: true);
            DbContext::setUser($request->user()->getAuthIdentifier(), local: true);

            $response = $next($request);
        } catch (Throwable $e) {
            DB::rollBack();
            self::runAfter($context->companyId, (string) $request->user()->getAuthIdentifier());
            throw $e;
        }

        if ($response->getStatusCode() >= 400) {
            DB::rollBack();
        } else {
            DB::commit();
        }
        self::runAfter($context->companyId, (string) $request->user()->getAuthIdentifier());

        return $response;
    }

    /**
     * Escritura que debe quedar aunque la petición termine en error: se ejecuta al cerrar la transacción de la petición,
     * en una transacción propia con la misma empresa y usuario. Fuera de una petición con contexto se ejecuta en el momento.
     */
    public static function afterRequest(callable $fn): void
    {
        if (self::$after === null) {
            $fn();

            return;
        }
        self::$after[] = $fn;
    }

    private static function runAfter(?string $companyId, string $userId): void
    {
        $fns = self::$after ?? [];
        self::$after = null;
        if (! $fns) {
            return;
        }
        try {
            DB::transaction(function () use ($fns, $companyId, $userId) {
                DbContext::setCompany($companyId, local: true);
                DbContext::setUser($userId, local: true);
                foreach ($fns as $fn) {
                    $fn();
                }
            });
        } catch (Throwable) {
            // Nunca cambia la respuesta de la petición.
        }
    }
}
