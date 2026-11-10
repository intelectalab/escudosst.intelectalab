<?php

namespace App\Core\Support;

use App\Core\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Separación de funciones: varias aprobaciones las hace «otra persona» (Ley 29783: el empleador aprueba, el comité
 * revisa). En una empresa que solo tiene un usuario con ese permiso, el mensaje explica cómo resolverlo: invitar a
 * Gerencia o a un miembro del comité como usuario (puede ser solo para aprobar).
 */
final class Approvers
{
    /** ¿Hay otra persona activa en la empresa con este permiso? */
    public static function someoneElse(string $permission): bool
    {
        return (bool) DB::selectOne(
            "select 1 as ok from memberships m
               join role_permissions rp on rp.role_id = m.role_id and rp.deleted_at is null
               join permissions p on p.id = rp.permission_id and p.code = ?
              where m.company_id = ? and m.user_id <> ? and m.status = 'active' and m.deleted_at is null limit 1",
            [$permission, app(CompanyContext::class)->companyId, (string) auth()->id()]
        );
    }

    /** Texto para agregar al mensaje cuando nadie más puede aprobar. */
    public static function hint(string $permission): string
    {
        return self::someoneElse($permission) ? ''
            : ' Hoy nadie más en tu empresa tiene ese permiso: invita a Gerencia o a un miembro del comité en Configuración › Usuarios, roles y permisos (puede tener un usuario solo para aprobar).';
    }
}
