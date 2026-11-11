<?php

namespace App\Modules\USR\Services;

use App\Core\Http\ApiProblem;
use App\Modules\SUS\Mail\SubscriptionNoticeMail;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Accesos de soporte (docs/05 §4.6, SCR-SUS-10; tabla support_access_grants).
 *
 *  1. El equipo de la plataforma SOLICITA acceso a una empresa: motivo, ticket y duración. Estado «requested».
 *     Se avisa a los administradores de cuenta de esa empresa (en el sistema y por correo).
 *  2. El administrador de cuenta AUTORIZA o RECHAZA. Al autorizar se crea una membresía temporal
 *     (rol platform_support, is_support_session) que vence sola. Estado «active».
 *  3. Mientras dure, la persona de soporte entra a la empresa SOLO EN LECTURA y nunca ve módulos médicos
 *     (App\Core\Auth\Permissions). Cualquiera de los dos lados puede REVOCAR antes del vencimiento.
 *  4. Al vencer pasa a «expired» (proceso cada 15 minutos; además app_my_memberships ya no la devuelve).
 *
 * Todo queda en la bitácora de la plataforma y en la de la empresa (el cliente ve quién entró).
 */
class SupportAccessService
{
    public const DURATIONS = [2, 8, 24, 72];   // horas permitidas

    public function __construct(private readonly AccountsService $accounts) {}

    private function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    // ---------------------------------------------------------------- Lado de la plataforma

    public function platformList(): array
    {
        $this->expire();

        return [
            'grants' => array_map($this->grantRow(...), $this->db()->select(
                "{$this->grantSelect()} order by case g.status when 'requested' then 0 when 'active' then 1 else 2 end, g.requested_at desc limit 300"
            )),
            'companies' => $this->db()->select(
                'select c.id, coalesce(c.trade_name, c.legal_name) as name, c.ruc, a.name as account_name
                   from companies c join accounts a on a.id = c.account_id and a.deleted_at is null
                  where c.deleted_at is null order by 2'
            ),
        ];
    }

    public function request(string $companyId, string $reason, ?string $ticket, int $hours, string $actor): array
    {
        $company = Str::isUuid($companyId)
            ? $this->db()->selectOne('select id, account_id, coalesce(trade_name, legal_name) as name from companies where id = ? and deleted_at is null', [$companyId])
            : null;
        if (! $company) {
            throw $this->validation('company_id', 'Elige una empresa.');
        }
        $admins = $this->admins($company->id);
        if (! $admins) {
            throw $this->validation('company_id', 'Esta empresa no tiene un administrador de cuenta activo que pueda autorizar el acceso.');
        }
        if ($this->db()->selectOne(
            "select 1 from support_access_grants where company_id = ? and support_user_id = ? and deleted_at is null
                and status in ('requested', 'active') and expires_at > now()",
            [$company->id, $actor]
        )) {
            throw $this->validation('company_id', 'Ya tienes una solicitud pendiente o un acceso activo en esta empresa.');
        }

        $id = $this->db()->selectOne(
            "insert into support_access_grants (company_id, support_user_id, requested_by, reason, ticket_reference, access_level, status, expires_at, created_by)
             values (?, ?, ?, ?, ?, 'read_only', 'requested', now() + make_interval(hours => ?), ?) returning id",
            [$company->id, $actor, $actor, $reason, $ticket, $hours, $actor]
        )->id;

        $this->accounts->audit($actor, 'support_access_requested', 'support_access_grants', $id, [
            'account_id' => $company->account_id, 'company' => $company->name, 'hours' => $hours, 'ticket' => $ticket,
        ], $reason);

        $requester = $this->db()->selectOne('select full_name from users where id = ?', [$actor])->full_name;
        $this->notifyAdmins($company->id, $company->name, $admins, $id,
            'Soporte de EscudoSST pide acceso a tu empresa',
            "{$requester} (soporte de EscudoSST) pide acceso de solo lectura a {$company->name} por {$hours} horas. Motivo: {$reason}. Nunca verá datos médicos. Puedes autorizarlo o rechazarlo en «Accesos de soporte».",
            'support.requested:'.$id
        );

        return ['id' => $id];
    }

