<?php

namespace App\Modules\REC;

use App\Core\Http\ApiProblem;

/**
 * Catálogo de registros de los módulos SST (resources/registry/registry.json).
 * Se genera con database/tools/build_registry.py a partir del modelo de datos:
 * qué tablas tiene cada módulo, sus campos, tipos, opciones y referencias.
 */
final class Registry
{
    private static ?array $data = null;

    public static function data(): array
    {
        return self::$data ??= json_decode(
            (string) file_get_contents(resource_path('registry/registry.json')), true, 512, JSON_THROW_ON_ERROR
        );
    }

    /** @return list<string> tablas del módulo, en el orden de su ficha */
    public static function tables(string $module): array
    {
        return self::data()['modules'][strtoupper($module)] ?? [];
    }

    public static function resource(string $module, string $table): array
    {
        $res = self::data()['resources'][$table] ?? null;
        if ($res === null || $res['module'] !== strtoupper($module)) {
            throw ApiProblem::notFound('Ese registro no existe en este módulo.');
        }

        return $res + ['table' => $table];
    }

    public static function has(string $table): bool
    {
        return isset(self::data()['resources'][$table]);
    }

    /** Columna que se muestra como nombre de un registro de la tabla (para listas y selectores). */
    public static function labelColumn(string $table): ?string
    {
        $d = self::data();

        return $d['resources'][$table]['label'] ?? $d['catalogs'][$table]['label'] ?? null;
    }

    public static function softDelete(string $table): bool
    {
        return (bool) (self::data()['resources'][$table]['soft_delete'] ?? ($table !== 'users'));
    }

    public static function field(array $res, string $name): ?array
    {
        foreach ($res['fields'] as $f) {
            if ($f['name'] === $name) {
                return $f;
            }
        }

        return null;
    }
}
