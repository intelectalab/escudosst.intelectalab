<?php

namespace App\Modules\USR\Services;

use App\Core\Http\ApiProblem;
use App\Modules\USR\Mail\AccountInvitationMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Invitación por correo a una empresa (hoy: administrador de cuenta creado desde el panel de la plataforma).
 *
 * - El enlace lleva un token aleatorio; en caché solo se guarda su hash (sha256), con vencimiento.
 * - Cada reenvío invalida el enlace anterior.
 * - Al aceptar, la persona crea su contraseña; el primer inicio de sesión le pide configurar MFA
 *   (account_admin está en escudo.auth.mfa_required_roles).
 * - Si el usuario ya existía y estaba activo, no hay token: solo se le avisa que tiene acceso.
 */
class InvitationService
{
    private const PREFIX = 'invitation:';

    public function send(string $userId, string $email, string $name, string $companyId, string $companyName, string $membershipId, bool $needsPassword, ?string $roleName = null): void
    {
        $hours = (int) config('escudo.subscriptions.invitation_ttl_hours');
        $url = null;

        if ($needsPassword) {
            if ($previous = Cache::pull(self::PREFIX.'user:'.$userId)) {
                Cache::forget(self::PREFIX.$previous);
            }
            $token = Str::random(48);
            $hash = hash('sha256', $token);
            $ttl = now()->addHours($hours);
            Cache::put(self::PREFIX.$hash, compact('userId', 'email', 'name', 'companyId', 'companyName', 'membershipId'), $ttl);
            Cache::put(self::PREFIX.'user:'.$userId, $hash, $ttl);
            $url = rtrim(config('escudo.frontend_url'), '/').'/invitacion/'.$token;
        }

        Mail::to($email)->send(new AccountInvitationMail($name, $companyName, $url, $hours, $roleName));
    }

    /** @return array{full_name: string, email: string, company_name: string} */
    public function preview(string $token): array
    {
        $data = $this->find($token);

        return ['full_name' => $data['name'], 'email' => $data['email'], 'company_name' => $data['companyName']];
    }

    /** Crea la contraseña y activa usuario y membresía. Devuelve el correo para iniciar sesión. */
    public function accept(string $token, string $password): string
    {
        $data = $this->find($token);
        $db = DB::connection('pgsql');

        $db->transaction(function () use ($db, $data, $password) {
            // users y memberships se ven por la membresía en esa empresa (RLS con app.company_id).
            $db->select("select set_config('app.company_id', ?, true)", [$data['companyId']]);
            $updated = $db->update(
                "update users set password_hash = ?, status = 'active', email_verified_at = coalesce(email_verified_at, now()),
                        password_changed_at = now(), failed_login_count = 0, locked_until = null
                  where id = ? and status = 'invited' and deleted_at is null",
                [Hash::make($password), $data['userId']]
            );
            if ($updated === 0) {
                throw $this->invalid();
            }
            $db->update(
                "update memberships set status = 'active', accepted_at = now() where id = ? and status = 'invited' and deleted_at is null",
                [$data['membershipId']]
            );
        });

        Cache::forget(self::PREFIX.hash('sha256', $token));
        Cache::forget(self::PREFIX.'user:'.$data['userId']);

        return $data['email'];
    }

    private function find(string $token): array
    {
        $data = strlen($token) === 48 ? Cache::get(self::PREFIX.hash('sha256', $token)) : null;

        return $data ?? throw $this->invalid();
    }

    private function invalid(): ApiProblem
    {
        return new ApiProblem(410, 'invitation_invalid', 'Invitación no válida',
            'El enlace venció o ya se usó. Pide al equipo de EscudoSST que te envíe una nueva invitación.');
    }
}
