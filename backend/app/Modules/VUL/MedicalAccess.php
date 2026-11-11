<?php

namespace App\Modules\VUL;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Acceso a la base médica para VUL y DES (docs/10 §6, mismo patrón que EMO clínico): clave médica de corta vida
 * tras confirmar la contraseña (cabecera X-Medical-Reauth, compartida con EMO y VMD), contexto RLS en la base médica
 * (empresa, usuario y re-autenticación) y doble registro de cada acceso: sensitive_access_logs en la base principal
 * (antes de leer: si falla, se deniega) y medical_access_logs en la base médica.
 */
final class MedicalAccess
{
    public static function ttl(): int
    {
        return max(1, (int) config('escudo.medical.reauth_minutes', 15));
    }

    /** Confirma la contraseña y emite la clave médica. @return array{reauth_id: string, expires_at: string} */
    public static function reauth(Request $request, array $anyOf): array
    {
        if (! array_intersect($anyOf, Permissions::effective())) {
            throw ApiProblem::forbidden('Solo el personal de salud autorizado ve datos médicos.');
        }
        $data = $request->validate(['password' => ['required', 'string']], [], ['password' => 'contraseña']);
        if (! Hash::check($data['password'], (string) $request->user()->getAuthPassword())) {
            throw new ApiProblem(422, 'validation_failed', 'Contraseña incorrecta', 'La contraseña no es correcta.', ['errors' => ['password' => ['La contraseña no es correcta.']]]);
        }
        $id = (string) Str::uuid();
        $expires = now()->addMinutes(self::ttl());
        Cache::put("vmd-reauth:{$id}", ['user' => self::uid(), 'company' => self::company()], $expires);
        self::recordReauth($id, $expires);

        return ['reauth_id' => $id, 'expires_at' => $expires->toIso8601String()];
    }

    /** Clave médica vigente de la cabecera o 403 reauth_required. */
    public static function reauthId(Request $request): string
    {
        $id = self::validReauth($request);
        if ($id === null) {
            throw new ApiProblem(403, 'reauth_required', 'Confirma tu contraseña', 'Para ver o registrar datos médicos confirma tu contraseña (dura '.self::ttl().' minutos).');
        }

        return $id;
    }

    /** La clave médica si es válida para este usuario y empresa; null si no viene o venció. */
    public static function validReauth(Request $request): ?string
    {
        $id = (string) $request->header('X-Medical-Reauth');
        $r = Str::isUuid($id) ? Cache::get("vmd-reauth:{$id}") : null;
        if (! $r || $r['user'] !== self::uid() || $r['company'] !== self::company()) {
            return null;
        }
        if (! DB::selectOne('select 1 as ok from sessions_mfa where id = ?', [$id])) {
            self::recordReauth($id, now()->addMinutes(self::ttl()));
        }

        return $id;
    }

    /** Ejecuta en la base médica dentro de una transacción con el contexto RLS de empresa, usuario y re-autenticación. */
    public static function med(string $reauth, callable $fn): mixed
    {
        $db = DB::connection('medical');

        return $db->transaction(function () use ($db, $fn, $reauth) {
            $db->select("select set_config('app.company_id', ?, true), set_config('app.user_id', ?, true), set_config('app.reauth_session_id', ?, true)",
                [self::company(), self::uid(), $reauth]);

            return $fn($db);
        });
    }

    /** Registro inalterable en la base médica. */
    public static function log($db, string $resource, ?string $resourceId, ?string $workerId, string $action, ?string $purpose = null): void
    {
        $db->select('select med_log_access(?, ?::uuid, ?, ?::uuid, ?, ?, ?::inet)',
            [$resource, $resourceId, $action, $workerId, app(CompanyContext::class)->roleCode, $purpose ? mb_substr($purpose, 0, 300) : null, request()->ip()]);
    }

    /** Registro en sensitive_access_logs de la base principal (previo a la lectura). */
    public static function audit(string $resource, ?string $resourceId, ?string $workerId, string $action, string $reauth, ?string $purpose = null): void
    {
        DB::insert("insert into sensitive_access_logs (company_id, user_id, role_code, worker_id, resource, resource_id, action, purpose, reauth_session_id, result, ip, user_agent, created_by)
                    values (?, ?, ?, ?, ?, ?, ?, ?, ?, 'granted', ?::inet, ?, ?)",
            [self::company(), self::uid(), app(CompanyContext::class)->roleCode, $workerId, $resource, $resourceId, $action, $purpose ? mb_substr($purpose, 0, 300) : null, $reauth,
                request()->ip(), mb_substr((string) request()->userAgent(), 0, 300), self::uid()]);
    }

    public static function has(string $p): bool
    {
        return in_array($p, Permissions::effective(), true);
    }

    public static function can(string $p, string $message): void
    {
        if (! self::has($p)) {
            throw ApiProblem::forbidden($message);
        }
    }

    public static function company(): string
    {
        return app(CompanyContext::class)->companyId;
    }

    public static function uid(): string
    {
        return (string) auth()->id();
    }

    private static function recordReauth(string $id, \DateTimeInterface $expires): void
    {
        DB::insert("insert into sessions_mfa (id, user_id, token_hash, ip, user_agent, mfa_method, reauth_at, reauth_expires_at, reauth_purpose, expires_at, created_by)
                    values (?, ?, decode(?, 'hex'), ?::inet, ?, 'none', now(), ?, 'medical', ?, ?)",
            [$id, self::uid(), hash('sha256', $id), request()->ip(), mb_substr((string) request()->userAgent(), 0, 300), $expires, $expires, self::uid()]);
    }
}