    public function cancel(string $grantId, string $actor): void
    {
        $g = $this->findPlatform($grantId);
        if ($g->status !== 'requested') {
            throw $this->conflict('Solo se puede cancelar una solicitud que aún no fue respondida.');
        }
        $this->db()->update("update support_access_grants set status = 'revoked', revoked_at = now(), revoked_by = ?, updated_by = ? where id = ?", [$actor, $actor, $g->id]);
        $this->accounts->audit($actor, 'support_access_cancelled', 'support_access_grants', $g->id, ['account_id' => $g->account_id, 'company' => $g->company_name]);
    }

    public function revokeByPlatform(string $grantId, string $actor): void
    {
        $g = $this->findPlatform($grantId);
        if ($g->status !== 'active') {
            throw $this->conflict('Este acceso no está activo.');
        }
        $this->endGrant($g, 'revoked', $actor);
        $this->accounts->audit($actor, 'support_access_revoked', 'support_access_grants', $g->id, ['account_id' => $g->account_id, 'company' => $g->company_name, 'by' => 'platform']);
    }

    /** Vence los accesos cuyo plazo pasó (también lo hace el programador cada 15 minutos). */
    public function expire(): int
    {
        $rows = $this->db()->select(
            "select g.id, g.company_id, g.membership_id from support_access_grants g
              where g.deleted_at is null and g.status = 'active' and g.expires_at <= now()"
        );
        foreach ($rows as $g) {
            $this->db()->update("update support_access_grants set status = 'expired' where id = ?", [$g->id]);
            if ($g->membership_id) {
                $this->accounts->withCompany($g->company_id, fn (ConnectionInterface $db) => $db->update(
                    "update memberships set status = 'expired' where id = ? and status = 'active'", [$g->membership_id]
                ));
            }
        }
        // Solicitudes nunca respondidas cuyo plazo ya pasó.
        $this->db()->update("update support_access_grants set status = 'expired' where deleted_at is null and status = 'requested' and expires_at <= now()");

        return count($rows);
    }

    // ---------------------------------------------------------------- Lado del cliente (empresa activa, RLS)

    public function companyList(string $companyId): array
    {
        $this->expire();

        $rows = array_map($this->grantRow(...), DB::select(
            "{$this->grantSelect()} and g.company_id = ? order by case g.status when 'requested' then 0 when 'active' then 1 else 2 end, g.requested_at desc limit 100",
            [$companyId]
        ));

        // El cliente aún no «ve» al usuario de soporte (no tiene membresía): su nombre y correo se toman del
        // personal de la plataforma, para que sepa quién le pide acceso.
        $ids = array_values(array_unique(array_column($rows, 'support_user_id')));
        if ($ids) {
            $staff = collect($this->db()->select('select id, full_name, email from users where is_platform_staff and id = any(?::uuid[])', ['{'.implode(',', $ids).'}']))->keyBy('id');
            foreach ($rows as &$r) {
                $r['support_name'] = $staff[$r['support_user_id']]->full_name ?? $r['support_name'];
                $r['support_email'] = $staff[$r['support_user_id']]->email ?? null;
            }
        }

        return $rows;
    }

