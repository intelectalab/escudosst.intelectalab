<?php

namespace App\Core\Support;

/**
 * Lista única de sectores económicos (companies.economic_sector, sector_packs.sector, formularios del sitio y altas de cuentas).
 * Los paquetes sectoriales (M-PSE) usan la misma lista: el de clínicas es «health» (Salud); los sectores sin paquete publicado
 * (servicios, educación, transporte, energía, otro) simplemente no tienen uno que recomendar.
 * Debe coincidir con el CHECK de las dos columnas en la base y con SECTORS de frontend/lib/accounts.ts.
 */
final class Sectors
{
    public const LABELS = [
        'construction' => 'Construcción', 'mining' => 'Minería', 'agroindustry' => 'Agroindustria', 'manufacturing' => 'Manufactura',
        'retail' => 'Comercio', 'services' => 'Servicios', 'office' => 'Oficinas', 'health' => 'Salud', 'education' => 'Educación',
        'transport' => 'Transporte', 'energy' => 'Energía', 'other' => 'Otro',
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::LABELS);
    }

    /** Para la regla de validación «in:…». */
    public static function csv(): string
    {
        return implode(',', self::codes());
    }

    public static function label(?string $code): ?string
    {
        return $code === null ? null : (self::LABELS[$code] ?? ucfirst($code));
    }
}
