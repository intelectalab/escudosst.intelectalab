<?php

namespace App\Modules\USR\Services;

use App\Core\Http\ApiProblem;
use App\Modules\WEB\Mail\SiteRequestMail;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Datos y acceso de la persona (cualquier usuario) y equipo de la plataforma.
 *
 * Enlaces por correo con token aleatorio: en caché solo se guarda su sha256 y vence. Un enlace nuevo del mismo tipo
 * invalida el anterior. Tipos:
 *  - reset:  «Olvidé mi contraseña» (60 min). Al usarlo se cierran todas las sesiones.
 *  - team:   invitación al equipo de la plataforma: crea su contraseña (72 h).
 *  - email:  confirmar el correo nuevo (24 h). Hasta confirmarlo, se sigue usando el anterior; al anterior le
 *            llega un aviso del cambio.
 * El MFA sigue igual: el personal de la plataforma lo configura en su primer ingreso.
 */
class AccountSecurityService
{
    private const PREFIX = 'acctlink:';

    private const TTL_MINUTES = ['reset' => 60, 'team' => 72 * 60, 'email' => 24 * 60];

    public const ROLES = ['owner' => 'Dueño de la plataforma', 'support' => 'Soporte de la plataforma'];

    private function db(): ConnectionInterface
    {
        // users con RLS: la plataforma ve a todos; aquí solo se toca la fila de quien corresponde.
        return DB::connection('platform');
    }

    /**
     * Cambios en la fila de la propia persona: con la conexión de la aplicación y app.user_id (la política de users
     * permite que cada quien edite su fila). Así no choca con el resto de la sesión, que usa esa misma conexión.
     */
    private function updateOwn(string $userId, string $sql, array $bindings): void
    {
        $db = DB::connection('pgsql');
        $db->transaction(function () use ($db, $userId, $sql, $bindings) {
            $db->select("select set_config('app.user_id', ?, true)", [$userId]);
            $done = $db->update($sql, $bindings);
            // Fila que esta conexión aún no ve (p. ej. una persona recién invitada en la misma transacción): se hace con
            // la conexión de la plataforma, que ve todas las filas de users.
            if ($done === 0 && $this->db()->update($sql, $bindings) === 0) {
                throw $this->invalid();
            }
        });
    }

    // ------------------------------------------------------------ Mi cuenta

    public function updateName(string $userId, string $name): void
    {
        $this->updateOwn($userId, 'update users set full_name = ?, updated_at = now(), updated_by = ? where id = ?', [trim($name), $userId, $userId]);
        $this->audit($userId, 'profile_updated', $userId, ['field' => 'full_name']);
    }

    public function changePassword(object $user, string $current, string $new, ?string $keepTokenId): void
    {
        $row = $this->user($user->id);
        if (! $row->password_hash || ! Hash::check($current, $row->password_hash)) {
            throw $this->field('current_password', 'La contraseña actual no es correcta.');
        }
        if (Hash::check($new, $row->password_hash)) {
            throw $this->field('password', 'Usa una contraseña distinta a la actual.');
        }
        $this->updateOwn($user->id, 'update users set password_hash = ?, password_changed_at = now(), updated_at = now() where id = ?', [Hash::make($new), $user->id]);
        $this->revokeTokens($user->id, $keepTokenId);   // las demás sesiones se cierran
        $this->audit($user->id, 'password_changed', $user->id, []);
        $this->mail($row->email, $row->full_name, 'Cambiaste tu contraseña', 'Tu contraseña de EscudoSST se cambió y cerramos tus otras sesiones. Si no fuiste tú, usa «¿Olvidaste tu contraseña?» en la pantalla de ingreso y avísanos.');
    }

