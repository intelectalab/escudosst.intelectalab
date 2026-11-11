<?php

namespace App\Modules\USR\Http\Controllers;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\USR\Services\CustomRolesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Roles de la cuenta (M-USR SCR-USR-04, RN-USR-03, RN-USR-09):
 *
 *  GET    /company/roles/manage        roles base (solo lectura), personalizados de la cuenta y permisos por módulo
 *  POST   /company/roles               crear a partir de un rol base {base_role_code, name, description?, permissions?}
 *  PATCH  /company/roles/{role}        renombrar o cambiar permisos {name?, description?, permissions?}
 *  DELETE /company/roles/{role}        eliminar (lógico) {reassign_to?: código del rol al que pasan sus personas}
 */
class CustomRolesController extends Controller
{
    public function __construct(private CustomRolesService $roles) {}

    public function index(): JsonResponse
    {
        $mine = $this->can(['usr.roles.read', 'usr.role_permissions.read'], 'Tu rol no puede ver los roles.');

        return response()->json(['data' => $this->roles->overview() + ['can' => [
            'create' => in_array('usr.roles.create', $mine, true),
            'update' => (bool) array_intersect(['usr.roles.update', 'usr.role_permissions.update'], $mine),
            'delete' => in_array('usr.roles.delete', $mine, true),
        ]]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->can(['usr.roles.create'], 'Tu rol no crea roles.');

        return response()->json(['data' => $this->roles->create($request->only(['base_role_code', 'name', 'description', 'permissions']))], 201);
    }

    public function update(Request $request, string $role): JsonResponse
    {
        $this->can(['usr.roles.update', 'usr.role_permissions.update'], 'Tu rol no edita roles.');

        return response()->json(['data' => $this->roles->update($role, $request->only(['name', 'description', 'permissions']))]);
    }

    public function destroy(Request $request, string $role): JsonResponse
    {
        $this->can(['usr.roles.delete'], 'Tu rol no elimina roles.');

        return response()->json(['data' => $this->roles->delete($role, $request->input('reassign_to') !== null ? (string) $request->input('reassign_to') : null)]);
    }

    /** @param list<string> $any @return list<string> permisos efectivos */
    private function can(array $any, string $message): array
    {
        $mine = Permissions::effective();
        if (array_intersect($any, $mine) === []) {
            throw ApiProblem::forbidden($message);
        }

        return $mine;
    }
}
