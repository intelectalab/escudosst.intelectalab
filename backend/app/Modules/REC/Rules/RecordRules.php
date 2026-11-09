<?php

namespace App\Modules\REC\Rules;

use App\Core\Http\ApiProblem;

/**
 * Reglas de negocio propias de una tabla, encima de la validación común del motor.
 * prepare() puede ajustar $values y agregar columnas calculadas en $extra (col => [expresión SQL, valores]).
 */
abstract class RecordRules
{
    /** @var array<string, class-string<RecordRules>> */
    private const MAP = [
        'sites' => TenRules::class,
        'areas' => TenRules::class,
        'positions' => TenRules::class,
        'work_shifts' => TenRules::class,
        'workers' => WorkerRules::class,
        'worker_assignments' => WorkerRules::class,
        'iperc_matrices' => IpercRules::class,
        'iperc_versions' => IpercRules::class,
        'iperc_rows' => IpercRules::class,
        'iperc_controls' => IpercRules::class,
    ];

    public static function for(string $table): ?self
    {
        $class = self::MAP[$table] ?? null;

        return $class ? app($class) : null;
    }

    public function prepare(string $op, array $res, array &$values, ?object $current, array &$extra): void {}

    public function after(string $op, array $res, string $id, array $values, ?object $current): void {}

    public function prepareDelete(array $res, object $current): void {}

    protected function fail(string $field, string $message): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $message, ['errors' => [$field => [$message]]]);
    }

    protected function conflict(string $message): never
    {
        throw new ApiProblem(409, 'conflict', 'No se puede hacer este cambio', $message);
    }

    /** Valor final del campo: el nuevo si se envía, si no el actual. */
    protected function value(array $values, ?object $current, string $field): mixed
    {
        return array_key_exists($field, $values) ? $values[$field] : ($current?->{$field} ?? null);
    }
}
