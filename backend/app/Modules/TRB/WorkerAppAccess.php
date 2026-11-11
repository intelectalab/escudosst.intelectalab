<?php

namespace App\Modules\TRB;

use App\Core\Auth\Roles;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\DbContext;
use App\Modules\REC\Rules\WorkerRules;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Acceso a la app del trabajador (docs/07 §5.2, docs/10 §5).
 *
 *  - Activación: el responsable SST o RR. HH. genera un código de 8 dígitos y un enlace, de un solo uso y con
 *    vencimiento (escudo.worker_app.activation_hours). El código solo vale junto con el DNI del trabajador.
 *    Se guardan en la caché por su huella (nunca en claro) y la huella de la activación vigente queda en
 *    workers.activation_code_hash: una activación nueva anula la anterior.
 *  - Al activar, el trabajador crea su PIN (6 dígitos, con hash en workers.pin_hash) y su equipo recibe un secreto
 *    aleatorio (devices.credential_hash guarda su SHA-256). Luego ingresa con DNI + PIN solo desde ese equipo.
 *  - PIN equivocados: tras escudo.worker_app.pin_max_attempts seguidos se bloquea por pin_lock_minutes. El contador vale
 *    para el ingreso, las firmas y la re-autenticación médica, y no se pierde aunque la petición se revierta.
 */
final class WorkerAppAccess
{
    private const DOC_TYPES = ['DNI', 'CE', 'PTP', 'PASSPORT', 'OTHER'];

    // ── Activación ──

    /** Genera (o regenera) la activación de un trabajador de la empresa activa. */
    public static function issue(string $companyId, object $worker, string $byUserId): array
    {
        $previous = Cache::get(self::lastKey($worker->id));
        foreach ((array) $previous as $k) {
            Cache::forget($k);
        }
        $code = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $token = Str::random(48);
        $nonce = bin2hex(random_bytes(16));
        $expires = now()->addHours(self::activationHours());
        $payload = ['company' => $companyId, 'worker' => $worker->id, 'nonce' => $nonce, 'by' => $byUserId];
        $codeKey = self::codeKey(bin2hex(self::bytes($worker->document_number_hash)), $code);
        $linkKey = self::linkKey($token);
        Cache::put($codeKey, $payload, $expires);
        Cache::put($linkKey, $payload, $expires);
        Cache::put(self::lastKey($worker->id), [$codeKey, $linkKey], $expires);
        DB::update('update workers set activation_code_hash = ?, updated_by = ? where id = ?', [hash('sha256', $nonce), $byUserId, $worker->id]);

        return ['code' => $code, 'token' => $token, 'expires_at' => $expires->toIso8601String(),
            'link' => rtrim((string) config('escudo.frontend_url'), '/').'/mi/activar?t='.$token];
    }

    /** Activación vigente por enlace o por DNI + código (sin consumirla). */
    public static function find(?string $token, ?string $dni, ?string $code): ?array
    {
        if ($token !== null && $token !== '') {
            return strlen($token) === 48 && ctype_alnum($token) ? Cache::get(self::linkKey($token)) : null;
        }
        $code = preg_replace('/\D+/', '', (string) $code);
        if ($dni === null || trim($dni) === '' || strlen($code) !== 8) {
            return null;
        }
        foreach (self::documentHashes($dni) as $h) {
            if ($p = Cache::get(self::codeKey($h, $code))) {
                return $p;
            }
        }

        return null;
    }

    /** Anula la activación (un solo uso). */
    public static function consume(array $payload): void
    {
        foreach ((array) Cache::pull(self::lastKey($payload['worker'])) as $k) {
            Cache::forget($k);
        }
    }

    public static function invalidActivation(): ApiProblem
    {
        return new ApiProblem(410, 'activation_invalid', 'Código no válido',
            'El código o el enlace no es válido, ya se usó o venció. Pide uno nuevo al área de SST o de Recursos Humanos.');
    }

    // ── PIN ──

    public static function validatePin(mixed $pin, mixed $confirmation): string
    {
        $pin = (string) $pin;
        $msg = null;
        if (! preg_match('/^\d{6}$/', $pin)) {
            $msg = 'Tu PIN debe tener 6 números.';
        } elseif (count(array_unique(str_split($pin))) === 1 || str_contains('0123456789', $pin) || str_contains('9876543210', $pin)) {
            $msg = 'Elige un PIN más difícil: no uses números repetidos ni seguidos.';
        } elseif ($confirmation !== null && (string) $confirmation !== $pin) {
            $msg = 'Los dos PIN no coinciden.';
        }
        if ($msg) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa tu PIN', $msg, ['errors' => ['pin' => [$msg]]]);
        }

