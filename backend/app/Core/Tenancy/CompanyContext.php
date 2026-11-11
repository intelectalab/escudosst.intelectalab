<?php

namespace App\Core\Tenancy;

/**
 * Empresa activa de la petición y la membresía del usuario en ella.
 * La registra ResolveCompany; se obtiene con app(CompanyContext::class).
 * roleCode es el código del rol BASE (también para un rol personalizado derivado de él; App\Core\Auth\Roles).
 */
final class CompanyContext
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $membershipId,
        public readonly string $roleCode,
        public readonly string $companyName,
        public readonly string $ruc,
        /** Código del rol personalizado de la membresía (null si es un rol base); roleCode es siempre el rol base. */
        public readonly ?string $customRoleCode = null,
    ) {}
}
