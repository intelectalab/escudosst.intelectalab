<?php

namespace App\Core\Support;

use App\Core\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Parámetros legales vigentes (legal_parameters): primero el de la empresa activa, si no el global.
 * Ningún plazo, factor o umbral legal se fija en el código: se lee de aquí con un valor de respaldo.
 */
final class LegalParams
{
    public static function number(string $key, float $default): float
    {
        $company = app()->bound(CompanyContext::class) ? app(CompanyContext::class)->companyId : null;
        $v = DB::selectOne(
            "select value from legal_parameters where key = ? and (company_id is null or company_id = ?) and valid_from <= current_date
                and (valid_to is null or valid_to >= current_date) and deleted_at is null order by company_id nulls last, valid_from desc limit 1",
            [$key, $company]
        );

        return is_numeric($v->value ?? null) ? (float) $v->value : $default;
    }
}
