<?php

namespace App\Modules\Consultora;

use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Core\Support\Ruc;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Clientes de la consultora (M-CON SCR-CON-03; flujos §7, RN-CON-02/06/08):
 *  GET  /con/invitations                    invitaciones de la consultora con su estado
 *  POST /con/invitations                    invitar empresa {ruc, legal_name, admin_email, suggested_plan?, message?}
 *  POST /con/invitations/{id}/revoke        anular una invitación pendiente
 *  GET  /public/con/invitations/{token}     ver la invitación (sin sesión: correo del administrador invitado)
 *  POST /con/invitations/{token}/accept     el administrador de la empresa invitada (empresa activa) acepta la vinculación
 *  POST /con/link/revoke                    la empresa cliente quita el acceso a su consultora (acceso perdido de inmediato)
 *
 * La empresa conserva su plan: la consultora solo sugiere uno y la empresa elige y contrata el suyo (RN-CON-03).
 * Al aceptar, quien invitó entra a la empresa como consultor asignado. Las invitaciones vencen en
 * client_invite_ttl_days (parámetro) y sirven una sola vez.
 */
class InvitationsController extends Controller
{
    private const PLANS = ['E' => 'Esencial', 'P' => 'Profesional', 'C' => 'Corporativo'];

    public function index(): JsonResponse
    {
        $con = Consultancy::resolve();
        $this->p()->update("update client_invitations set status = 'expired', updated_at = now() where consultancy_account_id = ? and status = 'sent' and expires_at < now()", [$con->account_id]);
        $rows = $this->p()->select(
            "select i.id, trim(i.target_ruc) as ruc, i.target_legal_name as legal_name, i.invited_email, i.suggested_plan_code, i.status, i.sent_at, i.expires_at,
                    i.responded_at, i.company_id, coalesce(c.trade_name, c.legal_name) as company_name
               from client_invitations i left join companies c on c.id = i.company_id
              where i.consultancy_account_id = ? and i.deleted_at is null order by i.sent_at desc limit 100", [$con->account_id]);

        return response()->json(['data' => array_map(fn ($r) => (array) $r + ['suggested_plan' => self::PLANS[$r->suggested_plan_code] ?? null], $rows)]);
    }

    public function store(Request $request): JsonResponse
    {
        $con = Consultancy::resolve();
        $d = $request->validate([
            'ruc' => ['required', 'digits:11'],
            'legal_name' => ['required', 'string', 'min:3', 'max:160'],
            'admin_email' => ['required', 'email:rfc', 'max:160'],
            'suggested_plan' => ['nullable', 'in:E,P,C'],
            'message' => ['nullable', 'string', 'max:500'],
        ], [
            'ruc.required' => 'Escribe el RUC de la empresa.', 'ruc.digits' => 'El RUC tiene 11 dígitos.',
            'legal_name.required' => 'Escribe la razón social.', 'admin_email.required' => 'Escribe el correo del administrador.',
            'admin_email.email' => 'Revisa el correo del administrador.',
        ]);
        if (! Ruc::isValid($d['ruc'])) {
            throw ValidationException::withMessages(['ruc' => 'El RUC no es válido: revisa los 11 dígitos.']);
        }
        $company = $this->p()->selectOne('select id, managed_by_account_id, account_id, coalesce(trade_name, legal_name) as name from companies where ruc = ? and deleted_at is null', [$d['ruc']]);
        if ($company && ($company->managed_by_account_id === $con->account_id || $company->account_id === $con->account_id)) {
            throw ValidationException::withMessages(['ruc' => 'Esta empresa ya es parte de tu consultora.']);
        }
        if ($company && $company->managed_by_account_id) {
            throw ValidationException::withMessages(['ruc' => 'Esta empresa ya la gestiona otra consultora. Para vincularla, primero debe quitarle el acceso.']);
        }
        if ($this->p()->selectOne("select 1 as ok from client_invitations where consultancy_account_id = ? and target_ruc = ? and status = 'sent' and expires_at > now() and deleted_at is null",
            [$con->account_id, $d['ruc']])) {
            throw ValidationException::withMessages(['ruc' => 'Ya enviaste una invitación a esta empresa y sigue vigente.']);
        }
        $days = (int) LegalParams::number('client_invite_ttl_days', 7);
        $token = Str::random(48);
        $email = Str::lower(trim($d['admin_email']));
        $id = $this->p()->selectOne(
            "insert into client_invitations (consultancy_account_id, company_id, target_ruc, target_legal_name, invited_email, token_hash, message, suggested_plan_code,
                                             invited_by_user_id, expires_at, created_by)
             values (?, ?, ?, ?, ?, decode(?, 'hex'), ?, ?, ?, now() + make_interval(days => ?), ?) returning id",
            [$con->account_id, $company?->id, $d['ruc'], trim($d['legal_name']), $email, hash('sha256', $token), $d['message'] ?? null, $d['suggested_plan'] ?? null,
                (string) auth()->id(), $days, (string) auth()->id()])->id;
        app(AccountsService::class)->audit((string) auth()->id(), 'client_invitation_sent', 'client_invitations', $id, ['ruc' => $d['ruc'], 'email' => $email]);

        $url = rtrim((string) config('escudo.frontend_url'), '/').'/invitacion-consultora/'.$token;
        Mail::to($email)->send(new ClientInvitationMail($con->name, $company->name ?? trim($d['legal_name']), $d['ruc'],
            self::PLANS[$d['suggested_plan'] ?? ''] ?? null, (bool) $company, $url, $days));

        return response()->json(['data' => ['id' => $id, 'status' => 'sent', 'company_exists' => (bool) $company]], 201);
    }

