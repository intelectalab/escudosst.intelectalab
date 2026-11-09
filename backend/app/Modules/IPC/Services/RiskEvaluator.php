<?php

namespace App\Modules\IPC\Services;

use App\Core\Http\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * Evaluación del riesgo de una fila IPERC con los criterios configurados (M-IPC RN-IPC-01, 02 y 04).
 *
 * - Método «pxs_index» (R.M. 050-2013-TR): probabilidad = suma de los índices (personas expuestas,
 *   procedimientos, capacitación, exposición); puntaje = probabilidad × severidad.
 * - Método «pxs» (matriz 5×5 u otra): probabilidad y severidad se eligen de sus escalas.
 * - El nivel sale de las bandas (min–max del puntaje) y es significativo desde `significant_from_level`.
 * Ningún factor, escala o banda está fijo en el código: todo viene de risk_criteria.
 */
final class RiskEvaluator
{
    /** @var array<string, array> */
    private array $cache = [];

    public function criteria(string $id): array
    {
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }
        $c = DB::selectOne('select id, code, name, version_number, method, probability_scale, severity_scale, probability_index_factors,
                                   level_bands, significant_from_level
                              from risk_criteria where id = ? and deleted_at is null', [$id]);
        if (! $c) {
            throw ApiProblem::notFound('Los criterios de evaluación no existen.');
        }

        return $this->cache[$id] = [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'version_number' => (int) $c->version_number,
            'method' => $c->method,
            'probability_scale' => json_decode($c->probability_scale, true) ?? [],
            'severity_scale' => json_decode($c->severity_scale, true) ?? [],
            'factors' => json_decode((string) $c->probability_index_factors, true) ?? [],
            'bands' => json_decode($c->level_bands, true) ?? [],
            'significant_from' => $c->significant_from_level,
        ];
    }

    /**
     * @param  array<string, int>|null  $detail  índices por factor (método pxs_index)
     * @return array{probability: int, severity: int, score: int, level: string, level_label: string, significant: bool, detail: ?array}
     */
    public function evaluate(array $c, ?int $probability, ?array $detail, ?int $severity): array
    {
        if ($c['method'] === 'pxs_index') {
            $detail ??= [];
            $clean = [];
            $probability = 0;
            foreach ($c['factors'] as $factor) {
                $v = $detail[$factor['code']] ?? null;
                $allowed = array_column($factor['levels'], 'value');
                if ($v === null || ! in_array((int) $v, $allowed, true)) {
                    $this->fail('probability_detail', "Elige el índice de «{$factor['label']}».");
                }
                $clean[$factor['code']] = (int) $v;
                $probability += (int) $v;
            }
            $detail = $clean;
        } else {
            $detail = null;
            if ($probability === null || ! in_array($probability, array_column($c['probability_scale'], 'value'), true)) {
                $this->fail('probability_value', 'Elige la probabilidad de la escala.');
            }
        }
        if ($severity === null || ! in_array($severity, array_column($c['severity_scale'], 'value'), true)) {
            $this->fail('severity_value', 'Elige la severidad de la escala.');
        }
        $score = $probability * $severity;
        $band = $this->band($c, $score);

        return [
            'probability' => $probability,
            'severity' => $severity,
            'score' => $score,
            'level' => $band['code'],
            'level_label' => $band['label'],
            'significant' => $this->isSignificant($c, $band['code']),
            'detail' => $detail,
        ];
    }

    /** Riesgo residual: misma escala de severidad; probabilidad dentro del rango de la escala. */
    public function residual(array $c, int $probability, int $severity, int $initialScore): array
    {
        $values = array_column($c['probability_scale'], 'value');
        $ok = $c['method'] === 'pxs_index'
            ? $probability >= min($values) && $probability <= max($values)
            : in_array($probability, $values, true);
        if (! $ok) {
            $this->fail('residual_probability', $c['method'] === 'pxs_index'
                ? 'La probabilidad residual debe estar entre '.min($values).' y '.max($values).'.'
                : 'Elige la probabilidad residual de la escala.');
        }
        if (! in_array($severity, array_column($c['severity_scale'], 'value'), true)) {
            $this->fail('residual_severity', 'Elige la severidad residual de la escala.');
        }
        $score = $probability * $severity;
        if ($score > $initialScore) {
            $this->fail('residual_probability', "El riesgo residual ({$score}) no puede ser mayor que el inicial ({$initialScore}).");
        }

        return ['score' => $score, 'level' => $this->band($c, $score)['code']];
    }

    /** ¿Es el nivel más alto de las bandas (intolerable o equivalente)? */
    public function isTop(array $c, ?string $level): bool
    {
        $codes = array_column($c['bands'], 'code');

        return $level !== null && $level === end($codes);
    }

    public function isSignificant(array $c, string $level): bool
    {
        $codes = array_column($c['bands'], 'code');
        $from = array_search($c['significant_from'], $codes, true);

        return $from !== false && array_search($level, $codes, true) >= $from;
    }

    public function label(array $c, ?string $level): ?string
    {
        foreach ($c['bands'] as $b) {
            if ($b['code'] === $level) {
                return $b['label'];
            }
        }

        return $level;
    }

    private function band(array $c, int $score): array
    {
        foreach ($c['bands'] as $b) {
            if ($score >= $b['min'] && $score <= $b['max']) {
                return $b;
            }
        }
        $this->fail('severity_value', "El puntaje {$score} no cae en ninguna banda de los criterios «{$c['name']}». Revisa los criterios.");
    }

    private function fail(string $field, string $message): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $message, ['errors' => [$field => [$message]]]);
    }
}
