<?php

namespace App\Modules\USR\Http\Controllers;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\USR\Services\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Usuarios de la empresa activa (M-USR):
 *
 *  GET   /company/users                     personas con acceso, su rol y estado
 *  GET   /company/roles                     roles que se pueden asignar
 *  POST  /company/users                     invitar {email, full_name, role_code, worker_id?}
 *  PATCH /company/users/{membership}        cambiar rol o suspender/reactivar {role_code?, status?}
 *  POST  /company/users/{membership}/resend reenviar la invitación
 *
 * Reglas: nadie cambia su propio acceso; la empresa no se queda sin administrador de cuenta; solo un administrador
 * de cuenta asigna ese rol; los roles de la plataforma no se asignan aquí.
 */
class CompanyUsersController extends Controller
{
    private const ASSIGNABLE = ['account_admin', 'sst_manager', 'sst_supervisor', 'committee_member', 'management', 'area_lead', 'occupational_doctor',
        'health_staff', 'hr', 'trainer', 'consultant', 'external_auditor', 'worker', 'contractor_admin'];

    public function __construct(private InvitationService $invitations) {}

    public function index(): JsonResponse
    {
        $this->can('usr.memberships.read');

        return response()->json(['data' => DB::select(
            "select m.id, m.status, m.invited_at, m.accepted_at, m.last_access_at, u.id as user_id, u.full_name, u.email, r.code as role_code, r.name as role_name,
                    m.worker_id, w.full_name as worker_name
               from memberships m join users u on u.id = m.user_id join roles r on r.id = m.role_id left join workers w on w.id = m.worker_id
              where m.deleted_at is null and not m.is_support_session order by m.status = 'active' desc, u.full_name"
        )]);
    }

    public function roles(): JsonResponse
    {
        $this->can('usr.memberships.read');
        $isAdmin = app(CompanyContext::class)->roleCode === 'account_admin';

        return response()->json(['data' => array_values(array_filter(
            DB::select('select code, name, description, requires_mfa from roles where account_id is null and deleted_at is null order by name'),
            fn ($r) => in_array($r->code, self::ASSIGNABLE, true) && ($isAdmin || $r->code !== 'account_admin')
        ))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->can('usr.memberships.create');
        $ctx = app(CompanyContext::class);
        $email = Str::lower(trim((string) $request->input('email')));
        $name = trim((string) $request->input('full_name'));
        $role = (string) $request->input('role_code');
        $worker = $request->input('worker_id');
        $errors = [];
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'Escribe un correo válido.';
        }
        if (mb_strlen($name) < 3) {
            $errors['full_name'][] = 'Escribe el nombre completo.';
        }
        $this->assignable($role, $errors);
        if ($worker !== null && $worker !== '' && (! Str::isUuid((string) $worker) || ! DB::selectOne('select 1 as ok from workers where id = ? and deleted_at is null', [$worker]))) {
            $errors['worker_id'][] = 'El trabajador no existe.';
        }
        if ($errors) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Hay campos por corregir.', ['errors' => $errors]);
        }

        // El usuario es global (puede estar en varias empresas): se busca o crea con la conexión de la plataforma.
        $platform = DB::connection('platform');
        $user = $platform->selectOne('select id, status, full_name, is_platform_staff from users where lower(email) = ? and deleted_at is null', [$email]);
        if ($user?->is_platform_staff || $user?->status === 'disabled') {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Ese correo no se puede invitar.', ['errors' => ['email' => ['Ese correo no se puede invitar.']]]);
        }
        $userId = $user->id ?? $platform->selectOne("insert into users (email, full_name, status, created_by) values (?, ?, 'invited', ?) returning id",
            [$email, $name, (string) auth()->id()])->id;
        if (DB::selectOne('select 1 as ok from memberships where user_id = ? and company_id = ? and deleted_at is null', [$userId, $ctx->companyId])) {
            throw new ApiProblem(409, 'conflict', 'Ya tiene acceso', 'Esa persona ya tiene acceso a esta empresa. Cambia su rol desde la lista.');
        }
        $active = $user?->status === 'active';
        $membership = DB::selectOne(
            'insert into memberships (company_id, user_id, role_id, worker_id, status, invited_by, invited_at, accepted_at, created_by)
             select ?, ?, r.id, ?, ?, ?, now(), ?, ? from roles r where r.account_id is null and r.code = ? returning id',
            [$ctx->companyId, $userId, $worker ?: null, $active ? 'active' : 'invited', (string) auth()->id(), $active ? now() : null, (string) auth()->id(), $role]
        )->id;
        $this->audit('member_invited', $membership, ['email' => $email, 'role' => $role]);
        $this->invitations->send($userId, $email, $user->full_name ?? $name, $ctx->companyId, $ctx->companyName, $membership, ! $active);

        return response()->json(['data' => ['id' => $membership, 'status' => $active ? 'active' : 'invited']], 201);
    }

