<?php

namespace App\Modules\USR\Http\Controllers;

use App\Core\Auth\Permissions;
use App\Core\Auth\Roles;
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
 *  GET   /company/roles                     roles que se pueden asignar (base y personalizados de la cuenta)
 *  POST  /company/users                     invitar {email, full_name, role_code, worker_id?}
 *  PATCH /company/users/{membership}        cambiar rol o suspender/reactivar {role_code?, status?}
 *  POST  /company/users/{membership}/resend reenviar la invitación
 *
 * Reglas: nadie cambia su propio acceso; la empresa no se queda sin administrador de cuenta; solo un administrador
 * de cuenta asigna ese rol; los roles de la plataforma no se asignan aquí. role_code es el código de un rol base o de un
 * rol personalizado de la cuenta (App\Core\Auth\Roles): las reglas se deciden con su rol base.
 */
class CompanyUsersController extends Controller
{
    private const ASSIGNABLE = ['account_admin', 'sst_manager', 'sst_supervisor', 'committee_member', 'management', 'area_lead', 'occupational_doctor',
        'health_staff', 'hr', 'trainer', 'consultant', 'external_auditor', 'worker', 'contractor_admin'];

    public function __construct(private InvitationService $invitations) {}

    public function index(): JsonResponse
    {
        $this->can('usr.memberships.read');

        $rows = DB::select(
            "select m.id, case when m.status = 'active' and m.expires_at is not null and m.expires_at <= now() then 'expired' else m.status end as status,
                    m.invited_at, m.accepted_at, coalesce(m.last_access_at, u.last_login_at) as last_access_at, m.expires_at,
                    u.id as user_id, u.full_name, u.email, u.mfa_enabled, r.code as role_code, coalesce(r.base_role_code, r.code) as base_role_code, r.name as role_name,
                    r.account_id is not null as is_custom_role, r.requires_mfa,
                    m.worker_id, w.full_name as worker_name
               from memberships m join users u on u.id = m.user_id join roles r on r.id = m.role_id left join workers w on w.id = m.worker_id
              where m.deleted_at is null and not m.is_support_session order by m.status = 'active' desc, u.full_name"
        );
        // Alcance (SCR-USR-02): sin filas en membership_scopes = toda la empresa.
        $scopes = [];
        foreach (DB::select(
            'select s.membership_id, s.scope_type, s.site_id, s.area_id, coalesce(si.name, ar.name, c.legal_name, s.module_code) as name
               from membership_scopes s left join sites si on si.id = s.site_id left join areas ar on ar.id = s.area_id left join contractors c on c.id = s.contractor_id
              where s.deleted_at is null order by s.scope_type, 5'
        ) as $s) {
            $scopes[$s->membership_id][] = ['type' => $s->scope_type, 'id' => $s->site_id ?? $s->area_id, 'name' => $s->name];
        }
        foreach ($rows as $r) {
            $r->mfa_enabled = (bool) $r->mfa_enabled;
            $r->requires_mfa = (bool) $r->requires_mfa;
            $r->is_custom_role = (bool) $r->is_custom_role;
            $r->scopes = $scopes[$r->id] ?? [];
        }

        return response()->json(['data' => $rows]);
    }

