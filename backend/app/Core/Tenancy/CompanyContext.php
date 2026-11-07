<?php

namespace App\Core\Tenancy;

/**
 * Empresa activa de la petición y la membresía del usuario en ella.
 * La registra ResolveCompany; se obtiene con app(CompanyContext::class).
 */
final class CompanyContext
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $membershipId,
        public readonly string $roleCode,
        public readonly string $companyName,
        public readonly string $ruc,
    ) {}
}
