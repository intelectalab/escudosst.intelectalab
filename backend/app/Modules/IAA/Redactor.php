<?php

namespace App\Modules\IAA;

use Illuminate\Support\Facades\DB;

/**
 * RN-IAA-03: antes de procesar un texto se quitan los datos personales y se reemplazan por marcadores: correos, DNI (8
 * dígitos), RUC de persona natural (10…), teléfonos y los nombres de los trabajadores de la empresa. Los datos de salud no
 * se reemplazan: la solicitud se rechaza antes (noHealth).
 */
final class Redactor
{
    /** @return array{0: string, 1: list<string>} texto con marcadores y tipos de dato encontrados */
    public static function redact(string $text): array
    {
        $found = [];
        $rules = [
            'CORREO' => '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u',
            'RUC' => '/\b10\d{9}\b/u',
            'TELÉFONO' => '/(?<!\d)(?:\+?51[\s\-]?)?9\d{2}[\s\-]?\d{3}[\s\-]?\d{3}(?!\d)/u',
            'DNI' => '/(?<!\d)\d{8}(?!\d)/u',
        ];
        foreach ($rules as $tag => $re) {
            $text = preg_replace_callback($re, function () use ($tag, &$found) {
                $found[] = $tag;

                return "[{$tag}]";
            }, $text) ?? $text;
        }
        // Nombres de trabajadores de la empresa (nombre completo y «nombre apellido»).
        $names = [];
        foreach (DB::select('select full_name, first_name, paternal_last_name from workers where deleted_at is null limit 5000') as $w) {
            foreach ([$w->full_name, trim($w->first_name.' '.$w->paternal_last_name)] as $n) {
                if (mb_strlen((string) $n) >= 6) {
                    $names[mb_strtolower((string) $n)] = true;
                }
            }
        }
        uksort($names, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach (array_keys($names) as $n) {
            $re = '/\b'.preg_quote($n, '/').'\b/iu';
            if (preg_match($re, $text)) {
                $text = preg_replace($re, '[PERSONA]', $text) ?? $text;
                $found[] = 'PERSONA';
            }
        }

        return [$text, array_values(array_unique($found))];
    }
}
