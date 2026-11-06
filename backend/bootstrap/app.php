<?php

use App\Core\Http\ApiProblem;
use App\Core\Http\Middleware\FeatureGate;
use App\Core\Http\Middleware\RequirePermission;
use App\Core\Http\Middleware\RequirePlatformOwner;
use App\Core\Http\Middleware\RequirePlatformStaff;
use App\Core\Http\Middleware\ResolveCompany;
use App\Core\Http\Middleware\SetTenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'module' => FeatureGate::class,
            'permission' => RequirePermission::class,
            'platform' => RequirePlatformStaff::class,
            'platform.owner' => RequirePlatformOwner::class,
        ]);

        // La API no redirige a una pantalla de inicio de sesión (no existe): responde 401 en problem+json aunque el
        // cliente no envíe «Accept: application/json». Antes eso terminaba en un error 500.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/login');

        // Empresa activa: valida membresía y fija RLS (docs/01-arquitectura.md §4).
        $middleware->group('company', [
            ResolveCompany::class,
            SetTenantContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Errores de la API en problem+json y en español (docs/08-api.md §7).
        $isApi = fn (Request $request) => $request->is('api/*');

        $exceptions->render(function (ApiProblem $e, Request $request) {
            return $e->toResponse((string) Str::ulid());
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiProblem::unauthenticated()->toResponse((string) Str::ulid());
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiProblem::make(422, 'validation_failed', 'Revisa los datos ingresados', null,
                    ['errors' => $e->errors()], (string) Str::ulid());
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiProblem::make(429, 'rate_limited', 'Demasiados intentos',
                    'Espera un momento antes de volver a intentarlo.', [], (string) Str::ulid())
                    ->withHeaders($e->getHeaders());
            }
        });

        $exceptions->render(function (NotFoundHttpException|MethodNotAllowedHttpException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiProblem::notFound()->toResponse((string) Str::ulid());
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) use ($isApi) {
            if ($isApi($request) && ! config('app.debug') && ! $e instanceof HttpExceptionInterface) {
                // Nunca trazas ni SQL al cliente; el trace_id permite buscar el caso en los logs.
                $traceId = (string) Str::ulid();
                logger()->error($e->getMessage(), ['trace_id' => $traceId, 'exception' => $e]);

                return ApiProblem::make(500, 'server_error', 'Ocurrió un error inesperado', null, [], $traceId);
            }
        });
    })->create();