    public function approve(string $companyId, string $grantId, string $adminId): void
    {
        $g = $this->findCompany($companyId, $grantId);
        if ($g->status !== 'requested') {
            throw $this->conflict('Esta solicitud ya fue respondida.');
        }

        // La duración pedida corre desde que se autoriza.
        $times = DB::selectOne('select now() as starts, now() + (?::timestamptz - ?::timestamptz) as ends', [$g->expires_at, $g->requested_at]);
        $membershipId = DB::selectOne(
            "insert into memberships (company_id, user_id, role_id, status, invited_by, invited_at, accepted_at, valid_from, expires_at,
                                      is_support_session, support_access_grant_id, created_by)
             select ?, ?, r.id, 'active', ?, now(), now(), ?, ?, true, ?, ? from roles r where r.account_id is null and r.code = 'platform_support'
             returning id",
            [$companyId, $g->support_user_id, $adminId, $times->starts, $times->ends, $g->id, $adminId]
        )->id;
        DB::update(
            "update support_access_grants set status = 'active', authorized_by = ?, authorized_at = now(), starts_at = ?, expires_at = ?,
                    membership_id = ?, updated_by = ? where id = ?",
            [$adminId, $times->starts, $times->ends, $membershipId, $adminId, $g->id]
        );
        $this->companyAudit($companyId, $adminId, 'support_access_authorized', $g, ['until' => $times->ends]);
        $this->accounts->audit($adminId, 'support_access_authorized', 'support_access_grants', $g->id, ['company' => $g->company_name, 'until' => $times->ends]);
    }

    public function reject(string $companyId, string $grantId, string $adminId, ?string $reason): void
    {
        $g = $this->findCompany($companyId, $grantId);
        if ($g->status !== 'requested') {
            throw $this->conflict('Esta solicitud ya fue respondida.');
        }
        DB::update("update support_access_grants set status = 'rejected', updated_by = ? where id = ?", [$adminId, $g->id]);
        $this->companyAudit($companyId, $adminId, 'support_access_rejected', $g, [], $reason);
        $this->accounts->audit($adminId, 'support_access_rejected', 'support_access_grants', $g->id, ['company' => $g->company_name], $reason);
    }

    public function revokeByCompany(string $companyId, string $grantId, string $adminId): void
    {
        $g = $this->findCompany($companyId, $grantId);
        if ($g->status !== 'active') {
            throw $this->conflict('Este acceso no está activo.');
        }
        DB::update("update support_access_grants set status = 'revoked', revoked_at = now(), revoked_by = ?, updated_by = ? where id = ?", [$adminId, $adminId, $g->id]);
        if ($g->membership_id) {
            DB::update("update memberships set status = 'revoked' where id = ?", [$g->membership_id]);
        }
        $this->companyAudit($companyId, $adminId, 'support_access_revoked', $g);
        $this->accounts->audit($adminId, 'support_access_revoked', 'support_access_grants', $g->id, ['company' => $g->company_name, 'by' => 'company']);
    }

    // ---------------------------------------------------------------- Ayudantes

    private function endGrant(object $g, string $status, string $actor): void
    {
        $this->db()->update('update support_access_grants set status = ?, revoked_at = now(), revoked_by = ?, updated_by = ? where id = ?', [$status, $actor, $actor, $g->id]);
        if ($g->membership_id) {
            $this->accounts->withCompany($g->company_id, fn (ConnectionInterface $db) => $db->update(
                "update memberships set status = 'revoked' where id = ?", [$g->membership_id]
            ));
        }
    }

    private function grantSelect(): string
    {
        // users y companies se leen por la vista de cada conexión (plataforma: todo; empresa: RLS de su empresa).
        return 'select g.*, coalesce(c.trade_name, c.legal_name) as company_name, c.ruc, c.account_id,
                       su.full_name as support_name, su.email as support_email, au.full_name as authorized_name,
                       round(extract(epoch from (g.expires_at - coalesce(g.starts_at, g.requested_at))) / 3600) as hours
                  from support_access_grants g
                  join companies c on c.id = g.company_id
                  left join users su on su.id = g.support_user_id
                  left join users au on au.id = g.authorized_by
                 where g.deleted_at is null';
    }

    private function grantRow(object $g): array
    {
        return [
            'id' => $g->id,
            'company_id' => $g->company_id,
            'company_name' => $g->company_name,
            'ruc' => trim((string) $g->ruc),
            'account_id' => $g->account_id,
            'support_user_id' => $g->support_user_id,
            'support_name' => $g->support_name ?? 'Soporte de EscudoSST',
            'authorized_name' => $g->authorized_name,
            'reason' => $g->reason,
            'ticket_reference' => $g->ticket_reference,
            'access_level' => $g->access_level,
            'status' => $g->status,
            'hours' => (int) $g->hours,
            'requested_at' => $g->requested_at,
            'authorized_at' => $g->authorized_at,
            'starts_at' => $g->starts_at,
            'expires_at' => $g->expires_at,
            'revoked_at' => $g->revoked_at,
        ];
    }

    private function findPlatform(string $id): object
    {
        $g = Str::isUuid($id) ? $this->db()->selectOne(
            'select g.*, coalesce(c.trade_name, c.legal_name) as company_name, c.account_id
               from support_access_grants g join companies c on c.id = g.company_id where g.id = ? and g.deleted_at is null', [$id]
        ) : null;

        return $g ?? throw ApiProblem::notFound('El acceso no existe.');
    }

    private function findCompany(string $companyId, string $id): object
    {
        $g = Str::isUuid($id) ? DB::selectOne(
            'select g.*, coalesce(c.trade_name, c.legal_name) as company_name
               from support_access_grants g join companies c on c.id = g.company_id
              where g.id = ? and g.company_id = ? and g.deleted_at is null', [$id, $companyId]
        ) : null;

        return $g ?? throw ApiProblem::notFound('La solicitud no existe.');
    }

    /** Administradores de cuenta activos de la empresa (para autorizar y recibir el aviso). */
    private function admins(string $companyId): array
    {
        return $this->accounts->withCompany($companyId, fn (ConnectionInterface $db) => $db->select(
            "select u.id, u.full_name, u.email from memberships m join roles r on r.id = m.role_id join users u on u.id = m.user_id
              where m.company_id = ? and coalesce(r.base_role_code, r.code) = 'account_admin' and m.status = 'active' and m.deleted_at is null and u.deleted_at is null",
            [$companyId]
        ));
    }

    private function notifyAdmins(string $companyId, string $companyName, array $admins, string $grantId, string $title, string $body, string $dedupe): void
    {
        $this->accounts->withCompany($companyId, function (ConnectionInterface $db) use ($companyId, $admins, $grantId, $title, $body, $dedupe) {
            foreach ($admins as $a) {
                $db->insert(
                    "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, dedupe_key)
                     values (?, 'support.requested', 'support_access_grants', ?, ?, ?, ?, '/soporte', 'high', ?)",
                    [$companyId, $grantId, $a->id, $title, $body, $dedupe]
                );
            }
        });
        $url = rtrim(config('escudo.frontend_url'), '/').'/login';
        foreach ($admins as $a) {
            try {
                Mail::to($a->email)->send(new SubscriptionNoticeMail($a->full_name, $title, $body, 'info', $url));
            } catch (Throwable $e) {
                Log::warning('No se pudo enviar el aviso de acceso de soporte', ['error' => $e->getMessage()]);
            }
        }
    }

    /** Bitácora de la empresa: el cliente ve quién pidió y cuándo se autorizó, rechazó o revocó (RN-AUD-03). */
    private function companyAudit(string $companyId, string $actorId, string $action, object $g, array $extra = [], ?string $reason = null): void
    {
        DB::insert(
            "insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes, reason)
             values (?, ?, 'account_admin', 'user', ?, 'USR', 'support_access_grants', ?, ?::jsonb, ?)",
            [$companyId, $actorId, $action, $g->id, json_encode(['support_user_id' => $g->support_user_id] + $extra), $reason]
        );
    }

    private function conflict(string $detail): ApiProblem
    {
        return new ApiProblem(409, 'conflict', 'No se puede realizar la acción', $detail);
    }

    private function validation(string $field, string $message): ApiProblem
    {
        return new ApiProblem(422, 'validation_failed', 'Revisa los datos ingresados', $message, ['errors' => [$field => [$message]]]);
    }
}
