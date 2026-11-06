<?php

namespace App\Core\Auth;

use App\Core\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Permisos efectivos del usuario en la empresa activa (RN-USR-02):
 * los del rol de su membresía, recortados por los módulos habilitados
 * (enabled; en read_only solo las acciones de lectura).
 *
 * Pendiente: recorte por membership_scopes (sede/área) y allowed_modules.
 */
final class Permissions
{
    private const READ_ACTIONS = ['read', 'read_sensitive', 'export', 'verify', 'track'];

    /** Módulos con datos de salud: soporte nunca los ve (docs/05 §4.6, includes_medical siempre false). */
    public const MEDICAL_MODULES = ['EMO', 'VMD', 'DES', 'PSI'];

    /** @var array<string, list<string>> */
    private static array $cache = [];

    /**
     * @return list<string>
     */
    public static function effective(): array
    {
        $membershipId = app(CompanyContext::class)->membershipId;

        // Sesión de soporte (docs/05 §4.6): solo lectura de los módulos que la empresa tiene, nunca datos médicos
        // ni permisos sensibles, sin importar los permisos del rol platform_support.
        $support = (bool) DB::selectOne('select is_support_session as s from memberships where id = ?', [$membershipId])?->s;
        if ($support) {
            return self::$cache[$membershipId] ??= array_map(fn ($row) => $row->code, DB::select(
                "select p.code
                   from permissions p
                   join feature_flags f on f.module_code = p.module_code and f.deleted_at is null
                   join memberships ms on ms.company_id = f.company_id and ms.id = ?
                  where p.deleted_at is null and p.action = 'read' and not p.is_sensitive
                    and p.module_code <> all(?::text[]) and f.mode in ('enabled', 'read_only')
                  order by p.code",
                [$membershipId, '{'.implode(',', self::MEDICAL_MODULES).'}']
            ));
        }

        return self::$cache[$membershipId] ??= array_map(
            fn ($row) => $row->code,
            DB::select(
                "select p.code
                   from memberships ms
                   join role_permissions rp on rp.role_id = ms.role_id
                   join permissions p on p.id = rp.permission_id and p.deleted_at is null
                   join feature_flags f on f.module_code = p.module_code and f.deleted_at is null
                  where ms.id = ?
                    and (f.mode = 'enabled' or (f.mode = 'read_only' and p.action = any(?::text[])))
                  order by p.code",
                [$membershipId, '{'.implode(',', self::READ_ACTIONS).'}']
            )
        );
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
