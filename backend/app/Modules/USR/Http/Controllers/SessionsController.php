<?php

namespace App\Modules\USR\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sesiones activas de mi cuenta (M-USR SCR-USR-05 «cerrar sesiones remotas»):
 *  GET    /auth/sessions                  mis sesiones vigentes: equipo (navegador), inicio, último uso, vencimiento y cuál es esta
 *  DELETE /auth/sessions/{id}             cierra una sesión de otro equipo
 *  POST   /auth/sessions/close-others     cierra todas menos la actual
 * Cada cierre queda en la bitácora de la cuenta.
 */
class SessionsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken()?->id;
        $rows = DB::select(
            "select id, name, created_at, last_used_at, expires_at from personal_access_tokens
              where tokenable_type = ? and tokenable_id = ? and (expires_at is null or expires_at > now()) and name not like 'vista-%'
              order by coalesce(last_used_at, created_at) desc", [get_class($request->user()), (string) $request->user()->getKey()]);

        return response()->json(['data' => array_map(fn ($r) => ['id' => (string) $r->id, 'device' => self::device((string) $r->name), 'started_at' => $r->created_at,
            'last_used_at' => $r->last_used_at, 'expires_at' => $r->expires_at, 'current' => (string) $r->id === (string) $current], $rows)]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if ((string) $request->user()->currentAccessToken()?->id === $id) {
            throw new ApiProblem(409, 'conflict', 'Es esta sesión', 'Para cerrar esta sesión usa «Cerrar sesión».');
        }
        $n = DB::delete('delete from personal_access_tokens where id = ?::uuid and tokenable_type = ? and tokenable_id = ?',
            [$id, get_class($request->user()), (string) $request->user()->getKey()]);
        if (! $n) {
            throw ApiProblem::notFound('La sesión no existe o ya se cerró.');
        }
        $this->audit($request, 1);

        return response()->json(['data' => ['closed' => 1]]);
    }

    public function closeOthers(Request $request): JsonResponse
    {
        $n = DB::delete('delete from personal_access_tokens where tokenable_type = ? and tokenable_id = ? and id <> ?',
            [get_class($request->user()), (string) $request->user()->getKey(), (string) ($request->user()->currentAccessToken()?->id ?? '00000000-0000-0000-0000-000000000000')]);
        $this->audit($request, $n);

        return response()->json(['data' => ['closed' => $n]]);
    }

    /** Nombre corto del equipo a partir del navegador con el que se inició la sesión. */
    public static function device(string $ua): string
    {
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android', str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iPhone / iPad',
            str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Mac OS') => 'Mac', str_contains($ua, 'Linux') => 'Linux', default => null,
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'OPR/') => 'Opera', str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox', str_contains($ua, 'Safari/') => 'Safari', default => null,
        };

        return $os || $browser ? trim(($browser ?? 'Navegador').' en '.($os ?? 'otro equipo')) : (Str::limit($ua, 40) ?: 'Equipo sin nombre');
    }

    private function audit(Request $request, int $n): void
    {
        try {
            app(\App\Modules\SUS\Services\AccountsService::class)->audit((string) $request->user()->getKey(), 'sessions_closed', 'users', (string) $request->user()->getKey(), ['count' => $n]);
        } catch (\Throwable) {
            // La bitácora no impide cerrar la sesión.
        }
    }
}