    public function revoke(string $id): JsonResponse
    {
        $con = Consultancy::resolve();
        $n = $this->p()->update("update client_invitations set status = 'cancelled', revoked_at = now(), updated_at = now(), updated_by = ?
                                   where id = ? and consultancy_account_id = ? and status = 'sent'", [(string) auth()->id(), $id, $con->account_id]);
        if (! $n) {
            throw ApiProblem::notFound('La invitación no existe o ya no está pendiente.');
        }

        return response()->json(['data' => ['id' => $id, 'status' => 'cancelled']]);
    }

    public function preview(string $token): JsonResponse
    {
        $i = $this->byToken($token);

        return response()->json(['data' => [
            'consultancy' => $i->consultancy,
            'ruc' => trim($i->target_ruc),
            'legal_name' => $i->company_name ?? $i->target_legal_name,
            'suggested_plan' => self::PLANS[$i->suggested_plan_code] ?? null,
            'company_exists' => (bool) $i->existing_company_id,
            'status' => $i->status === 'sent' && strtotime($i->expires_at) < time() ? 'expired' : $i->status,
            'expires_at' => $i->expires_at,
            'message' => $i->message,
        ]]);
    }

    public function accept(string $token, AccountsService $accounts): JsonResponse
    {
        $ctx = app(CompanyContext::class);
        $i = $this->byToken($token);
        if ($i->status !== 'sent' || strtotime($i->expires_at) < time()) {
            throw self::conflict($i->status === 'accepted' ? 'Esta invitación ya fue aceptada.' : 'La invitación venció o fue anulada. Pide a tu consultora que te envíe otra.');
        }
        if (trim($i->target_ruc) !== $ctx->ruc) {
            throw ApiProblem::forbidden('La invitación es para la empresa con RUC '.trim($i->target_ruc).'. Elige esa empresa para aceptarla.');
        }
        if (! in_array($ctx->roleCode, ['account_admin', 'sst_manager'], true)) {
            throw ApiProblem::forbidden('La vinculación la acepta el administrador de la cuenta o el Jefe SST de la empresa.');
        }
        $company = $this->p()->selectOne('select id, account_id, managed_by_account_id from companies where id = ?', [$ctx->companyId]);
        if ($company->account_id === $i->consultancy_account_id || $company->managed_by_account_id === $i->consultancy_account_id) {
            throw self::conflict('Tu empresa ya trabaja con esta consultora.');
        }
        if ($company->managed_by_account_id) {
            throw self::conflict('Tu empresa ya la gestiona otra consultora: primero quítale el acceso.');
        }
        $this->p()->transaction(function () use ($i, $ctx) {
            $this->p()->update('update companies set managed_by_account_id = ?, updated_at = now() where id = ? and managed_by_account_id is null', [$i->consultancy_account_id, $ctx->companyId]);
            $this->p()->update("update client_invitations set status = 'accepted', company_id = ?, responded_at = now(), responded_by = ?, updated_at = now() where id = ?",
                [$ctx->companyId, (string) auth()->id(), $i->id]);
        });
        // Quien invitó entra como consultor asignado (líder). Si ya tenía acceso, solo se registra la asignación.
        if ($i->invited_by_user_id) {
            $accounts->withCompany($ctx->companyId, function (ConnectionInterface $db) use ($i, $ctx) {
                $m = $db->selectOne('select id from memberships where company_id = ? and user_id = ? and deleted_at is null', [$ctx->companyId, $i->invited_by_user_id]);
                $mid = $m->id ?? $db->selectOne(
                    "insert into memberships (company_id, user_id, role_id, status, invited_by, invited_at, accepted_at, created_by)
                     select ?, ?, r.id, 'active', ?, now(), now(), ? from roles r where r.account_id is null and r.code = 'consultant' returning id",
                    [$ctx->companyId, $i->invited_by_user_id, (string) auth()->id(), (string) auth()->id()])->id;
                $db->insert(
                    "insert into consultant_assignments (company_id, consultancy_account_id, consultant_user_id, membership_id, is_lead, created_by)
                     select ?, ?, ?, ?, true, ? where not exists (select 1 from consultant_assignments where consultant_user_id = ? and status = 'active' and deleted_at is null)",
                    [$ctx->companyId, $i->consultancy_account_id, $i->invited_by_user_id, $mid, (string) auth()->id(), $i->invited_by_user_id]);
            });
        }
        $accounts->audit((string) auth()->id(), 'client_invitation_accepted', 'client_invitations', $i->id, ['company_id' => $ctx->companyId]);

        return response()->json(['data' => ['status' => 'accepted', 'consultancy' => $i->consultancy]]);
    }

    /** La empresa cliente quita el acceso a su consultora (RN-CON-02/08): los datos quedan en la empresa. */
    public function unlink(AccountsService $accounts): JsonResponse
    {
        $ctx = app(CompanyContext::class);
        if (! in_array($ctx->roleCode, ['account_admin', 'sst_manager'], true)) {
            throw ApiProblem::forbidden('Quitar el acceso a la consultora lo hace el administrador de la cuenta o el Jefe SST.');
        }
        $account = $this->p()->selectOne('select managed_by_account_id from companies where id = ?', [$ctx->companyId])?->managed_by_account_id;
        if (! $account) {
            throw self::conflict('Tu empresa no trabaja con una consultora.');
        }
        $this->p()->update('update companies set managed_by_account_id = null, updated_at = now() where id = ?', [$ctx->companyId]);
        $accounts->withCompany($ctx->companyId, function (ConnectionInterface $db) use ($account, $ctx) {
            $users = array_column($db->select("select consultant_user_id from consultant_assignments where consultancy_account_id = ? and status = 'active' and deleted_at is null", [$account]), 'consultant_user_id');
            $db->update("update consultant_assignments set status = 'revoked', ends_on = current_date, updated_at = now() where consultancy_account_id = ? and status = 'active'", [$account]);
            if ($users) {
                $db->update("update memberships m set status = 'revoked', updated_at = now() from roles r
                              where r.id = m.role_id and r.code = 'consultant' and m.company_id = ? and m.user_id = any(?::uuid[]) and m.status = 'active'", [$ctx->companyId, '{'.implode(',', $users).'}']);
            }
        });
        $accounts->audit((string) auth()->id(), 'consultancy_unlinked', 'companies', $ctx->companyId, ['consultancy_account_id' => $account]);

        return response()->json(['data' => ['status' => 'unlinked']]);
    }

    private function byToken(string $token): object
    {
        $i = strlen($token) === 48 ? $this->p()->selectOne(
            "select i.*, coalesce(w.brand_name, a.name) as consultancy, c.id as existing_company_id, coalesce(c.trade_name, c.legal_name) as company_name
               from client_invitations i join accounts a on a.id = i.consultancy_account_id
               left join white_label_settings w on w.account_id = a.id and w.deleted_at is null
               left join companies c on c.ruc = i.target_ruc and c.deleted_at is null
              where i.token_hash = decode(?, 'hex') and i.deleted_at is null", [hash('sha256', $token)]) : null;

        return $i ?? throw ApiProblem::notFound('La invitación no existe o el enlace está incompleto.');
    }

    private static function conflict(string $detail): ApiProblem
    {
        return new ApiProblem(409, 'conflict', 'No se puede hacer este cambio', $detail);
    }

    private function p(): ConnectionInterface
    {
        return DB::connection('platform');
    }
}
