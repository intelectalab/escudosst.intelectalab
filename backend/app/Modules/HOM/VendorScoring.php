<?php

namespace App\Modules\HOM;

use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use Illuminate\Support\Facades\DB;

/**
 * Puntaje y calificación de la homologación de proveedores (M-HOM), el mismo para las tres vías: «Calificar» de SST
 * (PUT /hom/evaluations/{id}/scores), la edición de la evaluación y la autoevaluación del portal del contratista.
 *
 *  - Cada criterio se califica de 0 a MAX (0 = no cumple, MAX = cumple totalmente).
 *  - RN-HOM-01: total = Σ (puntaje / MAX) × peso; los pesos de la plantilla suman 100, así que el total va de 0 a 100.
 *  - RN-HOM-02: aprueba si total ≥ passing_score de la plantilla (sin mínimo en la plantilla: parámetro vendor_passing_score).
 *  - Calificación A/B/C (§5): A = aprobado (total ≥ vendor_rating_a_min_score), B = aprobado con condiciones (aprueba pero
 *    no llega a A), C = no aprobado. El umbral de A es un parámetro «a confirmar»; si no supera el mínimo, todo aprobado es A.
 *  - Forma de «scores»: {criterio: {score, comment?, evidence_file_id?, by}}.
 */
final class VendorScoring
{
    public const MAX = 5;

    /** Valor de rating por letra (la base guarda el código; la interfaz muestra «A · Aprobado», etc.). */
    public const A = 'approved';

    public const B = 'conditionally_approved';

    public const C = 'rejected';

    /** Criterios y mínimo de la plantilla. @return array{criteria: list<array{name: string, weight: float}>, passing: float} */
    public static function template(?string $templateId, ?\Illuminate\Database\ConnectionInterface $db = null): array
    {
        $t = $templateId ? ($db ?? DB::connection())->selectOne('select criteria, passing_score from vendor_evaluation_templates where id = ?', [$templateId]) : null;
        $criteria = [];
        foreach (json_decode((string) ($t->criteria ?? '[]'), true) ?: [] as $c) {
            if (isset($c['name'])) {
                $criteria[] = ['name' => (string) $c['name'], 'weight' => (float) ($c['weight'] ?? 0)];
            }
        }

        return ['criteria' => $criteria,
            'passing' => $t?->passing_score !== null && $t !== null ? (float) $t->passing_score : LegalParams::number('vendor_passing_score', 70)];
    }

    /**
     * Valida y normaliza los puntajes recibidos (número o {score, comment, evidence_file_id}) contra los criterios: todos
     * calificados y en el rango 0..MAX. Conserva el comentario y la evidencia anteriores del criterio si no se envían.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function normalize(array $input, array $criteria, array $previous, string $by): array
    {
        if ($criteria === []) {
            throw new ApiProblem(409, 'conflict', 'Plantilla sin criterios', 'La plantilla no tiene criterios: agrégalos primero.');
        }
        $out = [];
        $missing = [];
        $errors = [];
        foreach ($criteria as $c) {
            $name = $c['name'];
            $v = $input[$name] ?? null;
            $entry = is_array($v) ? $v : ['score' => $v];
            $sc = $entry['score'] ?? null;
            if ($sc === null || $sc === '') {
                $missing[] = $name;

                continue;
            }
            if (! is_numeric($sc) || (float) $sc < 0 || (float) $sc > self::MAX) {
                $errors[] = "El puntaje de «{$name}» debe estar entre 0 y ".self::MAX.'.';

                continue;
            }
            $prev = is_array($previous[$name] ?? null) ? $previous[$name] : [];
            $out[$name] = array_filter([
                'score' => (float) $sc,
                'comment' => array_key_exists('comment', $entry) ? ($entry['comment'] === null ? null : mb_substr(trim((string) $entry['comment']), 0, 1000)) : ($prev['comment'] ?? null),
                'evidence_file_id' => array_key_exists('evidence_file_id', $entry) ? $entry['evidence_file_id'] : ($prev['evidence_file_id'] ?? null),
                'by' => $by,
            ], fn ($x) => $x !== null && $x !== '');
        }
        if ($errors !== []) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los puntajes', $errors[0], ['errors' => ['scores' => $errors]]);
        }
        if ($missing !== []) {
            $msg = 'Califica todos los criterios: '.implode(', ', $missing).'.';
            throw new ApiProblem(422, 'validation_failed', 'Faltan puntajes', $msg, ['errors' => ['scores' => [$msg]]]);
        }

        return $out;
    }

    /** RN-HOM-01: total ponderado de 0 a 100. */
    public static function total(array $scores, array $criteria): float
    {
        $total = 0.0;
        foreach ($criteria as $c) {
            $v = $scores[$c['name']] ?? null;
            $sc = is_array($v) ? ($v['score'] ?? 0) : ($v ?? 0);
            $total += ((float) $sc / self::MAX) * (float) $c['weight'];
        }

        return round($total, 2);
    }

    /** RN-HOM-02 y §5: A, B o C según el mínimo de la plantilla y el umbral de A. */
    public static function rating(float $total, float $passing): string
    {
        if ($total < $passing) {
            return self::C;
        }

        return $total >= LegalParams::number('vendor_rating_a_min_score', 85) ? self::A : self::B;
    }

    /** @return array{scores: array, total_score: float, rating: string, passing_score: float} */
    public static function score(?string $templateId, array $input, array $previous, string $by): array
    {
        $t = self::template($templateId);
        $scores = self::normalize($input, $t['criteria'], $previous, $by);
        $total = self::total($scores, $t['criteria']);

        return ['scores' => $scores, 'total_score' => $total, 'rating' => self::rating($total, $t['passing']), 'passing_score' => $t['passing']];
    }
}
