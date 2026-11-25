<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Auth\Scope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registros del sistema vinculados como evidencia (M-AUI RN-AUI-07, audit_checklist_items.linked_records): cualquier registro del
 * catálogo de registros que el rol puede leer, salvo los módulos con datos de salud. Cada vínculo guarda {module, table, id, label};
 * la etiqueta es la del registro al vincularlo (si luego se archiva, el vínculo conserva su nombre).
 */
final class LinkedRecords
{
    public const MAX = 30;

    /** Tablas que no se ofrecen como evidencia (el propio checklist y sus hallazgos, cuentas y configuración interna). */
    private const EXCLUDED = ['audit_checklist_items', 'api_clients', 'webhooks'];

    /** @return list<array{module: string, table: string, title: string, label: string}> tablas que el rol puede vincular */
    public static function types(): array
    {
        $perms = Permissions::effective();
        $out = [];
        foreach (Registry::data()['resources'] as $table => $res) {
            if (self::allowed($table, $res, $perms)) {
                $out[] = ['module' => $res['module'], 'table' => $table, 'title' => $res['title'] ?? $table, 'label' => $res['label'] ?? 'id'];
            }
        }
        usort($out, fn ($a, $b) => [$a['module'], $a['title']] <=> [$b['module'], $b['title']]);

        return $out;
    }

    /**
     * Valida y completa los vínculos enviados ([{table, id}] o [{module, table, id}]): la tabla se puede vincular, el registro existe en
     * la empresa (RLS) y en el alcance de la membresía; no se repite. Devuelve null si algo no cumple (con el motivo en $error).
     *
     * @return list<array{module: string, table: string, id: string, label: string}>|null
     */
    public static function normalize(mixed $input, ?string &$error = null): ?array
    {
        $list = is_string($input) ? json_decode($input, true) : $input;
        if ($list === null) {
            return [];
        }
        if (! is_array($list) || ! array_is_list($list)) {
            $error = 'Los registros vinculados deben ser una lista.';

            return null;
        }
        if (count($list) > self::MAX) {
            $error = 'Vincula hasta '.self::MAX.' registros por ítem.';

            return null;
        }
        $perms = Permissions::effective();
        $res = Registry::data()['resources'];
        $out = [];
        foreach ($list as $l) {
            $table = is_array($l) ? (string) ($l['table'] ?? '') : '';
            $id = is_array($l) ? (string) ($l['id'] ?? '') : '';
            if (! isset($res[$table]) || ! self::allowed($table, $res[$table], $perms) || ! Str::isUuid($id)) {
                $error = 'Uno de los registros vinculados no es un registro del sistema que puedas vincular.';

                return null;
            }
            if (isset($out["{$table}:{$id}"])) {
                continue;
            }
            $label = self::labelExpr($table, 'r');
            $row = DB::selectOne("select r.id, ({$label})::text as label from \"{$table}\" r where r.id = ?"
                .(Registry::softDelete($table) ? ' and r.deleted_at is null' : '').Scope::andTable($table, 'r'), [$id]);
            if (! $row) {
                $error = 'Uno de los registros vinculados no existe en tu empresa o no está en tu alcance.';

                return null;
            }
            $out["{$table}:{$id}"] = ['module' => $res[$table]['module'], 'table' => $table, 'id' => $id, 'label' => mb_substr((string) ($row->label ?? $id), 0, 200)];
        }

        return array_values($out);
    }

    /** Texto del registro: el de las listas del sistema (código y nombre cuando los tiene). */
    public static function labelExpr(string $table, string $alias): string
    {
        if ($sql = RecordsService::labelSql($table, $alias)) {
            return $sql;
        }
        $label = Registry::labelColumn($table) ?? 'id';
        $res = Registry::data()['resources'][$table] ?? null;
        $hasCode = $res && $label !== 'code' && Registry::field($res, 'code');

        return $hasCode ? "concat_ws(' · ', {$alias}.\"code\", {$alias}.\"{$label}\"::text)" : "{$alias}.\"{$label}\"::text";
    }

    /** Enlace a la ficha del registro en su módulo. */
    public static function link(array $l): string
    {
        return '/m/'.strtolower((string) $l['module']).'?t='.$l['table'].'&open='.$l['id'];
    }

    private static function allowed(string $table, array $res, array $perms): bool
    {
        return ! in_array($table, self::EXCLUDED, true) && ! in_array($res['module'], Permissions::MEDICAL_MODULES, true)
            && in_array(strtolower($res['module']).".{$table}.read", $perms, true) && Scope::moduleAllowed($res['module']);
    }
}
