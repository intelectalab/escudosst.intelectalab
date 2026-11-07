<?php

namespace App\Core\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Variables de sesión de PostgreSQL que leen las políticas RLS
 * (app_user_id(), app_company_id(), app_account_id() en schema_main.sql).
 *
 * - local = true  → equivale a SET LOCAL: solo dura la transacción actual.
 * - local = false → dura la conexión; se usa solo para identificar al usuario
 *   del token antes de abrir la transacción del tenant.
 */
final class DbContext
{
    public static function setUser(?string $userId, bool $local = false): void
    {
        self::set('app.user_id', $userId, $local);
    }

    public static function setCompany(?string $companyId, bool $local = true): void
    {
        self::set('app.company_id', $companyId, $local);
    }

    public static function companyId(): ?string
    {
        $value = DB::selectOne("select nullif(current_setting('app.company_id', true), '') as v")->v;

        return $value ?: null;
    }

    private static function set(string $key, ?string $value, bool $local): void
    {
        DB::select('select set_config(?, ?, ?)', [$key, $value ?? '', $local]);
    }
}