    public function requestEmailChange(object $user, string $newEmail, string $current): void
    {
        $row = $this->user($user->id);
        if (! $row->password_hash || ! Hash::check($current, $row->password_hash)) {
            throw $this->field('current_password', 'La contraseña actual no es correcta.');
        }
        $email = Str::lower(trim($newEmail));
        if ($email === Str::lower($row->email)) {
            throw $this->field('email', 'Es el mismo correo que ya tienes.');
        }
        if ($this->db()->selectOne('select 1 from users where lower(email) = ? and id <> ?', [$email, $user->id])) {
            throw $this->field('email', 'Ese correo ya está en uso por otra cuenta.');
        }
        $token = $this->issue('email', $user->id, ['new_email' => $email]);
        $this->mail($email, $row->full_name, 'Confirma tu correo nuevo',
            "Pediste usar este correo para ingresar a EscudoSST en lugar de {$row->email}. Confirma con el botón. Si no fuiste tú, ignora este mensaje.",
            'Confirmar mi correo', $this->url("/confirmar-correo/{$token}"));
        $this->audit($user->id, 'email_change_requested', $user->id, ['new_email' => $email]);
    }

    public function confirmEmail(string $token): string
    {
        $data = $this->consume($token, 'email');
        $row = $this->user($data['user_id']);
        if ($this->db()->selectOne('select 1 from users where lower(email) = ? and id <> ?', [$data['new_email'], $row->id])) {
            throw new ApiProblem(409, 'email_taken', 'Correo en uso', 'Ese correo ya lo usa otra cuenta.');
        }
        $this->updateOwn($row->id, 'update users set email = ?, email_verified_at = now(), updated_at = now() where id = ?', [$data['new_email'], $row->id]);
        $this->audit($row->id, 'email_changed', $row->id, ['from' => $row->email, 'to' => $data['new_email']]);
        $this->mail($row->email, $row->full_name, 'Tu correo de ingreso cambió',
            "Desde ahora ingresas a EscudoSST con {$data['new_email']}. Si no fuiste tú, escríbenos de inmediato.");

        return $data['new_email'];
    }

    // ------------------------------------------------------------ Olvidé mi contraseña

    /** Siempre responde igual (no revela si el correo existe). */
    public function requestReset(string $email): void
    {
        $row = $this->db()->selectOne(
            "select id, email, full_name from users where lower(email) = ? and status in ('active', 'locked') and deleted_at is null",
            [Str::lower(trim($email))]
        );
        if (! $row) {
            return;
        }
        $token = $this->issue('reset', $row->id, []);
        $this->mail($row->email, $row->full_name, 'Crea una contraseña nueva',
            'Pediste cambiar tu contraseña de EscudoSST. El enlace sirve una vez y vence en 60 minutos. Si no fuiste tú, ignora este correo: tu contraseña sigue igual.',
            'Crear contraseña nueva', $this->url("/restablecer/{$token}"));
        $this->audit($row->id, 'password_reset_requested', $row->id, [], 'public');
    }

    /** Para la pantalla del enlace: qué tipo es y para quién. */
    public function preview(string $token): array
    {
        $data = $this->find($token);
        $row = $this->user($data['user_id']);

        return ['purpose' => $data['purpose'], 'email' => $row->email, 'full_name' => $row->full_name, 'role' => self::ROLES[$row->platform_role] ?? null];
    }

    /** Crea la contraseña desde un enlace de restablecer o de invitación al equipo. Devuelve el correo. */
    public function setPassword(string $token, string $password): string
    {
        $data = $this->find($token);
        if (! in_array($data['purpose'], ['reset', 'team'], true)) {
            throw $this->invalid();
        }
        $this->consume($token, $data['purpose']);
        $row = $this->user($data['user_id']);
        if ($row->status === 'disabled') {
            throw $this->invalid();
        }
        $this->updateOwn(
            $row->id,
            "update users set password_hash = ?, status = 'active', email_verified_at = coalesce(email_verified_at, now()),
                    password_changed_at = now(), failed_login_count = 0, locked_until = null, updated_at = now() where id = ?",
            [Hash::make($password), $row->id]
        );
        $this->revokeTokens($row->id, null);
        $this->audit($row->id, $data['purpose'] === 'team' ? 'team_invitation_accepted' : 'password_reset', $row->id, [], 'public');

        return $row->email;
    }

