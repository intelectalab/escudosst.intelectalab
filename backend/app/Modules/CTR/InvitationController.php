<?php

namespace App\Modules\CTR;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\Ruc;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\REC\RecordsService;
use App\Modules\REC\Registry;
use App\Modules\USR\Services\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Invitar contratista (M-CTR SCR-CTR-02):
 *
 *  GET  /ctr/invitations                    contratistas con su acceso al portal (correo del administrador y estado)
 *  POST /ctr/invitations                    {ruc, legal_name, admin_email, admin_name?, services_description?, risk_level?}
 *  POST /ctr/invitations/{contractor}/resend reenviar la invitación pendiente
 *
 * Crea (o reutiliza por RUC, RN-CTR-01) el contratista, un usuario con rol contractor_admin y su membresía acotada al
 * contratista (membership_scopes 'contractor'), y envía la invitación por correo para que cree su contraseña.
 */
class InvitationController extends Controller
{
    public function __construct(private RecordsService $records, private InvitationService $invitations) {}

    public function index(): JsonResponse
    {
        $this->can('ctr.contractors.read');
        $rows = DB::select(
            "select c.id, c.ruc, c.legal_name, c.services_description, c.risk_level, c.status,
                    (select json_agg(json_build_object('membership_id', m.id, 'email', u.email, 'full_name', u.full_name, 'status', m.status, 'invited_at', m.invited_at, 'accepted_at', m.accepted_at) order by m.invited_at)
                       from membership_scopes s join memberships m on m.id = s.membership_id and m.deleted_at is null join users u on u.id = m.user_id
                      where s.contractor_id = c.id and s.scope_type = 'contractor' and s.deleted_at is null) as admins
               from contractors c where c.deleted_at is null order by c.legal_name"
        );
        foreach ($rows as $r) {
            $r->admins = $r->admins ? json_decode($r->admins, true) : [];
        }

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->can('ctr.contractors.create');
        $ctx = app(CompanyContext::class);
        $ruc = preg_replace('/\D/', '', (string) $request->input('ruc'));
        $legal = trim((string) $request->input('legal_name'));
        $email = Str::lower(trim((string) $request->input('admin_email')));
        $name = trim((string) $request->input('admin_name')) ?: $legal;
        $service = trim((string) $request->input('services_description'));
        $risk = $request->input('risk_level');
        $errors = [];
        if (! Ruc::isValid($ruc)) {
            $errors['ruc'][] = 'El RUC no es válido (11 dígitos).';
        }
        if (mb_strlen($legal) < 3) {
            $errors['legal_name'][] = 'Escribe la razón social.';
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['admin_email'][] = 'Escribe un correo válido.';
        }
        if ($risk !== null && $risk !== '' && ! in_array($risk, ['low', 'medium', 'high', 'very_high'], true)) {
            $errors['risk_level'][] = 'Elige un nivel de riesgo válido.';
        }
        if ($errors) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Hay campos por corregir.', ['errors' => $errors]);
        }

        $platform = DB::connection('platform');
        $user = $platform->selectOne('select id, status, full_name, is_platform_staff from users where lower(email) = ? and deleted_at is null', [$email]);
        if ($user?->is_platform_staff || $user?->status === 'disabled') {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Ese correo no se puede invitar.', ['errors' => ['admin_email' => ['Ese correo no se puede invitar.']]]);
        }
        if ($user && DB::selectOne('select 1 as ok from memberships where user_id = ? and company_id = ? and deleted_at is null', [$user->id, $ctx->companyId])) {
            throw new ApiProblem(409, 'conflict', 'Ya tiene acceso', 'Ese correo ya tiene acceso a esta empresa con otro rol. Usa otro correo para el administrador del contratista.');
        }

        // RN-CTR-01: un contratista por RUC en la empresa principal; si ya existe se invita a su portal.
        $contractor = DB::selectOne('select id, legal_name from contractors where ruc = ? and deleted_at is null', [$ruc]);
        $created = false;
        if (! $contractor) {
            $row = $this->records->create(Registry::resource('CTR', 'contractors'), array_filter([
                'ruc' => $ruc, 'legal_name' => $legal, 'contact_name' => $name, 'contact_email' => $email,
                'services_description' => $service ?: null, 'risk_level' => $risk ?: null,
            ], fn ($v) => $v !== null));
            $contractor = (object) ['id' => $row['id'], 'legal_name' => $legal];
            $created = true;
        } else {
            DB::update('update contractors set contact_email = coalesce(contact_email, ?), updated_by = ? where id = ?', [$email, (string) auth()->id(), $contractor->id]);
        }

        $userId = $user->id ?? $platform->selectOne("insert into users (email, full_name, status, created_by) values (?, ?, 'invited', ?) returning id",
            [$email, $name, (string) auth()->id()])->id;
        $active = $user?->status === 'active';
        $membership = DB::selectOne(
            "insert into memberships (company_id, user_id, role_id, status, invited_by, invited_at, accepted_at, created_by)
             select ?, ?, r.id, ?, ?, now(), ?, ? from roles r where r.account_id is null and r.code = 'contractor_admin' returning id",
            [$ctx->companyId, $userId, $active ? 'active' : 'invited', (string) auth()->id(), $active ? now() : null, (string) auth()->id()]
        )->id;
        DB::insert("insert into membership_scopes (company_id, membership_id, scope_type, contractor_id, created_by) values (?, ?, 'contractor', ?, ?)",
            [$ctx->companyId, $membership, $contractor->id, (string) auth()->id()]);
        $this->audit('contractor_invited', $membership, ['email' => $email, 'contractor_id' => $contractor->id, 'role' => 'contractor_admin']);
        $this->invitations->send($userId, $email, $user->full_name ?? $name, $ctx->companyId, $this->portalName($ctx->companyName), $membership, ! $active);

        return response()->json(['data' => [
            'contractor_id' => $contractor->id, 'contractor_created' => $created, 'membership_id' => $membership, 'status' => $active ? 'active' : 'invited',
        ]], 201);
    }

    public function resend(string $contractor): JsonResponse
    {
        $this->can('ctr.contractors.create');
        $ctx = app(CompanyContext::class);
        $m = Str::isUuid($contractor) ? DB::selectOne(
            "select m.id, m.user_id, u.email, u.full_name from membership_scopes s join memberships m on m.id = s.membership_id and m.deleted_at is null
               join users u on u.id = m.user_id
              where s.contractor_id = ? and s.scope_type = 'contractor' and s.deleted_at is null and m.status = 'invited' order by m.invited_at desc limit 1",
            [$contractor]) : null;
        if (! $m) {
            throw new ApiProblem(409, 'conflict', 'Sin invitación pendiente', 'Este contratista no tiene una invitación pendiente: ya aceptó o aún no se le invitó.');
        }
        $this->invitations->send($m->user_id, $m->email, $m->full_name, $ctx->companyId, $this->portalName($ctx->companyName), $m->id, true);

        return response()->json(['data' => ['sent' => true]]);
    }

    private function portalName(string $company): string
    {
        return 'el portal de contratistas de '.$company;
    }

    private function can(string $permission): void
    {
        if (! in_array($permission, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no gestiona contratistas.');
        }
    }

    private function audit(string $action, string $id, array $changes): void
    {
        $ctx = app(CompanyContext::class);
        DB::insert(
            "insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes)
             values (?, ?, ?, 'user', ?, 'CTR', 'memberships', ?, ?::jsonb)",
            [$ctx->companyId, (string) auth()->id(), $ctx->roleCode, $action, $id, json_encode($changes, JSON_UNESCAPED_UNICODE)]
        );
    }
}
