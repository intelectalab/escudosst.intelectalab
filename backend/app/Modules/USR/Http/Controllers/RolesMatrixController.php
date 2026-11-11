<?php

namespace App\Modules\USR\Http\Controllers;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\REC\Registry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Roles y permisos de la empresa (M-USR SCR-USR-04, docs/05) en solo lectura, y opciones de alcance para invitar.
 *
 *  GET /company/roles/matrix   roles base × permisos agrupados por módulo (permisos sensibles marcados)
 *  GET /company/scope-options  sedes y áreas para acotar el alcance de un acceso (membership_scopes)
 */
class RolesMatrixController extends Controller
{
    /** Roles que se ven en la matriz de una empresa (los de la plataforma no). */
    private const ROLES = ['account_admin', 'consultant', 'sst_manager', 'sst_supervisor', 'committee_member', 'management', 'area_lead',
        'occupational_doctor', 'health_staff', 'hr', 'trainer', 'external_learner', 'worker', 'contractor_admin', 'external_auditor'];

    private const ACTIONS = [
        'read' => 'Ver', 'create' => 'Crear', 'update' => 'Editar', 'delete' => 'Eliminar', 'approve' => 'Aprobar', 'sign' => 'Firmar',
        'export' => 'Exportar', 'close' => 'Cerrar', 'vote' => 'Votar', 'verify' => 'Verificar', 'generate' => 'Generar', 'share' => 'Compartir',
        'sync' => 'Sincronizar', 'read_sensitive' => 'Ver dato sensible', 'publish' => 'Publicar', 'revoke' => 'Revocar', 'import' => 'Importar',
        'respond' => 'Responder', 'track' => 'Seguir', 'assign' => 'Asignar', 'send' => 'Enviar', 'confirm' => 'Confirmar', 'manage' => 'Administrar',
    ];

    public function matrix(): JsonResponse
    {
        $this->can(['usr.role_permissions.read', 'usr.roles.read']);
        $roles = DB::select(
            'select id, code, name, description, is_medical, requires_mfa from roles where account_id is null and deleted_at is null and code = any(?::text[])',
            ['{'.implode(',', self::ROLES).'}']
        );
        usort($roles, fn ($a, $b) => array_search($a->code, self::ROLES, true) <=> array_search($b->code, self::ROLES, true));
        $codeById = array_column(array_map(fn ($r) => ['id' => $r->id, 'code' => $r->code], $roles), 'code', 'id');

        $grants = [];
        foreach (DB::select('select role_id, permission_id from role_permissions where account_id is null and deleted_at is null') as $g) {
            if (isset($codeById[$g->role_id])) {
                $grants[$g->permission_id][] = $codeById[$g->role_id];
            }
        }
        $tables = Registry::data()['resources'] ?? [];
        $modules = [];
        foreach (DB::select('select id, code, module_code, resource, action, is_sensitive from permissions where deleted_at is null order by module_code, resource, action') as $p) {
            $modules[$p->module_code][] = [
                'code' => $p->code,
                'resource' => $p->resource,
                'resource_label' => $tables[$p->resource]['tab'] ?? ucfirst(str_replace('_', ' ', $p->resource)),
                'action' => $p->action,
                'action_label' => self::ACTIONS[$p->action] ?? $p->action,
                'is_sensitive' => (bool) $p->is_sensitive,
                'roles' => array_values(array_unique($grants[$p->id] ?? [])),
            ];
        }
        $names = array_column(DB::select('select code, name, number from modules where deleted_at is null order by group_number, number'), null, 'code');
        $out = [];
        foreach ($names as $code => $m) {
            if (isset($modules[$code])) {
                $out[] = ['code' => $code, 'number' => $m->number, 'name' => $m->name, 'permissions' => $modules[$code]];
                unset($modules[$code]);
            }
        }
        foreach ($modules as $code => $perms) {
            $out[] = ['code' => $code, 'number' => '', 'name' => $code, 'permissions' => $perms];
        }

        return response()->json(['data' => [
            'roles' => array_map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'description' => $r->description,
                'is_medical' => (bool) $r->is_medical, 'requires_mfa' => (bool) $r->requires_mfa], $roles),
            'modules' => $out,
        ]]);
    }

    public function scopeOptions(): JsonResponse
    {
        $this->can(['usr.memberships.create', 'usr.memberships.update']);

        return response()->json(['data' => [
            'sites' => DB::select("select id, name from sites where deleted_at is null and coalesce(status, 'active') = 'active' order by name"),
            'areas' => DB::select("select a.id, a.name, a.site_id from areas a where a.deleted_at is null and coalesce(a.status, 'active') = 'active' order by a.name"),
        ]]);
    }

    /** @param list<string> $any */
    private function can(array $any): void
    {
        if (array_intersect($any, Permissions::effective()) === []) {
            throw ApiProblem::forbidden('Tu rol no puede ver esta información.');
        }
    }
}
