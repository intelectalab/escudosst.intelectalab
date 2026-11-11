<?php

namespace App\Modules\USR\Services;

use App\Core\Auth\Permissions;
use App\Core\Auth\Roles;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Modules\REC\Registry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Roles personalizados de la cuenta (M-USR SCR-USR-04, RN-USR-03, RN-USR-09, docs/05):
 *  - derivan de un rol base (base_role_code) y heredan su alcance (scope_level), si es médico y si pide doble factor;
 *  - sus permisos son un subconjunto de los del rol base;
 *  - los permisos médicos sensibles solo existen en roles derivados de un rol médico (occupational_doctor, health_staff);
 *  - los roles base no se editan ni se borran;
 *  - se borran (lógico) solo si nadie los usa en las empresas de la cuenta, o reasignando a esas personas a otro rol.
 *
 * Las filas de la cuenta se escriben como escudo_app con app.account_id fijado solo durante la operación (RLS de roles y
 * role_permissions). Cada cambio queda en audit_logs y limpia el caché de permisos efectivos.
 */
class CustomRolesService
{
    public const ACTIONS = [
        'read' => 'Ver', 'create' => 'Crear', 'update' => 'Editar', 'delete' => 'Eliminar', 'export' => 'Exportar', 'approve' => 'Aprobar', 'sign' => 'Firmar',
        'close' => 'Cerrar', 'vote' => 'Votar', 'verify' => 'Verificar', 'generate' => 'Generar', 'share' => 'Compartir',
        'sync' => 'Sincronizar', 'read_sensitive' => 'Ver dato sensible', 'publish' => 'Publicar', 'revoke' => 'Revocar', 'import' => 'Importar',
        'respond' => 'Responder', 'track' => 'Seguir', 'assign' => 'Asignar', 'send' => 'Enviar', 'confirm' => 'Confirmar', 'manage' => 'Administrar',
    ];

    /** Estados de membresía que todavía usan el rol (impiden borrarlo sin reasignar). */
    private const IN_USE = ['active', 'invited', 'suspended'];

    private const MEDICAL_BASES = 'Médico ocupacional o Personal de salud';

    /** Cuenta de la empresa activa. */
    public function accountId(): string
    {
        $id = DB::selectOne('select account_id from companies where id = ?', [app(CompanyContext::class)->companyId])?->account_id;

        return $id ?? throw ApiProblem::forbidden('La empresa no pertenece a una cuenta: no puede tener roles personalizados.');
    }

