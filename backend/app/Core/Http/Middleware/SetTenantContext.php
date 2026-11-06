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
 * Si la respuesta es un error, la transacción se revierte.
 */
class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(CompanyContext::class);

        DB::beginTransaction();

        try {
            DbContext::setCompany($context->companyId, local: true);
            DbContext::setUser($request->user()->getAuthIdentifier(), local: true);

            $response = $next($request);
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        if ($response->getStatusCode() >= 400) {
            DB::rollBack();
        } else {
            DB::commit();
        }

        return $response;
    }
}
