<?php

namespace App\Modules\VUL;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Http\Middleware\SetTenantContext;
use App\Core\Tenancy\CompanyContext;
use App\Modules\USR\Services\MfaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Acceso a la base médica para EMO, VMD, VUL, DES y REG (docs/10 §6): clave médica de corta vida tras confirmar la
 * contraseña y el segundo factor, con motivo (cabecera X-Medical-Reauth, compartida por los módulos), contexto RLS en la base médica
 * (empresa, usuario y re-autenticación) y doble registro de cada acceso: sensitive_access_logs en la base principal
 * (antes de leer: si falla, se deniega) y medical_access_logs en la base médica.
 */
final class MedicalAccess
{
    public static function ttl(): int
    {
        return max(1, (int) config('escudo.medical.reauth_minutes', 15));
    }

    /**
     * M-EMO §7 / docs/10 §5: confirma la contraseña y el segundo factor (código de la app de autenticación o uno de los
     * códigos de recuperación, que se gasta), pide el motivo y emite la clave médica. Sin verificación en dos pasos
     * activa no se emite. Cada intento fallido queda como denegado en sensitive_access_logs.
     *
     * @param  list<string>|null  $anyOf  permisos que habilitan la clave (null: el módulo lo controla en cada lectura)
     * @return array{reauth_id: string, expires_at: string, mfa_method: string}
     */
    public static function reauth(Request $request, ?array $anyOf, ?string $forbidden = null): array
    {
        if ($anyOf !== null && ! array_intersect($anyOf, Permissions::effective())) {
            self::denied('Sin permiso para datos médicos');
            throw ApiProblem::forbidden($forbidden ?? 'Solo el personal de salud autorizado ve datos médicos.');
        }
        $data = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:20', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:20'],
            'purpose' => ['required', 'string', 'min:3', 'max:300'],
        ], ['code.required_without' => 'Escribe el código de 6 dígitos de tu app de autenticación o un código de recuperación.'],
            ['password' => 'contraseña', 'code' => 'código de la app', 'recovery_code' => 'código de recuperación', 'purpose' => 'motivo']);
        $user = $request->user();
        if (! Hash::check($data['password'], (string) $user->getAuthPassword())) {
            self::denied('Contraseña incorrecta');
            throw new ApiProblem(422, 'validation_failed', 'Contraseña incorrecta', 'La contraseña no es correcta.', ['errors' => ['password' => ['La contraseña no es correcta.']]]);
        }
        if (! DB::selectOne('select mfa_enabled from users where id = ?', [$user->id])?->mfa_enabled) {
            self::denied('Sin verificación en dos pasos');
            throw new ApiProblem(403, 'mfa_not_enabled', 'Activa la verificación en dos pasos',
                'Para ver datos médicos necesitas la verificación en dos pasos. Actívala en Mi cuenta › «Verificación en dos pasos» y vuelve a intentarlo.');
        }
        $mfa = app(MfaService::class);
        $recovery = trim((string) ($data['recovery_code'] ?? ''));
        $method = $recovery !== '' ? 'recovery_code' : 'totp';
        $ok = $method === 'recovery_code' ? $mfa->consumeRecoveryCode($user, $recovery) : $mfa->verifyUserTotp($user, (string) $data['code']);
        if (! $ok) {
            $field = $method === 'recovery_code' ? 'recovery_code' : 'code';
            $msg = $method === 'recovery_code' ? 'El código de recuperación no es válido o ya se usó.' : 'El código de la app no es correcto o ya se usó. Espera el siguiente.';
            self::denied($method === 'recovery_code' ? 'Código de recuperación incorrecto' : 'Código de verificación incorrecto');
            throw new ApiProblem(422, 'validation_failed', 'Código incorrecto', $msg, ['errors' => [$field => [$msg]]]);
        }
        $id = (string) Str::uuid();
        $expires = now()->addMinutes(self::ttl());
        Cache::put("vmd-reauth:{$id}", ['user' => self::uid(), 'company' => self::company(), 'purpose' => trim($data['purpose'])], $expires);
        self::recordReauth($id, $expires, $method);

        return ['reauth_id' => $id, 'expires_at' => $expires->toIso8601String(), 'mfa_method' => $method];
    }

    /** Clave médica vigente de la cabecera o 403 reauth_required. */
    public static function reauthId(Request $request): string
    {
        $id = self::validReauth($request);
        if ($id === null) {
            throw new ApiProblem(403, 'reauth_required', 'Confirma tu identidad',
                'Para ver o registrar datos médicos confirma tu contraseña y el código de tu app de autenticación, e indica el motivo (dura '.self::ttl().' minutos).');
        }

        return $id;
    }

    /**
     * Motivo del acceso para los registros: el que trae la petición y, además, el que la persona dio al confirmar su
     * identidad (M-USR RN-USR-04: la re-autenticación queda registrada con motivo).
     */
    public static function purpose(?string $given): ?string
    {
        $id = (string) request()->header('X-Medical-Reauth');
        $motivo = Str::isUuid($id) ? (Cache::get("vmd-reauth:{$id}")['purpose'] ?? null) : null;
        $given = $given !== null && trim($given) !== '' ? trim($given) : null;
        $out = ($given !== null && $motivo !== null && $given !== $motivo) ? "{$given} · Motivo: {$motivo}" : ($given ?? $motivo);

        return $out !== null ? mb_substr($out, 0, 300) : null;
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
            [$resource, $resourceId, $action, $workerId, app(CompanyContext::class)->roleCode, self::purpose($purpose), request()->ip()]);
    }

    /**
     * M-AUD §10: intento de acceso médico denegado (sin permiso, contraseña o código incorrectos, o sin verificación en
     * dos pasos). Se registra en sensitive_access_logs y, al llegar a denied_access_threshold intentos de la misma persona
     * en el día, se avisa al médico ocupacional y al administrador de la cuenta. La petición termina en error y su
     * transacción se revierte, por eso el registro se guarda al cerrarla (SetTenantContext::afterRequest).
     */
    public static function denied(string $why): void
    {
        $row = [self::company(), self::uid(), app(CompanyContext::class)->roleCode, mb_substr($why, 0, 300), request()->ip(), mb_substr((string) request()->userAgent(), 0, 300), self::uid()];
        $uid = self::uid();
        SetTenantContext::afterRequest(function () use ($row, $uid) {
            try {
                DB::insert("insert into sensitive_access_logs (company_id, user_id, role_code, resource, action, purpose, result, ip, user_agent, created_by)
                            values (?, ?, ?, 'worker_sensitive_data', 'read', ?, 'denied', ?::inet, ?, ?)", $row);
                $n = (int) DB::selectOne("select count(*) as n from sensitive_access_logs where user_id = ? and result = 'denied' and created_at > (now() at time zone 'America/Lima')::date at time zone 'America/Lima'", [$uid])->n;
                $max = max(1, (int) \App\Core\Support\LegalParams::number('denied_access_threshold', 3));
                if ($n === $max) {
                    $who = DB::selectOne('select full_name from users where id = ?', [$uid])?->full_name ?? 'Un usuario';
                    \App\Modules\NOT\Notify::users(\App\Modules\NOT\Notify::roles(['occupational_doctor', 'account_admin']), 'aud.medical_denied', 'AUD', $uid, 'Accesos médicos denegados',
                        "{$who} intentó {$n} veces hoy entrar a datos médicos sin permiso o con la contraseña o el código incorrectos.", '/configuracion/auditoria', 'high',
                        'aud.medical_denied:'.$uid.':'.now('America/Lima')->format('Ymd'));
                }
            } catch (\Throwable) {
            }
        });
    }

    /** Registro en sensitive_access_logs de la base principal (previo a la lectura). */
    public static function audit(string $resource, ?string $resourceId, ?string $workerId, string $action, string $reauth, ?string $purpose = null): void
    {
        DB::insert("insert into sensitive_access_logs (company_id, user_id, role_code, worker_id, resource, resource_id, action, purpose, reauth_session_id, result, ip, user_agent, created_by)
                    values (?, ?, ?, ?, ?, ?, ?, ?, ?, 'granted', ?::inet, ?, ?)",
            [self::company(), self::uid(), app(CompanyContext::class)->roleCode, $workerId, $resource, $resourceId, $action, self::purpose($purpose), $reauth,
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

    /** Constancia en sessions_mfa (token médico de corta vida: solo su huella) con el segundo factor usado. */
    private static function recordReauth(string $id, \DateTimeInterface $expires, string $method = 'none'): void
    {
        DB::insert("insert into sessions_mfa (id, user_id, token_hash, ip, user_agent, mfa_method, mfa_verified_at, reauth_at, reauth_expires_at, reauth_purpose, expires_at, created_by)
                    values (?, ?, decode(?, 'hex'), ?::inet, ?, ?, case when ? <> 'none' then now() end, now(), ?, 'medical', ?, ?)",
            [$id, self::uid(), hash('sha256', $id), request()->ip(), mb_substr((string) request()->userAgent(), 0, 300), $method, $method, $expires, $expires, self::uid()]);
    }
}
