<?php

namespace App\Core\Support;

use App\Core\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Parámetros legales vigentes (legal_parameters): primero el de la empresa activa, si no el global.
 * Ningún plazo, factor o umbral legal se fija en el código: se lee de aquí con un valor de respaldo.
 */
final class LegalParams
{
    /** $asOf: fecha (Y-m-d) a la que debe estar vigente el parámetro; por defecto, hoy. */
    public static function number(string $key, float $default, ?string $asOf = null): float
    {
        $company = app()->bound(CompanyContext::class) ? app(CompanyContext::class)->companyId : null;
        $on = $asOf ?: date('Y-m-d');
        $v = DB::selectOne(
            'select value from legal_parameters where key = ? and (company_id is null or company_id = ?) and valid_from <= ?::date
                and (valid_to is null or valid_to >= ?::date) and deleted_at is null order by company_id nulls last, valid_from desc limit 1',
            [$key, $company, $on, $on]
        );

        return is_numeric($v->value ?? null) ? (float) $v->value : $default;
    }

    /** Parámetro sí/no ('true'/'false', '1'/'0'); sin parámetro vigente, el valor de respaldo. */
    public static function flag(string $key, bool $default): bool
    {
        $company = app()->bound(CompanyContext::class) ? app(CompanyContext::class)->companyId : null;
        $v = DB::selectOne(
            'select value from legal_parameters where key = ? and (company_id is null or company_id = ?) and valid_from <= current_date
                and (valid_to is null or valid_to >= current_date) and deleted_at is null order by company_id nulls last, valid_from desc limit 1',
            [$key, $company]
        );
        $b = filter_var($v->value ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $b ?? $default;
    }

    /** Parámetro con una lista (JSON); sin parámetro vigente, la lista de respaldo. @return list<string> */
    public static function list(string $key, array $default = []): array
    {
        $company = app()->bound(CompanyContext::class) ? app(CompanyContext::class)->companyId : null;
        $v = DB::selectOne(
            'select value from legal_parameters where key = ? and (company_id is null or company_id = ?) and valid_from <= current_date
                and (valid_to is null or valid_to >= current_date) and deleted_at is null order by company_id nulls last, valid_from desc limit 1',
            [$key, $company]
        );
        $d = $v ? json_decode((string) $v->value, true) : null;

        return is_array($d) ? array_values(array_map('strval', $d)) : $default;
    }

    /** Parámetro de texto; sin parámetro vigente, el valor de respaldo. */
    public static function text(string $key, string $default): string
    {
        $company = app()->bound(CompanyContext::class) ? app(CompanyContext::class)->companyId : null;
        $v = DB::selectOne(
            'select value from legal_parameters where key = ? and (company_id is null or company_id = ?) and valid_from <= current_date
                and (valid_to is null or valid_to >= current_date) and deleted_at is null order by company_id nulls last, valid_from desc limit 1',
            [$key, $company]
        );

        return $v && trim((string) $v->value) !== '' ? trim((string) $v->value) : $default;
    }

    /**
     * Valor vigente de un parámetro para una empresa dada sin sesión (páginas públicas por empresa): el propio de la empresa o el
     * global. Abre su propia transacción con el contexto de esa empresa (RLS); null si no hay parámetro vigente.
     */
    public static function forCompany(string $companyId, string $key): ?string
    {
        return DB::transaction(function () use ($companyId, $key) {
            // Dentro de otra transacción (pruebas), SET LOCAL duraría hasta su fin: se restaura el valor previo.
            $previous = DB::selectOne("select current_setting('app.company_id', true) as v")->v ?? '';
            DB::select("select set_config('app.company_id', ?, true)", [$companyId]);
            try {
                $v = DB::selectOne(
                    'select value from legal_parameters where key = ? and (company_id is null or company_id = ?) and valid_from <= current_date
                        and (valid_to is null or valid_to >= current_date) and deleted_at is null order by company_id nulls last, valid_from desc limit 1',
                    [$key, $companyId]
                );
            } finally {
                DB::select("select set_config('app.company_id', ?, true)", [$previous]);
            }

            return $v?->value;
        });
    }

    /** Fija el valor propio de la empresa activa desde hoy (el anterior queda con vigencia hasta ayer). */
    public static function setForCompany(string $key, string $value): void
    {
        $company = app(CompanyContext::class)->companyId;
        $g = DB::selectOne('select value_type, unit, description, legal_reference from legal_parameters where key = ? and company_id is null and deleted_at is null limit 1', [$key]);
        DB::update('update legal_parameters set valid_to = current_date - 1 where key = ? and company_id = ? and deleted_at is null and valid_to is null and valid_from < current_date', [$key, $company]);
        // Un cambio el mismo día corrige el valor de hoy (la clave única es empresa + parámetro + fecha de inicio).
        if (DB::update('update legal_parameters set value = ?, deleted_at = null, valid_to = null, updated_at = now() where key = ? and company_id = ? and valid_from = current_date', [$value, $key, $company]) > 0) {
            return;
        }
        DB::insert('insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm, created_by)
                    values (?, ?, ?, ?, ?, ?, ?, ?, current_date, false, ?)',
            [$company, $key, $value, $g->value_type ?? 'text', $g->unit ?? null, $g->description ?? $key, $g->legal_reference ?? null, 'Empresa', auth()->id()]);
    }
}
