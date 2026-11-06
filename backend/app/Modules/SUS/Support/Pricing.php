<?php

namespace App\Modules\SUS\Support;

use Illuminate\Support\Facades\DB;

/**
 * Precios por periodo. El precio de referencia de planes y complementos es MENSUAL;
 * el anual = 12 × mensual − descuento anual (parámetro sus.annual_discount_pct, 0 por defecto).
 */
final class Pricing
{
    public static function annualDiscountPct(?string $connection = null): float
    {
        $v = DB::connection($connection)->selectOne(
            "select value from legal_parameters
              where company_id is null and key = 'sus.annual_discount_pct' and deleted_at is null
                and valid_from <= current_date and (valid_to is null or valid_to >= current_date)
              order by valid_from desc limit 1"
        );

        return $v && is_numeric($v->value) ? max(0.0, min(100.0, (float) $v->value)) : 0.0;
    }

    /** Monto de UN periodo de cobro a partir del precio mensual. Null si el precio aún no está definido. */
    public static function forPeriod(?float $monthly, string $period, float $discountPct): ?float
    {
        if ($monthly === null) {
            return null;
        }

        return $period === 'annual' ? round($monthly * 12 * (1 - $discountPct / 100), 2) : round($monthly, 2);
    }
}