    /** Roles base, roles personalizados de la cuenta y catálogo de permisos por módulo (con el estado del módulo en el plan). */
    public function overview(): array
    {
        $account = $this->accountId();
        $roles = DB::select(
            'select r.id, r.code, r.name, r.description, coalesce(r.base_role_code, r.code) as base_role_code, r.scope_level, r.is_system,
                    r.is_medical, r.requires_mfa, r.account_id is not null as is_custom, r.created_at, r.updated_at
               from roles r
              where r.deleted_at is null and ((r.account_id is null and r.code = any(?::text[])) or r.account_id = ?)',
            ['{'.implode(',', Roles::DERIVABLE).'}', $account]
        );
        $ids = array_column($roles, 'id');
        $perms = [];
        foreach (DB::select('select rp.role_id, p.code from role_permissions rp join permissions p on p.id = rp.permission_id and p.deleted_at is null
                               where rp.role_id = any(?::uuid[]) and rp.deleted_at is null order by p.code', ['{'.implode(',', $ids).'}']) as $g) {
            $perms[$g->role_id][] = $g->code;
        }
        $members = [];
        foreach (DB::select('select role_id, count(*) as n from memberships where deleted_at is null and not is_support_session and status = any(?::text[]) group by role_id',
            ['{'.implode(',', self::IN_USE).'}']) as $m) {
            $members[$m->role_id] = (int) $m->n;
        }
        $baseName = [];
        foreach ($roles as $r) {
            if (! $r->is_custom) {
                $baseName[$r->code] = $r->name;
            }
        }
        $out = ['base' => [], 'custom' => []];
        foreach ($roles as $r) {
            $row = [
                'id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'description' => $r->description,
                'base_role_code' => $r->base_role_code, 'base_role_name' => $baseName[$r->base_role_code] ?? $r->base_role_code,
                'scope_level' => $r->scope_level, 'is_medical' => (bool) $r->is_medical, 'requires_mfa' => (bool) $r->requires_mfa,
                'is_custom' => (bool) $r->is_custom, 'members' => $members[$r->id] ?? 0, 'permissions' => $perms[$r->id] ?? [],
                'updated_at' => $r->updated_at,
            ];
            $out[$r->is_custom ? 'custom' : 'base'][] = $row;
        }
        usort($out['base'], fn ($a, $b) => array_search($a['code'], Roles::DERIVABLE, true) <=> array_search($b['code'], Roles::DERIVABLE, true));
        usort($out['custom'], fn ($a, $b) => strcmp(mb_strtolower($a['name']), mb_strtolower($b['name'])));

        return ['roles' => $out['base'], 'custom_roles' => $out['custom'], 'modules' => $this->catalog(), 'actions' => self::ACTIONS];
    }

    /** Permisos agrupados por módulo, con el estado del módulo en la empresa (feature_flags) y la marca médica. */
    private function catalog(): array
    {
        $tables = Registry::data()['resources'] ?? [];
        $flags = array_column(DB::select('select module_code, mode from feature_flags where deleted_at is null'), 'mode', 'module_code');
        $byModule = [];
        foreach (DB::select('select code, module_code, resource, action, is_sensitive from permissions where deleted_at is null order by module_code, resource, action') as $p) {
            $byModule[trim($p->module_code)][] = [
                'code' => $p->code,
                'resource' => $p->resource,
                'resource_label' => $tables[$p->resource]['tab'] ?? ucfirst(str_replace('_', ' ', $p->resource)),
                'action' => $p->action,
                'action_label' => self::ACTIONS[$p->action] ?? $p->action,
                'is_sensitive' => (bool) $p->is_sensitive || $p->action === 'read_sensitive',
                'is_medical' => Roles::isMedicalSensitive($p),
            ];
        }
        $out = [];
        foreach (DB::select('select trim(code) as code, number, name, plan_code from modules where deleted_at is null order by group_number, number') as $m) {
            if (! isset($byModule[$m->code])) {
                continue;
            }
            $mode = $flags[$m->code] ?? 'locked';
            $out[] = ['code' => $m->code, 'number' => $m->number, 'name' => $m->name, 'mode' => $mode,
                'available' => in_array($mode, ['enabled', 'read_only'], true), 'is_addon' => $m->plan_code === '+',
                'permissions' => $byModule[$m->code]];
            unset($byModule[$m->code]);
        }
        foreach ($byModule as $code => $perms) {
            $mode = $flags[$code] ?? 'locked';
            $out[] = ['code' => $code, 'number' => '', 'name' => $code, 'mode' => $mode, 'available' => in_array($mode, ['enabled', 'read_only'], true),
                'is_addon' => false, 'permissions' => $perms];
        }

        return $out;
    }

    /** Crea un rol personalizado a partir de un rol base (por defecto con todos los permisos del base). */
    public function create(array $input): array
    {
        $account = $this->accountId();
        $baseCode = (string) ($input['base_role_code'] ?? '');
        $errors = [];
        $base = in_array($baseCode, Roles::DERIVABLE, true)
            ? DB::selectOne('select id, code, name, scope_level, is_medical, requires_mfa, is_temporary from roles where account_id is null and code = ? and deleted_at is null', [$baseCode])
            : null;
        if (! $base) {
            $errors['base_role_code'][] = 'Elige el rol base del que parte el nuevo rol.';
        } elseif ($base->code === 'account_admin' && app(CompanyContext::class)->roleCode !== 'account_admin') {
            $errors['base_role_code'][] = 'Solo un administrador de cuenta puede crear roles a partir de «Administrador de cuenta».';
        }
        [$name, $description] = $this->nameInput($input, $account, null, $errors);
        $this->failIf($errors);

        $basePerms = $this->rolePermissions($base->id);
        $wanted = array_key_exists('permissions', $input) && is_array($input['permissions']) ? $input['permissions'] : array_keys($basePerms);
        $ids = $this->validatePermissions($wanted, $base, $basePerms);

        return $this->asAccount($account, function () use ($account, $base, $name, $description, $ids) {
            $code = $this->uniqueCode($account, $base->code, $name);
            $actor = (string) auth()->id();
            $id = DB::selectOne(
                'insert into roles (account_id, code, name, description, base_role_code, scope_level, is_system, is_medical, requires_mfa, is_temporary, created_by, updated_by)
                 values (?, ?, ?, ?, ?, ?, false, ?, ?, ?, ?, ?) returning id',
                [$account, $code, $name, $description, $base->code, $base->scope_level, $base->is_medical ? 'true' : 'false', $base->requires_mfa ? 'true' : 'false',
                    $base->is_temporary ? 'true' : 'false', $actor, $actor]
            )->id;
            $this->insertPermissions($account, $id, array_values($ids));
            $this->audit('role_created', $id, ['name' => $name, 'code' => $code, 'base_role' => $base->code, 'scope_level' => $base->scope_level,
                'permissions' => count($ids), 'removed_from_base' => count($this->rolePermissions($base->id)) - count($ids)]);
            Permissions::flush();

            return ['id' => $id, 'code' => $code];
        });
    }

    /** Renombra o cambia los permisos de un rol personalizado (nunca de un rol base). */
    public function update(string $roleId, array $input): array
    {
        $account = $this->accountId();
        $role = $this->customRole($roleId, $account, 'editar');
        $base = DB::selectOne('select id, code, name, scope_level, is_medical from roles where account_id is null and code = ?', [$role->base_role_code]);
        $errors = [];
        $changes = [];
        $name = $role->name;
        $description = $role->description;
        if (array_key_exists('name', $input) || array_key_exists('description', $input)) {
            [$name, $description] = $this->nameInput($input + ['name' => $role->name, 'description' => $role->description], $account, $role->id, $errors);
        }
        $this->failIf($errors);
        $ids = null;
        if (array_key_exists('permissions', $input)) {
            if (! is_array($input['permissions'])) {
                $this->failIf(['permissions' => ['Envía la lista de permisos del rol.']]);
            }
            $ids = $this->validatePermissions($input['permissions'], $base, $this->rolePermissions($base->id));
        }

        return $this->asAccount($account, function () use ($role, $account, $name, $description, $ids, $changes) {
            $actor = (string) auth()->id();
            if ($name !== $role->name) {
                $changes['name'] = ['from' => $role->name, 'to' => $name];
            }
            if ($description !== $role->description) {
                $changes['description'] = ['from' => $role->description, 'to' => $description];
            }
            if ($changes) {
                DB::update('update roles set name = ?, description = ?, updated_by = ? where id = ?', [$name, $description, $actor, $role->id]);
            }
            if ($ids !== null) {
                $current = $this->rolePermissions($role->id);
                $added = array_values(array_diff(array_keys($ids), array_keys($current)));
                $removed = array_values(array_diff(array_keys($current), array_keys($ids)));
                if ($removed) {
                    DB::delete('delete from role_permissions where role_id = ? and permission_id = any(?::uuid[])',
                        [$role->id, '{'.implode(',', array_map(fn ($c) => $current[$c], $removed)).'}']);
                }
                if ($added) {
                    $this->insertPermissions($account, $role->id, array_map(fn ($c) => $ids[$c], $added));
                }
                if ($added || $removed) {
                    $changes['permissions'] = ['added' => $added, 'removed' => $removed];
                    DB::update('update roles set updated_by = ?, updated_at = now() where id = ?', [$actor, $role->id]);
                }
            }
            if ($changes) {
                $this->audit('role_updated', $role->id, $changes + ['code' => $role->code]);
                Permissions::flush();
            }

            return ['id' => $role->id, 'code' => $role->code, 'changed' => (bool) $changes];
        });
    }

    /**
     * Borra (lógico) un rol personalizado. Si alguien lo usa en alguna empresa de la cuenta se rechaza, salvo que se
     * indique a qué rol pasar a esas personas (reassign_to: código de un rol base o personalizado de la cuenta).
     */
    public function delete(string $roleId, ?string $reassignTo): array
    {
        $account = $this->accountId();
        $role = $this->customRole($roleId, $account, 'eliminar');

        return $this->asAccount($account, function () use ($role, $account, $reassignTo) {
            $usage = $this->usage($account, $role->id);
            $total = array_sum(array_column($usage, 'count'));
            $target = null;
            if ($total > 0) {
                if ($reassignTo === null || $reassignTo === '') {
                    throw new ApiProblem(409, 'conflict', 'Rol en uso',
                        "{$total} persona(s) tienen el rol «{$role->name}». Pásalas a otro rol antes de eliminarlo.",
                        ['members' => $total, 'companies' => array_values($usage)]);
                }
                $target = DB::selectOne('select id, code, name, coalesce(base_role_code, code) as base from roles
                                          where code = ? and deleted_at is null and (account_id is null or account_id = ?) order by account_id nulls first limit 1',
                    [$reassignTo, $account]);
                if (! $target || $target->id === $role->id || ! in_array($target->base, Roles::DERIVABLE, true)) {
                    $this->failIf(['reassign_to' => ['Elige el rol al que pasarán esas personas.']]);
                }
                if ($target->base === 'account_admin' && app(CompanyContext::class)->roleCode !== 'account_admin') {
                    $this->failIf(['reassign_to' => ['Solo un administrador de cuenta puede dar ese rol.']]);
                }
                if ($role->base_role_code === 'account_admin' && $target->base !== 'account_admin') {
                    foreach ($usage as $u) {
                        $others = $this->inCompany($u['company_id'], fn () => (int) DB::selectOne(
                            "select count(*) as n from memberships m join roles r on r.id = m.role_id
                              where coalesce(r.base_role_code, r.code) = 'account_admin' and m.status = 'active' and m.deleted_at is null and m.role_id <> ?", [$role->id])->n);
                        if ($others === 0) {
                            throw new ApiProblem(409, 'conflict', 'Último administrador', "«{$u['company_name']}» debe conservar al menos un administrador de cuenta activo.");
                        }
                    }
                }
            }
            $actor = (string) auth()->id();
            $current = app(CompanyContext::class)->companyId;
            foreach ($usage as $u) {
                $this->inCompany($u['company_id'], function () use ($role, $target, $actor, $u, $current) {
                    $moved = DB::update('update memberships set role_id = ?, updated_by = ? where role_id = ? and deleted_at is null',
                        [$target->id, $actor, $role->id]);
                    if ($u['company_id'] !== $current) {
                        $this->audit('role_reassigned', $role->id, ['role' => $role->code, 'to' => $target->code, 'members' => $moved], $u['company_id']);
                    }
                });
            }
            DB::update('update roles set deleted_at = now(), updated_by = ? where id = ?', [$actor, $role->id]);
            $this->audit('role_deleted', $role->id, array_filter(['name' => $role->name, 'code' => $role->code, 'base_role' => $role->base_role_code,
                'reassigned_to' => $target?->code, 'members' => $total ?: null]));
            Permissions::flush();

            return ['id' => $role->id, 'reassigned' => $total];
        });
    }

    // ---------------------------------------------------------------- Validaciones

    /**
     * RN-USR-03 y RN-USR-09: los permisos existen, los médicos sensibles solo en roles de base médica (o ya incluidos en un
     * rol base de alcance propio, como el trabajador con sus propios resultados) y ninguno excede el rol base.
     *
     * @param  array<string, string>  $basePerms  código => id
     * @return array<string, string> código => id de los permisos pedidos
     */
    private function validatePermissions(array $wanted, object $base, array $basePerms): array
    {
        $codes = array_values(array_unique(array_filter(array_map(fn ($c) => is_string($c) ? trim($c) : '', $wanted))));
        if ($codes === []) {
            $this->failIf(['permissions' => ['El rol necesita al menos un permiso.']]);
        }
        $rows = DB::select('select id, code, module_code, action, is_sensitive from permissions where deleted_at is null and code = any(?::text[])', ['{'.implode(',', $codes).'}']);
        $found = array_column($rows, null, 'code');
        $unknown = array_values(array_diff($codes, array_keys($found)));
        if ($unknown) {
            $this->failIf(['permissions' => ['Hay permisos que no existen: '.implode(', ', array_slice($unknown, 0, 5)).'.']]);
        }
        $medical = [];
        foreach ($rows as $p) {
            if (Roles::isMedicalSensitive($p) && ! $base->is_medical && ! ($base->scope_level === 'own' && isset($basePerms[$p->code]))) {
                $medical[] = $p->code;
            }
        }
        if ($medical) {
            throw new ApiProblem(422, 'medical_permission_not_allowed', 'Permiso médico no permitido',
                'Los permisos con datos médicos sensibles ('.implode(', ', array_slice($medical, 0, 5)).') solo pueden estar en un rol derivado de un rol médico ('.self::MEDICAL_BASES.'). «'.$base->name.'» no es un rol médico.',
                ['errors' => ['permissions' => ['Quita los permisos médicos sensibles: '.implode(', ', array_slice($medical, 0, 5)).'.']], 'medical_permissions' => $medical]);
        }
        $extra = array_values(array_diff($codes, array_keys($basePerms)));
        if ($extra) {
            throw new ApiProblem(422, 'exceeds_base_role', 'Excede el rol base',
                'Un rol personalizado no puede tener permisos que su rol base («'.$base->name.'») no tiene: '.implode(', ', array_slice($extra, 0, 5)).(count($extra) > 5 ? '…' : '').'.',
                ['errors' => ['permissions' => ['Solo puedes elegir permisos que tiene «'.$base->name.'».']], 'not_in_base' => $extra]);
        }

        return array_map(fn ($p) => $p->id, $found);
    }

    /** @return array{0: string, 1: ?string} */
    private function nameInput(array $input, string $account, ?string $exceptId, array &$errors): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($input['name'] ?? '')));
        $description = trim((string) ($input['description'] ?? '')) ?: null;
        if (mb_strlen($name) < 3 || mb_strlen($name) > 80) {
            $errors['name'][] = 'Escribe un nombre de 3 a 80 caracteres.';
        } elseif (DB::selectOne('select 1 as ok from roles where deleted_at is null and lower(name) = lower(?) and (account_id = ? or account_id is null) and id is distinct from ?::uuid',
            [$name, $account, $exceptId])) {
            $errors['name'][] = 'Ya hay un rol con ese nombre.';
        }
        if ($description !== null && mb_strlen($description) > 500) {
            $errors['description'][] = 'La descripción admite hasta 500 caracteres.';
        }

        return [$name, $description];
    }