    // ------------------------------------------------------------ Equipo de la plataforma

    public function team(): array
    {
        return array_map(function ($r) {
            $r->invite_pending = $r->status === 'invited' && Cache::has(self::PREFIX.'user:team:'.$r->id);

            return $r;
        }, $this->db()->select(
            "select id, full_name, email, platform_role, status, mfa_enabled, last_login_at, created_at
               from users where is_platform_staff and deleted_at is null
              order by (status = 'disabled'), platform_role, full_name"
        ));
    }

    /** $actor null = desde la consola del servidor (primer Dueño de la plataforma en producción). */
    public function invite(?string $actor, string $name, string $email, string $role): string
    {
        $email = Str::lower(trim($email));
        $existing = $this->db()->selectOne('select id, is_platform_staff from users where lower(email) = ?', [$email]);
        if ($existing) {
            throw $this->field('email', $existing->is_platform_staff
                ? 'Esa persona ya está en el equipo.'
                : 'Ese correo ya tiene una cuenta de empresa. Usa un correo de trabajo de EscudoSST distinto.');
        }
        $id = $this->db()->selectOne(
            "insert into users (email, full_name, status, is_platform_staff, platform_role, created_by) values (?, ?, 'invited', true, ?, ?) returning id",
            [$email, trim($name), $role, $actor]
        )->id;
        $this->audit($actor, 'team_member_invited', $id, ['email' => $email, 'role' => $role], $actor ? 'user' : 'system');
        $this->sendTeamInvite($id);

        return $id;
    }

    public function resendInvite(string $actor, string $id): void
    {
        $row = $this->member($id);
        if ($row->status !== 'invited') {
            throw $this->field('action', 'Esta persona ya creó su contraseña.');
        }
        $this->sendTeamInvite($id);
        $this->audit($actor, 'team_invitation_resent', $id, []);
    }

    public function setRole(string $actor, string $id, string $role): void
    {
        $row = $this->member($id);
        if ($row->platform_role === 'owner' && $role !== 'owner') {
            $this->guardLastOwner($id, 'No puedes quitar el último Dueño de la plataforma.');
        }
        $this->db()->update('update users set platform_role = ?, updated_at = now(), updated_by = ? where id = ?', [$role, $actor, $id]);
        $this->audit($actor, 'team_member_role_changed', $id, ['from' => $row->platform_role, 'to' => $role]);
    }

    public function setActive(string $actor, string $id, bool $active): void
    {
        $row = $this->member($id);
        if ($id === $actor) {
            throw $this->field('action', 'No puedes desactivarte a ti mismo.');
        }
        if (! $active && $row->platform_role === 'owner') {
            $this->guardLastOwner($id, 'No puedes desactivar al último Dueño de la plataforma.');
        }
        $status = $active ? ($row->password_hash ? 'active' : 'invited') : 'disabled';
        $this->db()->update('update users set status = ?, updated_at = now(), updated_by = ? where id = ?', [$status, $actor, $id]);
        if (! $active) {
            $this->revokeTokens($id, null);   // sale de inmediato
            Cache::forget(self::PREFIX.'user:team:'.$id);
        }
        $this->audit($actor, $active ? 'team_member_enabled' : 'team_member_disabled', $id, []);
    }

    // ------------------------------------------------------------ Apoyo

    private function sendTeamInvite(string $id): void
    {
        $row = $this->member($id);
        $token = $this->issue('team', $id, []);
        $role = self::ROLES[$row->platform_role] ?? 'equipo';
        $this->mail($row->email, $row->full_name, 'Te invitaron al panel de EscudoSST',
            "Te sumaron al equipo de la plataforma EscudoSST como {$role}. Crea tu contraseña con el botón (el enlace vence en 72 horas). "
            .'Al ingresar por primera vez configurarás la verificación en dos pasos con una app de autenticación.',
            'Crear mi contraseña', $this->url("/restablecer/{$token}"));
    }

