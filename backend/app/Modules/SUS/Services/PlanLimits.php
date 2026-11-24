<?php

namespace App\Modules\SUS\Services;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Límites del plan de la empresa (docs/03 §5 y §8, docs/08 §7 y §16): trabajadores, sedes, usuarios de gestión,
 * almacenamiento y puntos de reporte QR. Las solicitudes IA tienen su propio cupo mensual (App\Modules\IAA\Usage) y el
 * número de empresas se controla por cuenta en AccountsService::addCompany.
 *
 * - El límite sale del plan de la suscripción vigente de la empresa activa (cada empresa tiene su propia suscripción;
 *   docs/03 §2 y RN-SUS-04). Límite nulo = sin límite.
 * - «Exceder un límite: no se bloquea lo existente. Se impide crear más y se invita a mejorar» (docs/03 §8): solo se
 *   revisa al crear o al reactivar; nada existente se borra ni se bloquea.
 * - Al llegar al límite responde 403 plan_limit_reached (docs/08 §7).
 *
 * Qué se cuenta (consultas con RLS de la empresa activa):
 *  - workers: trabajadores no eliminados en estado activo o de licencia (los cesados e inactivos no cuentan).
 *  - sites: sedes no eliminadas en estado activo.
 *  - management_users: accesos no eliminados, invitados o activos y no vencidos, con un rol de gestión (no cuentan
 *    trabajador, participante externo, contratista, auditor externo, consultor de la consultora ni accesos de soporte).
 *  - qr_points: puntos de reporte QR no eliminados y activos.
 *  - storage: suma del tamaño de los archivos disponibles y no eliminados de la empresa (base principal).
 */
final class PlanLimits
{
    public const KINDS = [
        'workers' => 'max_workers',
        'sites' => 'max_sites',
        'management_users' => 'max_management_users',
        'qr_points' => 'qr_report_points',
        'storage' => 'storage_gb',
    ];

    /** Roles base que no son «usuarios de gestión». */
    public const NON_MANAGEMENT_ROLES = ['worker', 'external_learner', 'contractor_admin', 'external_auditor', 'consultant', 'platform_owner', 'platform_support'];

    /** Estados de trabajador que ocupan un cupo. */
    public const WORKER_COUNTED = ['active', 'on_leave'];

    public const UPGRADE_URL = '/cuenta/suscripcion';

    private const LABELS = [
        'workers' => ['trabajador', 'trabajadores'],
        'sites' => ['sede', 'sedes'],
        'management_users' => ['usuario de gestión', 'usuarios de gestión'],
        'qr_points' => ['punto de reporte QR', 'puntos de reporte QR'],
    ];

    /** Límites del plan vigente de la empresa (null = sin límite). @return array<string, ?int> */
    public static function limits(?string $companyId = null): array
    {
        $companyId ??= app(CompanyContext::class)->companyId;
        $p = DB::selectOne('select '.implode(', ', array_map(fn ($c) => 'p.'.$c, self::KINDS)).' from subscriptions s join plans p on p.id = s.plan_id
                             where s.company_id = ? and s.deleted_at is null and s.status not in (\'cancelled\', \'trial_expired\') order by s.started_at desc limit 1', [$companyId]);
        $out = [];
        foreach (self::KINDS as $kind => $col) {
            $out[$kind] = $p === null || $p->{$col} === null ? null : (int) $p->{$col};
        }

        return $out;
    }

    /** Uso actual de un límite (en bytes para storage). */
    public static function used(string $kind): int
    {
        return (int) match ($kind) {
            'workers' => DB::selectOne("select count(*) as n from workers where deleted_at is null and status in ('active', 'on_leave')")->n,
            'sites' => DB::selectOne("select count(*) as n from sites where deleted_at is null and status = 'active'")->n,
            'management_users' => DB::selectOne(
                "select count(*) as n from memberships m join roles r on r.id = m.role_id
                  where m.company_id = ? and m.deleted_at is null and not m.is_support_session and m.status in ('invited', 'active')
                    and (m.expires_at is null or m.expires_at > now()) and coalesce(r.base_role_code, r.code) <> all(?::text[])",
                [app(CompanyContext::class)->companyId, '{'.implode(',', self::NON_MANAGEMENT_ROLES).'}'])->n,
            'qr_points' => DB::selectOne('select count(*) as n from report_points where deleted_at is null and is_active')->n,
            'storage' => DB::selectOne("select coalesce(sum(size_bytes), 0) as n from files where deleted_at is null and status = 'available'")->n,
        };
    }

    /** Uso y límite de todo lo que limita el plan (pantalla «Mi suscripción»). @return array<string, array{used: int, limit: ?int}> */
    public static function summary(): array
    {
        $limits = self::limits();
        $out = [];
        foreach (array_keys(self::KINDS) as $kind) {
            $out[$kind] = ['used' => self::used($kind), 'limit' => $limits[$kind]];
        }

        return $out;
    }

    /**
     * Impide crear (o reactivar) si con lo nuevo se supera el límite del plan. Para storage, $adding son bytes.
     *
     * @throws ApiProblem 403 plan_limit_reached
     */
    public static function assertCanCreate(string $kind, int $adding = 1): void
    {
        if (! isset(self::KINDS[$kind]) || $adding <= 0) {
            return;
        }
        $limit = self::limits()[$kind];
        if ($limit === null) {
            return;
        }
        $used = self::used($kind);
        $max = $kind === 'storage' ? $limit * 1024 * 1024 * 1024 : $limit;
        if ($used + $adding <= $max) {
            return;
        }
        if ($kind === 'storage') {
            $detail = "Llegaste al límite de almacenamiento de tu plan: {$limit} GB. Mejora tu plan para subir más archivos.";
        } else {
            [$one, $many] = self::LABELS[$kind];
            $detail = "Llegaste al límite de tu plan: {$limit} ".($limit === 1 ? $one : $many).'. Mejora tu plan para agregar más.';
        }

        throw new ApiProblem(403, 'plan_limit_reached', 'Límite del plan', $detail,
            ['kind' => $kind, 'limit' => $limit, 'used' => $kind === 'storage' ? round($used / 1073741824, 2) : $used]
                + ($kind === 'storage' ? ['unit' => 'GB'] : []) + ['upgrade_url' => self::UPGRADE_URL]);
    }

    /** Límite que ocupa una fila de un recurso del motor de registros (null si la tabla no tiene límite). */
    public static function kindForTable(string $table): ?string
    {
        return ['workers' => 'workers', 'sites' => 'sites', 'report_points' => 'qr_points'][$table] ?? null;
    }

    /** ¿La fila (con estos valores) ocupa un cupo del plan? */
    public static function occupies(string $table, array $row): bool
    {
        return match ($table) {
            'workers' => in_array($row['status'] ?? 'active', self::WORKER_COUNTED, true),
            'sites' => ($row['status'] ?? 'active') === 'active',
            'report_points' => filter_var($row['is_active'] ?? true, FILTER_VALIDATE_BOOL),
            default => false,
        };
    }

    public static function isManagementRole(string $baseRole): bool
    {
        return ! in_array($baseRole, self::NON_MANAGEMENT_ROLES, true);
    }
}
