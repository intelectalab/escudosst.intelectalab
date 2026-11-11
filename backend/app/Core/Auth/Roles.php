<?php

namespace App\Core\Auth;

/**
 * Roles base y personalizados (M-USR RN-USR-09, docs/05).
 *
 * Un rol personalizado pertenece a una cuenta (roles.account_id), deriva de un rol base (roles.base_role_code) y su
 * código es «<rol base>:<nombre corto>» (p. ej. «hr:asistente-rrhh»). Las reglas de negocio comparan siempre el código
 * del rol BASE (CompanyContext->roleCode): un rol personalizado se comporta como su rol base, recortado a sus permisos.
 */
final class Roles
{
    /** Los 17 roles base del sistema (account_id NULL). */
    public const BASE = ['platform_owner', 'platform_support', 'account_admin', 'consultant', 'sst_manager', 'sst_supervisor', 'committee_member',
        'management', 'area_lead', 'occupational_doctor', 'health_staff', 'hr', 'trainer', 'external_learner', 'worker', 'contractor_admin', 'external_auditor'];

    /** Roles base de los que una empresa puede derivar roles personalizados (los de la plataforma no). */
    public const DERIVABLE = ['account_admin', 'consultant', 'sst_manager', 'sst_supervisor', 'committee_member', 'management', 'area_lead',
        'occupational_doctor', 'health_staff', 'hr', 'trainer', 'external_learner', 'worker', 'contractor_admin', 'external_auditor'];

    /** Módulos cuyos permisos sensibles son datos de salud (RN-USR-03): solo en roles derivados de un rol médico. */
    public const MEDICAL_MODULES = ['EMO', 'VMD', 'DES', 'PSI', 'VUL'];

    public const SEPARATOR = ':';

    /** Código del rol base de un código de rol (el mismo si ya es base). */
    public static function base(string $code): string
    {
        $base = explode(self::SEPARATOR, $code, 2)[0];

        return in_array($base, self::BASE, true) ? $base : $code;
    }

    public static function isCustom(string $code): bool
    {
        return str_contains($code, self::SEPARATOR) && self::base($code) !== $code;
    }

    /** Permiso médico sensible: is_sensitive o «read_sensitive» de un módulo de salud. */
    public static function isMedicalSensitive(object $permission): bool
    {
        return in_array(trim((string) $permission->module_code), self::MEDICAL_MODULES, true)
            && ((bool) $permission->is_sensitive || $permission->action === 'read_sensitive');
    }
}