    private function customRole(string $roleId, string $account, string $verb): object
    {
        $role = Str::isUuid($roleId)
            ? DB::selectOne('select id, account_id, code, name, description, coalesce(base_role_code, code) as base_role_code, is_system from roles where id = ? and deleted_at is null', [$roleId])
            : null;
        if (! $role || ($role->account_id !== null && $role->account_id !== $account)) {
            throw ApiProblem::notFound('El rol no existe.');
        }
        if ($role->account_id === null || $role->is_system) {
            throw new ApiProblem(409, 'base_role_locked', 'Rol base', "Los roles base no se pueden {$verb}. Crea un rol personalizado a partir de este.");
        }

        return $role;
    }

    private function failIf(array $errors): void
    {
        if ($errors) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', reset($errors)[0], ['errors' => $errors]);
        }
    }

    // ---------------------------------------------------------------- Datos

    /** @return array<string, string> código => id de permiso */
    private function rolePermissions(string $roleId): array
    {
        return array_column(DB::select('select p.code, p.id from role_permissions rp join permissions p on p.id = rp.permission_id and p.deleted_at is null
                                          where rp.role_id = ? and rp.deleted_at is null', [$roleId]), 'id', 'code');
    }

    private function insertPermissions(string $account, string $roleId, array $permissionIds): void
    {
        if ($permissionIds === []) {
            return;
        }
        DB::insert('insert into role_permissions (account_id, role_id, permission_id, created_by)
                    select ?, ?, p, ? from unnest(?::uuid[]) as p on conflict (role_id, permission_id) do nothing',
            [$account, $roleId, (string) auth()->id(), '{'.implode(',', $permissionIds).'}']);
    }

    /** Código estable «<base>:<nombre-corto>» único en la cuenta (incluidos los borrados). */
    private function uniqueCode(string $account, string $base, string $name): string
    {
        $slug = Str::limit(Str::slug($name), 40, '') ?: 'rol';
        $code = $base.Roles::SEPARATOR.$slug;
        for ($i = 2; DB::selectOne('select 1 as ok from roles where account_id = ? and code = ?', [$account, $code]); $i++) {
            $code = $base.Roles::SEPARATOR.$slug.'-'.$i;
        }

        return $code;
    }

    /** Personas que usan el rol en cada empresa de la cuenta. @return list<array{company_id: string, company_name: string, count: int}> */
    private function usage(string $account, string $roleId): array
    {
        $out = [];
        foreach (DB::select('select id, coalesce(trade_name, legal_name) as name from companies where account_id = ? and deleted_at is null order by 2', [$account]) as $c) {
            $n = $this->inCompany($c->id, fn () => (int) DB::selectOne('select count(*) as n from memberships where role_id = ? and deleted_at is null and status = any(?::text[])',
                [$roleId, '{'.implode(',', self::IN_USE).'}'])->n);
            if ($n > 0) {
                $out[] = ['company_id' => $c->id, 'company_name' => $c->name, 'count' => $n];
            }
        }

        return $out;
    }

    /** Ejecuta con app.account_id fijado (RLS de los roles de la cuenta) y lo restaura al terminar. */
    private function asAccount(string $account, callable $fn): mixed
    {
        return DB::transaction(function () use ($account, $fn) {
            $previous = DB::selectOne("select current_setting('app.account_id', true) as v")->v ?? '';
            DB::select("select set_config('app.account_id', ?, true)", [$account]);
            try {
                return $fn();
            } finally {
                DB::select("select set_config('app.account_id', ?, true)", [$previous]);
            }
        });
    }

    /** Ejecuta con otra empresa de la cuenta como activa (RLS de memberships) y restaura la actual. */
    private function inCompany(string $companyId, callable $fn): mixed
    {
        $previous = DB::selectOne("select current_setting('app.company_id', true) as v")->v ?? '';
        DB::select("select set_config('app.company_id', ?, true)", [$companyId]);
        try {
            return $fn();
        } finally {
            DB::select("select set_config('app.company_id', ?, true)", [$previous]);
        }
    }

    private function audit(string $action, string $roleId, array $changes, ?string $companyId = null): void
    {
        $ctx = app(CompanyContext::class);
        DB::insert(
            "insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes, ip, user_agent)
             values (?, ?, ?, 'user', ?, 'USR', 'roles', ?, ?::jsonb, ?, ?)",
            [$companyId ?? $ctx->companyId, (string) auth()->id(), $ctx->roleCode, $action, $roleId, json_encode($changes, JSON_UNESCAPED_UNICODE),
                request()->ip(), mb_substr((string) request()->userAgent(), 0, 300) ?: null]
        );
    }
}