        return $pin;
    }

    /** ¿PIN bloqueado por intentos fallidos? */
    public static function assertNotLocked(string $workerId): void
    {
        $until = Cache::get(self::lockKey($workerId));
        if ($until && $until > time()) {
            $min = max(1, (int) ceil(($until - time()) / 60));
            throw new ApiProblem(423, 'pin_locked', 'PIN bloqueado',
                "Escribiste mal tu PIN muchas veces. Espera {$min} minuto(s) y vuelve a intentarlo.");
        }
    }

    /** Comprueba el PIN del trabajador y cuenta los errores (bloqueo temporal). */
    public static function checkPin(string $workerId, ?string $pinHash, string $pin): bool
    {
        self::assertNotLocked($workerId);
        if ($pinHash && Hash::check($pin, $pinHash)) {
            Cache::forget(self::failKey($workerId));

            return true;
        }
        $minutes = max(1, (int) config('escudo.worker_app.pin_lock_minutes', 15));
        $fails = (int) Cache::get(self::failKey($workerId), 0) + 1;
        if ($fails >= max(1, (int) config('escudo.worker_app.pin_max_attempts', 5))) {
            Cache::forget(self::failKey($workerId));
            Cache::put(self::lockKey($workerId), time() + $minutes * 60, now()->addMinutes($minutes));
        } else {
            Cache::put(self::failKey($workerId), $fails, now()->addMinutes($minutes));
        }

        return false;
    }

    /** PIN del trabajador vinculado (dentro de la empresa activa); 422 si no es correcto. */
    public static function requirePin(string $workerId, mixed $pin): void
    {
        $hash = DB::selectOne('select pin_hash from workers where id = ?', [$workerId])?->pin_hash;
        if (! $hash) {
            throw new ApiProblem(422, 'validation_failed', 'Sin PIN', 'Aún no creaste tu PIN: activa la app con el código que te dio el área de SST.',
                ['errors' => ['pin' => ['Aún no tienes PIN.']]]);
        }
        if (! self::checkPin($workerId, $hash, (string) $pin)) {
            self::assertNotLocked($workerId);
            throw new ApiProblem(422, 'validation_failed', 'PIN incorrecto', 'El PIN no es correcto.', ['errors' => ['pin' => ['El PIN no es correcto.']]]);
        }
    }

    // ── Usuario y dispositivo ──

    /**
     * Usuario con rol trabajador vinculado al trabajador (memberships.worker_id). Lo crea si no existe.
     * Debe correr con app.company_id fijado en la transacción.
     */
    public static function ensureUser(string $companyId, object $worker, string $byUserId): string
    {
        $m = DB::selectOne('select id, user_id, status from memberships where worker_id = ? and deleted_at is null order by (status = \'active\') desc limit 1', [$worker->id]);
        if ($m && in_array($m->status, ['suspended', 'revoked', 'expired'], true)) {
            throw new ApiProblem(403, 'forbidden', 'Acceso suspendido', 'Tu acceso a la app está suspendido. Consulta con el área de SST o de Recursos Humanos.');
        }
        $userId = $m->user_id ?? $worker->user_id;
        $platform = DB::connection('platform');
        if (! $userId) {
            $email = self::decrypt($worker->personal_email);
            $email = $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? Str::lower($email) : null;
            if (! $email || $platform->selectOne('select 1 as ok from users where lower(email) = ?', [$email])) {
                // Sin correo propio: dirección técnica que nunca recibe mensajes (dominio reservado .invalid).
                $email = 'trabajador-'.$worker->id.'@app.escudosst.invalid';
            }
            $userId = $platform->selectOne(
                "insert into users (email, full_name, status, created_by) values (?, ?, 'active', ?) returning id",
                [$email, $worker->full_name, $byUserId])->id;
        } else {
            $u = $platform->selectOne('select status, is_platform_staff from users where id = ?', [$userId]);
            if (! $u || $u->is_platform_staff || in_array($u->status, ['disabled', 'locked'], true)) {
                throw new ApiProblem(403, 'forbidden', 'Acceso suspendido', 'Tu usuario está desactivado. Consulta con el área de SST o de Recursos Humanos.');
            }
            if ($u->status === 'invited') {
                $platform->update("update users set status = 'active' where id = ?", [$userId]);
            }
        }
        if (! $m) {
            $role = DB::selectOne("select id from roles where account_id is null and code = 'worker' and deleted_at is null")->id;
            $existing = DB::selectOne('select id, worker_id from memberships where user_id = ? and company_id = ? and deleted_at is null', [$userId, $companyId]);
            if ($existing) {
                DB::update('update memberships set worker_id = coalesce(worker_id, ?) where id = ?', [$worker->id, $existing->id]);
            } else {
                DB::insert("insert into memberships (company_id, user_id, role_id, worker_id, status, invited_by, invited_at, accepted_at, created_by)
                            values (?, ?, ?, ?, 'active', ?, now(), now(), ?)", [$companyId, $userId, $role, $worker->id, $byUserId, $byUserId]);
            }
        } elseif ($m->status === 'invited') {
            DB::update("update memberships set status = 'active', accepted_at = now() where id = ?", [$m->id]);
        }
        if (! $worker->user_id) {
            DB::update('update workers set user_id = ? where id = ? and user_id is null', [$userId, $worker->id]);
        }

        return $userId;
    }

    /**
     * El ingreso con PIN es solo para cuentas de trabajador: si la persona tiene otro rol en alguna empresa (o MFA),
     * entra con su correo y contraseña.
     */
    public static function assertPinAllowed(string $userId): void
    {
        $u = DB::connection('platform')->selectOne('select mfa_enabled, is_platform_staff from users where id = ?', [$userId]);
        DbContext::setUser($userId);
        $roles = array_map(fn ($r) => Roles::base((string) $r->role_code), DB::select('select role_code from app_my_memberships()'));
        if (! $u || $u->mfa_enabled || $u->is_platform_staff || array_diff($roles, ['worker'])) {
            throw new ApiProblem(409, 'pin_not_allowed', 'Ingresa con tu contraseña',
                'Tu usuario tiene otros accesos en EscudoSST: ingresa con tu correo o DNI y tu contraseña.');
        }
    }

    /** Registra (o vuelve a ligar) el dispositivo del trabajador y devuelve su id y el secreto, que solo se entrega aquí. */
    public static function bindDevice(string $companyId, string $userId, string $workerId, string $uid, ?string $model): array
    {
        $secret = Str::random(48);
        $hash = hash('sha256', $secret);
        $device = DB::selectOne('select id from devices where device_uid = ? and deleted_at is null', [$uid]);
        if ($device) {
            DB::update("update devices set user_id = ?, worker_id = ?, credential_hash = decode(?, 'hex'), pin_bound = true, status = 'active', revoked_at = null,
                               revoked_by = null, revoke_reason = null, model = coalesce(?, model), last_login_at = now(), updated_by = ? where id = ?",
                [$userId, $workerId, $hash, $model, $userId, $device->id]);
            $id = $device->id;
        } else {
            $id = DB::selectOne("insert into devices (company_id, user_id, worker_id, device_uid, platform, model, pin_bound, credential_hash, last_login_at, created_by, updated_by)
                                 values (?, ?, ?, ?, 'web_pwa', ?, true, decode(?, 'hex'), now(), ?, ?) returning id",
                [$companyId, $userId, $workerId, $uid, $model, $hash, $userId, $userId])->id;
        }

        return ['id' => $id, 'secret' => $secret];
    }

    // ── Apoyo ──

    /** Huellas del documento con cada tipo posible (el trabajador solo escribe el número). */
    public static function documentHashes(string $number): array
    {
        return array_map(fn ($t) => WorkerRules::documentHash($t, $number), self::DOC_TYPES);
    }

    public static function decrypt(?string $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        try {
            return Crypt::decryptString($v);
        } catch (Throwable) {
            return $v;   // dato antiguo sin cifrar
        }
    }

    public static function bytes(mixed $v): string
    {
        return is_resource($v) ? (string) stream_get_contents($v) : (string) $v;
    }

    public static function audit(string $companyId, ?string $userId, string $role, string $action, string $workerId, array $changes = []): void
    {
        DB::insert("insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes, ip, user_agent)
                    values (?, ?, ?, 'user', ?, 'TRB', 'workers', ?, ?::jsonb, ?::inet, ?)",
            [$companyId, $userId, $role, $action, $workerId, json_encode($changes, JSON_UNESCAPED_UNICODE), request()->ip(), mb_substr((string) request()->userAgent(), 0, 300)]);
    }

    private static function activationHours(): int
    {
        return max(1, (int) config('escudo.worker_app.activation_hours', 72));
    }

    private static function codeKey(string $docHashHex, string $code): string
    {
        return 'worker-act:code:'.hash('sha256', $docHashHex.'|'.$code);
    }

    private static function linkKey(string $token): string
    {
        return 'worker-act:link:'.hash('sha256', $token);
    }

    private static function lastKey(string $workerId): string
    {
        return 'worker-act:last:'.$workerId;
    }

    private static function failKey(string $workerId): string
    {
        return 'worker-pin:fail:'.$workerId;
    }

    private static function lockKey(string $workerId): string
    {
        return 'worker-pin:lock:'.$workerId;
    }
}