    public function roles(): JsonResponse
    {
        $this->can('usr.memberships.read');
        $isAdmin = app(CompanyContext::class)->roleCode === 'account_admin';
        // Roles base y los personalizados de la cuenta de la empresa (RLS: account_id de la empresa activa).
        $rows = DB::select(
            'select r.code, r.name, r.description, r.requires_mfa, coalesce(r.base_role_code, r.code) as base_role_code, r.account_id is not null as is_custom
               from roles r
              where r.deleted_at is null
                and (r.account_id is null or r.account_id = (select c.account_id from companies c where c.id = ?))
              order by r.account_id is not null, r.name',
            [app(CompanyContext::class)->companyId]
        );

        return response()->json(['data' => array_values(array_map(function ($r) {
            $r->requires_mfa = (bool) $r->requires_mfa;
            $r->is_custom = (bool) $r->is_custom;

            return $r;
        }, array_filter($rows, fn ($r) => in_array($r->base_role_code, self::ASSIGNABLE, true) && ($isAdmin || $r->base_role_code !== 'account_admin'))))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->can('usr.memberships.create');
        $ctx = app(CompanyContext::class);
        $email = Str::lower(trim((string) $request->input('email')));
        $name = trim((string) $request->input('full_name'));
        $role = (string) $request->input('role_code');
        $worker = $request->input('worker_id');
        $roleRow = null;
        $errors = [];
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'Escribe un correo válido.';
        }
        if (mb_strlen($name) < 3) {
            $errors['full_name'][] = 'Escribe el nombre completo.';
        }
        $roleRow = $this->assignable($role, $errors);
        if ($worker !== null && $worker !== '' && (! Str::isUuid((string) $worker) || ! DB::selectOne('select 1 as ok from workers where id = ? and deleted_at is null', [$worker]))) {
            $errors['worker_id'][] = 'El trabajador no existe.';
        }
        [$sites, $areas] = $this->scopeInput($request, $errors);
        $expires = $this->expiresInput($request, $roleRow->base ?? $role, $errors);
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
            'insert into memberships (company_id, user_id, role_id, worker_id, status, invited_by, invited_at, accepted_at, expires_at, created_by)
             values (?, ?, ?, ?, ?, ?, now(), ?, ?, ?) returning id',
            [$ctx->companyId, $userId, $roleRow->id, $worker ?: null, $active ? 'active' : 'invited', (string) auth()->id(), $active ? now() : null, $expires, (string) auth()->id()]
        )->id;
        $this->saveScopes($membership, $sites, $areas);
        $this->audit('member_invited', $membership, array_filter(['email' => $email, 'role' => $role, 'sites' => $sites ?: null, 'areas' => $areas ?: null, 'expires_at' => $expires]));
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
        // La vigencia se valida antes de tocar el rol: un auditor externo nunca queda sin fecha de fin.
        $expires = null;
        if ($request->has('expires_on')) {
            $errors = [];
            $expires = $this->expiresInput($request, Roles::base((string) ($request->input('role_code') ?: $m->role_code)), $errors);
            if ($errors) {
                throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $errors['expires_on'][0], ['errors' => $errors]);
            }
        }
        if ($request->filled('role_code')) {
            $role = (string) $request->input('role_code');
            $errors = [];
            $roleRow = $this->assignable($role, $errors);
            if ($errors) {
                throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $errors['role_code'][0], ['errors' => $errors]);
            }
            if ($m->base_role_code === 'account_admin' && $roleRow->base !== 'account_admin') {
                $this->keepAdmin($m->id);
            }
            if ($roleRow->base === 'external_auditor' && ! $m->expires_at && ! $request->filled('expires_on')) {
                throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'El auditor externo necesita una fecha de fin del acceso.', ['errors' => ['expires_on' => ['Indica hasta cuándo tendrá acceso.']]]);
            }
            DB::update('update memberships set role_id = ?, updated_by = ? where id = ?', [$roleRow->id, (string) auth()->id(), $m->id]);
            $changes['role'] = ['from' => $m->role_code, 'to' => $role];
            Permissions::flush();
        }
        // Alcance por sede/área y vigencia (SCR-USR-03): solo si vienen en el pedido.
        if ($request->has('site_ids') || $request->has('area_ids')) {
            $errors = [];
            [$sites, $areas] = $this->scopeInput($request, $errors);
            if ($errors) {
                throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Revisa el alcance.', ['errors' => $errors]);
            }
            DB::update("update membership_scopes set deleted_at = now(), updated_by = ? where membership_id = ? and scope_type in ('site', 'area') and deleted_at is null", [(string) auth()->id(), $m->id]);
            $this->saveScopes($m->id, $sites, $areas);
            $changes['scope'] = ['sites' => $sites, 'areas' => $areas];
        }
        if ($request->has('expires_on')) {
            DB::update('update memberships set expires_at = ?, updated_by = ? where id = ?', [$expires, (string) auth()->id(), $m->id]);
            $changes['expires_at'] = ['from' => $m->expires_at, 'to' => $expires];
        }
        if ($request->filled('status')) {
            $status = (string) $request->input('status');
            if (! in_array($status, ['active', 'suspended'], true) || ($status === 'active' && $m->status === 'invited')) {
                throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Solo se suspende o se reactiva un acceso ya aceptado.');
            }
            if ($status === 'suspended' && $m->base_role_code === 'account_admin') {
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
            'select m.id, m.user_id, m.status, m.expires_at, r.code as role_code, coalesce(r.base_role_code, r.code) as base_role_code, u.email, u.full_name from memberships m join roles r on r.id = m.role_id join users u on u.id = m.user_id
              where m.id = ? and m.deleted_at is null and not m.is_support_session', [$id]) : null;

        return $m ?? throw ApiProblem::notFound('Esa persona no tiene acceso a esta empresa.');
    }

    /**
     * Rol que se puede asignar: uno base de la lista o uno personalizado (vigente) de la cuenta de la empresa.
     *
     * @return object|null {id, code, base}
     */
    private function assignable(string $role, array &$errors): ?object
    {
        $row = $role === '' ? null : DB::selectOne(
            'select r.id, r.code, coalesce(r.base_role_code, r.code) as base from roles r
              where r.code = ? and r.deleted_at is null
                and (r.account_id is null or r.account_id = (select c.account_id from companies c where c.id = ?))
              order by r.account_id nulls first limit 1',
            [$role, app(CompanyContext::class)->companyId]
        );
        if (! $row || ! in_array($row->base, self::ASSIGNABLE, true)) {
            $errors['role_code'][] = 'Elige un rol válido.';

            return null;
        }
        if ($row->base === 'account_admin' && app(CompanyContext::class)->roleCode !== 'account_admin') {
            $errors['role_code'][] = 'Solo un administrador de cuenta puede dar ese rol.';

            return null;
        }

        return $row;
    }

    /** @return array{0: list<string>, 1: list<string>} sedes y áreas del alcance (vacío = toda la empresa) */
    private function scopeInput(Request $request, array &$errors): array
    {
        $ids = fn (string $k) => array_values(array_unique(array_filter(array_map('strval', (array) ($request->input($k) ?? [])))));
        $sites = $ids('site_ids');
        $areas = $ids('area_ids');
        foreach (['site_ids' => [$sites, 'sites', 'Una de las sedes no existe.'], 'area_ids' => [$areas, 'areas', 'Una de las áreas no existe.']] as $field => [$list, $table, $msg]) {
            if ($list === []) {
                continue;
            }
            $bad = array_filter($list, fn ($id) => ! Str::isUuid($id));
            $found = $bad ? 0 : (int) DB::selectOne("select count(*) as n from {$table} where deleted_at is null and id = any(?::uuid[])", ['{'.implode(',', $list).'}'])->n;
            if ($found !== count($list)) {
                $errors[$field][] = $msg;
            }
        }

        return [$sites, $areas];
    }

    /** Vigencia opcional (obligatoria para el auditor externo, RN-USR-07): fin del día indicado, hora de Lima. */
    private function expiresInput(Request $request, string $role, array &$errors): ?string
    {
        $v = trim((string) $request->input('expires_on'));
        if ($v === '') {
            if ($role === 'external_auditor') {
                $errors['expires_on'][] = 'El auditor externo necesita una fecha de fin del acceso.';
            }

            return null;
        }
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $d) || ! checkdate((int) $d[2], (int) $d[3], (int) $d[1])) {
            $errors['expires_on'][] = 'Usa una fecha válida.';

            return null;
        }
        if ($v < now('America/Lima')->toDateString()) {
            $errors['expires_on'][] = 'La fecha de fin no puede estar en el pasado.';

            return null;
        }

        return $v.' 23:59:59-05:00';
    }

    private function saveScopes(string $membership, array $sites, array $areas): void
    {
        $company = app(CompanyContext::class)->companyId;
        foreach ([['site', 'site_id', $sites], ['area', 'area_id', $areas]] as [$type, $col, $list]) {
            foreach ($list as $id) {
                DB::insert("insert into membership_scopes (company_id, membership_id, scope_type, {$col}, created_by) values (?, ?, ?, ?, ?)",
                    [$company, $membership, $type, $id, (string) auth()->id()]);
            }
        }
    }

    private function keepAdmin(string $except): void
    {
        $others = (int) DB::selectOne("select count(*) as n from memberships m join roles r on r.id = m.role_id
                                        where coalesce(r.base_role_code, r.code) = 'account_admin' and m.status = 'active' and m.deleted_at is null and m.id <> ?", [$except])->n;
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
