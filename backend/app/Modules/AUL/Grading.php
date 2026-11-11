<?php

namespace App\Modules\AUL;

use Illuminate\Support\Facades\DB;

/**
 * Escala de calificación del curso (M-AUL RN-AUL-11/12, SCR-AUL-10): la nota se calcula siempre en porcentaje y se
 * convierte a la escala del curso (numérica 0-20, 0-10, 0-100… o por letras con rangos `bands`).
 */
final class Grading
{
    /** Escala del curso (o null: porcentaje). */
    public static function scaleOf(?string $courseId): ?object
    {
        if (! $courseId) {
            return null;
        }

        return DB::selectOne('select g.* from courses c join grading_scales g on g.id = c.grading_scale_id where c.id = ? and g.deleted_at is null', [$courseId]);
    }

    /** Bandas de una escala por letras, de mayor a menor. */
    public static function bands(object $scale): array
    {
        $b = is_string($scale->bands ?? null) ? (json_decode($scale->bands, true) ?: []) : (array) ($scale->bands ?? []);
        usort($b, fn ($x, $y) => (float) ($y['from'] ?? 0) <=> (float) ($x['from'] ?? 0));

        return $b;
    }

    /** Porcentaje → etiqueta en la escala («14», «14,5», «A»). Sin escala: «85 %». */
    public static function label(float $pct, ?object $scale): string
    {
        if (! $scale) {
            return rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.').' %';
        }
        if ($scale->scale_type === 'letter') {
            foreach (self::bands($scale) as $b) {
                if ($pct >= (float) ($b['from'] ?? 0)) {
                    return (string) ($b['label'] ?? '');
                }
            }

            return (string) (end($b)['label'] ?? '');
        }
        $min = (float) $scale->min_value;
        $max = (float) $scale->max_value;
        $v = round($min + ($max - $min) * $pct / 100, (int) $scale->decimals);

        return rtrim(rtrim(number_format($v, (int) $scale->decimals, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * Porcentaje mínimo para aprobar: el de la evaluación; si no, en escala por letras el inicio del rango de
     * `passing_label`; si no, el del curso; por defecto 70.
     */
    public static function passingPct(?float $lessonPassing, ?float $coursePassing, ?object $scale): float
    {
        if ($lessonPassing !== null) {
            return $lessonPassing;
        }
        if ($scale && $scale->scale_type === 'letter' && $scale->passing_label) {
            foreach (self::bands($scale) as $b) {
                if (($b['label'] ?? null) === $scale->passing_label) {
                    return (float) $b['from'];
                }
            }
        }

        return $coursePassing ?? 70.0;
    }
}
