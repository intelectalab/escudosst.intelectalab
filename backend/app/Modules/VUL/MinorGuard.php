<?php

namespace App\Modules\VUL;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Menores de edad (M-VUL RN-VUL-03, criterio 1): un menor no se asigna a un puesto cuyo IPERC tiene peligros de las
 * familias marcadas «prohibidas para menores». La lista es un parámetro legal (legal_parameters
 * «minor_forbidden_hazard_families», JSON con familias de hazard_catalog; global o propio de la empresa, a confirmar por
 * especialista según el Código de los Niños y Adolescentes) y la edad, «minor_age_years». Si el parámetro está vacío,
 * solo queda la advertencia.
 */
final class MinorGuard
{
    public static function isMinor(?string $birthOn): bool
    {
        if (! $birthOn) {
            return false;
        }
        $age = (int) self::param('minor_age_years', '18');

        return $birthOn > now('America/Lima')->subYears($age > 0 ? $age : 18)->toDateString();
    }

    /** @return list<string> familias de peligro prohibidas para menores. */
    public static function forbiddenFamilies(): array
    {
        $v = json_decode(self::param('minor_forbidden_hazard_families', '[]'), true);

        return is_array($v) ? array_values(array_filter($v, fn ($f) => is_string($f) && isset(VulController::FAMILIES[$f]))) : [];
    }

    /** Filas del IPERC del puesto con peligros prohibidos para menores. */
    public static function conflicts(?string $positionId): array
    {
        $families = self::forbiddenFamilies();

        return $positionId && $families ? app(VulController::class)->conflicts($positionId, $families) : [];
    }

    /** Bloquea (422) la asignación de un menor a un puesto con peligros prohibidos para menores. */
    public static function assertAllowed(?string $birthOn, ?string $positionId, string $field): void
    {
        if (! $positionId || ! self::isMinor($birthOn) || ! ($c = self::conflicts($positionId))) {
            return;
        }
        $labels = implode(', ', array_unique(array_column($c, 'family_label')));
        $msg = "El trabajador es menor de edad y el IPERC de ese puesto tiene peligros prohibidos para menores ({$labels}): elige otro puesto.";
        throw new ApiProblem(422, 'validation_failed', 'Puesto prohibido para menores', $msg, ['errors' => [$field => [$msg]], 'minor_conflicts' => $c]);
    }

    private static function param(string $key, string $default): string
    {
        $company = app()->bound(CompanyContext::class) ? app(CompanyContext::class)->companyId : null;
        $v = DB::selectOne(
            "select value from legal_parameters where key = ? and (company_id is null or company_id = ?) and valid_from <= current_date
                and (valid_to is null or valid_to >= current_date) and deleted_at is null order by company_id nulls last, valid_from desc limit 1",
            [$key, $company]
        );

        return $v->value ?? $default;
    }
}