    public function update(Request $request, string $membership): JsonResponse
    {
        $this->can('usr.memberships.update');
        $m = $this->membership($membership);
        if ($m->user_id === (string) auth()->id()) {
            throw new ApiProblem(409, 'conflict', 'Tu propio acceso', 'No puedes cambiar tu propio rol ni suspenderte.');
        }
        $changes = [];
        if ($request->filled('role_code')) {
            $role = (string) $request->input('role_code');
            $errors = [];
            $this->assignable($role, $errors);
            if ($errors) {
                throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $errors['role_code'][0], ['errors' => $errors]);
            }
            if ($m->role_code === 'account_admin' && $role !== 'account_admin') {
                $this->keepAdmin($m->id);
            }
            DB::update('update memberships set role_id = (select id from roles where account_id is null and code = ?), updated_by = ? where id = ?', [$role, (string) auth()->id(), $m->id]);
            $changes['role'] = ['from' => $m->role_code, 'to' => $role];
        }
        if ($request->filled('status')) {
            $status = (string) $request->input('status');
            if (! in_array($status, ['active', 'suspended'], true) || ($status === 'active' && $m->status === 'invited')) {
                throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Solo se suspende o se reactiva un acceso ya aceptado.');
            }
            if ($status === 'suspended' && $m->role_code === 'account_admin') {
                $this->keepAdmin($m->id);
            }
            DB::update('update memberships set status = ?, updated_by = ? where id = ?', [$status, (string) auth()->id(), $m->id]);
            if ($status === 'suspended') {
                // Cierra sus sesiones en esta empresa en el siguiente pedido (la membresía ya no está activa).
                $changes['status'] = ['from' => $m->status, 'to' => 'suspended'];
            } else {
                $changes['status'] = ['from' => $m->status, 'to' => 'active'];
            }
        }
        $this->audit('member_updated', $m->id, $changes);

        return response()->json(['data' => ['id' => $m->id]]);
    }

    public function resend(string $membership): JsonResponse
    {
        $this->can('usr.memberships.update');
        $m = $this->membership($membership);
        if ($m->status !== 'invited') {
            throw new ApiProblem(409, 'conflict', 'Ya aceptó', 'Esa persona ya aceptó la invitación.');
        }
        $ctx = app(CompanyContext::class);
        $this->invitations->send($m->user_id, $m->email, $m->full_name, $ctx->companyId, $ctx->companyName, $m->id, true);

        return response()->json(['data' => ['sent' => true]]);
    }

    private function membership(string $id): object
    {
        $m = Str::isUuid($id) ? DB::selectOne(
            'select m.id, m.user_id, m.status, r.code as role_code, u.email, u.full_name from memberships m join roles r on r.id = m.role_id join users u on u.id = m.user_id
              where m.id = ? and m.deleted_at is null and not m.is_support_session', [$id]) : null;

        return $m ?? throw ApiProblem::notFound('Esa persona no tiene acceso a esta empresa.');
    }

    private function assignable(string $role, array &$errors): void
    {
        if (! in_array($role, self::ASSIGNABLE, true)) {
            $errors['role_code'][] = 'Elige un rol válido.';
        } elseif ($role === 'account_admin' && app(CompanyContext::class)->roleCode !== 'account_admin') {
            $errors['role_code'][] = 'Solo un administrador de cuenta puede dar ese rol.';
        }
    }

    private function keepAdmin(string $except): void
    {
        $others = (int) DB::selectOne("select count(*) as n from memberships m join roles r on r.id = m.role_id
                                        where r.code = 'account_admin' and m.status = 'active' and m.deleted_at is null and m.id <> ?", [$except])->n;
        if ($others === 0) {
            throw new ApiProblem(409, 'conflict', 'Último administrador', 'La empresa debe conservar al menos un administrador de cuenta activo.');
        }
    }

    private function can(string $permission): void
    {
        if (! in_array($permission, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no gestiona los usuarios de la empresa.');
        }
    }

    private function audit(string $action, string $id, array $changes): void
    {
        $ctx = app(CompanyContext::class);
        DB::insert(
            "insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes)
             values (?, ?, ?, 'user', ?, 'USR', 'memberships', ?, ?::jsonb)",
            [$ctx->companyId, (string) auth()->id(), $ctx->roleCode, $action, $id, json_encode($changes, JSON_UNESCAPED_UNICODE)]
        );
    }
}