    private function guardLastOwner(string $exceptId, string $msg): void
    {
        $others = $this->db()->selectOne(
            "select count(*) as n from users where is_platform_staff and platform_role = 'owner' and status = 'active' and deleted_at is null and id <> ?",
            [$exceptId]
        )->n;
        if ((int) $others === 0) {
            throw $this->field('action', $msg);
        }
    }

    private function issue(string $purpose, string $userId, array $extra): string
    {
        $userKey = self::PREFIX."user:{$purpose}:{$userId}";
        if ($previous = Cache::pull($userKey)) {
            Cache::forget(self::PREFIX.$previous);
        }
        $token = Str::random(48);
        $hash = hash('sha256', $token);
        $ttl = now()->addMinutes(self::TTL_MINUTES[$purpose]);
        Cache::put(self::PREFIX.$hash, ['purpose' => $purpose, 'user_id' => $userId] + $extra, $ttl);
        Cache::put($userKey, $hash, $ttl);

        return $token;
    }

    private function find(string $token): array
    {
        $data = strlen($token) === 48 ? Cache::get(self::PREFIX.hash('sha256', $token)) : null;

        return $data ?? throw $this->invalid();
    }

    private function consume(string $token, string $purpose): array
    {
        $data = $this->find($token);
        if ($data['purpose'] !== $purpose) {
            throw $this->invalid();
        }
        Cache::forget(self::PREFIX.hash('sha256', $token));
        Cache::forget(self::PREFIX."user:{$purpose}:{$data['user_id']}");

        return $data;
    }

    private function revokeTokens(string $userId, ?string $keepTokenId): void
    {
        DB::table('personal_access_tokens')
            ->where('tokenable_id', $userId)
            ->when($keepTokenId, fn ($q) => $q->where('id', '<>', $keepTokenId))
            ->delete();
    }

    private function user(string $id): object
    {
        return $this->db()->selectOne('select * from users where id = ? and deleted_at is null', [$id]) ?? throw $this->invalid();
    }

    private function member(string $id): object
    {
        return $this->db()->selectOne('select * from users where id = ? and is_platform_staff and deleted_at is null', [$id])
            ?? throw ApiProblem::notFound('Esa persona no está en el equipo.');
    }

    private function url(string $path): string
    {
        return rtrim((string) config('escudo.frontend_url'), '/').$path;
    }

    private function mail(string $to, string $name, string $title, string $intro, ?string $button = null, ?string $url = null): void
    {
        try {
            Mail::to($to)->send(new SiteRequestMail($name, $title, $intro, [], null, $button, $url));
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar un correo de la cuenta', ['title' => $title, 'error' => $e->getMessage()]);
            if ($button) {
                throw new ApiProblem(502, 'mail_failed', 'No se pudo enviar el correo', 'El correo no salió. Prueba de nuevo en unos minutos.');
            }
        }
    }

    private function audit(?string $actor, string $action, string $userId, array $changes, string $type = 'user'): void
    {
        $role = $actor ? $this->db()->selectOne('select platform_role from users where id = ?', [$actor])?->platform_role : null;
        $this->db()->insert(
            "insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes)
             values (null, ?, ?, ?, ?, 'USR', 'users', ?, ?::jsonb)",
            [$type === 'public' ? null : $actor, $role ? "platform_{$role}" : null, $type, $action, $userId, json_encode($changes, JSON_UNESCAPED_UNICODE)]
        );
    }

    private function field(string $field, string $msg): ApiProblem
    {
        return new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }

    private function invalid(): ApiProblem
    {
        return new ApiProblem(410, 'link_invalid', 'Enlace no válido', 'El enlace venció o ya se usó. Pide uno nuevo.');
    }
}
