<?php

namespace App\Core\Support;

/**
 * RUC peruano: 11 dígitos, prefijo 10, 15, 17 o 20 (companies.ck_companies_1) y dígito verificador
 * (módulo 11 con pesos 5,4,3,2,7,6,5,4,3,2).
 */
final class Ruc
{
    private const WEIGHTS = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    public static function isValid(?string $ruc): bool
    {
        if ($ruc === null || ! preg_match('/^(10|15|17|20)\d{9}$/', $ruc)) {
            return false;
        }

        $sum = 0;
        foreach (self::WEIGHTS as $i => $w) {
            $sum += (int) $ruc[$i] * $w;
        }

        $check = 11 - ($sum % 11);
        $check = $check === 10 ? 0 : ($check === 11 ? 1 : $check);

        return $check === (int) $ruc[10];
    }
}
