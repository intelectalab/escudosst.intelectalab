<?php

namespace App\Core\Auth;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Modules\REC\Registry;
use Illuminate\Support\Facades\DB;

/**
 * Alcance de la membresía activa (docs/03 §3, docs/05 §2, M-USR RN-USR-02 y criterio 4, M-AUI RN-AUI-08).
 *
 * Módulos: memberships.allowed_modules y las filas membership_scopes «module» limitan los módulos que la membresía ve
 * (null = todos los de su rol). Siempre se conservan los módulos base para funcionar: USR (cuenta, perfil), NOT
 * (notificaciones) y TEN (estructura de la empresa para nombres y filtros), recortados igual por los permisos del rol.
 *
 * Sede/área: membership_scopes «site» y «area». Sin filas = toda la empresa.
 *  - Un área incluye sus subáreas (jerarquía de M-TEN).
 *  - Una sede da toda la sede, salvo que la membresía también tenga un área de esa sede: entonces el área acota la sede
 *    (docs/03: «jefe de área con alcance sede Lurín, área Producción» ve lo de Producción y lo de nivel sede de Lurín).
 *  - Un área da además los registros de nivel sede (sin área) de su sede.
 *  - Los registros sin sede ni área son de toda la empresa: se leen, pero quien tiene alcance no los crea ni modifica.
 *
 * No se aplica (ven toda la empresa) al administrador de cuenta, al trabajador y al administrador de contratista (tienen
 * su propio recorte: lo suyo / su contratista), al participante externo ni a la sesión de soporte (solo lectura).
 * Se aplica en la aplicación (RecordsService y los endpoints propios); la RLS de la base sigue siendo por empresa.
 */
final class Scope
{
    /** Módulos que se conservan aunque allowed_modules no los liste (docs/05: menú, cuenta y notificaciones). */
    public const BASE_MODULES = ['USR', 'NOT', 'TEN'];

    /** Roles que no se recortan por sede/área. */
    private const UNSCOPED_ROLES = ['account_admin', 'worker', 'contractor_admin', 'external_learner', 'platform_owner', 'platform_support'];

    private const NONE = '00000000-0000-0000-0000-000000000000';

    /** @var array<string, array{restricted: bool, sites: list<string>, areas: list<string>, area_mode: bool, modules: ?list<string>}> */
    private static array $cache = [];

    public static function flush(): void
    {
        self::$cache = [];
    }

    /** @return array{restricted: bool, sites: list<string>, areas: list<string>, area_mode: bool, modules: ?list<string>} */
    public static function current(): array
    {
        if (! app()->bound(CompanyContext::class)) {
            return ['restricted' => false, 'sites' => [], 'areas' => [], 'area_mode' => false, 'modules' => null];
        }
        $ctx = app(CompanyContext::class);

        return self::$cache[$ctx->membershipId] ??= self::load($ctx);
    }

    private static function load(CompanyContext $ctx): array
    {
        $m = DB::selectOne('select is_support_session, allowed_modules::text as mods from memberships where id = ?', [$ctx->membershipId]);
        $rows = DB::select("select scope_type, site_id, area_id, trim(module_code) as module_code from membership_scopes
                             where membership_id = ? and deleted_at is null and scope_type in ('site', 'area', 'module')", [$ctx->membershipId]);

        $modules = null;
        $listed = $m && $m->mods !== null ? array_values(array_filter(array_map(fn ($x) => trim($x, ' "'), explode(',', trim($m->mods, '{}'))))) : null;
        $scoped = array_values(array_filter(array_map(fn ($r) => $r->scope_type === 'module' ? strtoupper((string) $r->module_code) : null, $rows)));
        if ($listed !== null || $scoped !== []) {
            $modules = array_values(array_unique(array_merge(array_map('strtoupper', $listed ?? []), $scoped, self::BASE_MODULES)));
        }

        $out = ['restricted' => false, 'sites' => [], 'areas' => [], 'area_mode' => false, 'modules' => $modules];
        if (($m->is_support_session ?? false) || in_array($ctx->roleCode, self::UNSCOPED_ROLES, true)) {
            return $out;
        }
        $siteScopes = array_values(array_unique(array_filter(array_map(fn ($r) => $r->scope_type === 'site' ? $r->site_id : null, $rows))));
        $areaRoots = array_values(array_unique(array_filter(array_map(fn ($r) => $r->scope_type === 'area' ? $r->area_id : null, $rows))));
        if ($siteScopes === [] && $areaRoots === []) {
            return $out;
        }
        $arr = fn (array $l) => '{'.implode(',', $l).'}';
        $subtree = $areaRoots ? array_column(DB::select(
            'with recursive t as (select id from areas where id = any(?::uuid[]) and deleted_at is null
                                  union select a.id from areas a join t on a.parent_area_id = t.id where a.deleted_at is null) select id from t',
            [$arr($areaRoots)]), 'id') : [];
        $areaSites = $areaRoots ? array_column(DB::select('select distinct site_id from areas where id = any(?::uuid[])', [$arr($areaRoots)]), 'site_id') : [];
        $fullSites = array_values(array_diff($siteScopes, $areaSites));
        $siteAreas = $fullSites ? array_column(DB::select('select id from areas where site_id = any(?::uuid[]) and deleted_at is null', [$arr($fullSites)]), 'id') : [];

        return [
            'restricted' => true,
            'sites' => array_values(array_unique(array_merge($fullSites, $areaSites))) ?: [self::NONE],
            'areas' => array_values(array_unique(array_merge($subtree, $siteAreas))) ?: [self::NONE],
            // Hay áreas en el alcance: lo de nivel sede de esas sedes se ve, pero no las demás áreas de la sede.
            'area_mode' => $areaRoots !== [],
            'modules' => $modules,
        ];
    }

    // ── Módulos ───────────────────────────────────────────────────────────────

    /** @return list<string>|null módulos permitidos (null = todos los del rol) */
    public static function modules(): ?array
    {
        return self::current()['modules'];
    }

    public static function moduleAllowed(string $module): bool
    {
        $mods = self::modules();

        return $mods === null || in_array(strtoupper(trim($module)), $mods, true);
    }

    public static function assertModule(string $module): void
    {
        if (! self::moduleAllowed($module)) {
            throw new ApiProblem(403, 'module_not_allowed', 'Módulo fuera de tu acceso',
                'Tu acceso a esta empresa no incluye este módulo. Pídelo al administrador de la cuenta.', ['module' => strtoupper(trim($module))]);
        }
    }

    // ── Sede y área ───────────────────────────────────────────────────────────

    public static function restricted(): bool
    {
        return self::current()['restricted'];
    }

    /** @return list<string> sedes visibles (vacío = sin recorte) */
    public static function siteIds(): array
    {
        return self::restricted() ? self::current()['sites'] : [];
    }

    /** @return list<string> áreas visibles, con subáreas (vacío = sin recorte) */
    public static function areaIds(): array
    {
        return self::restricted() ? self::current()['areas'] : [];
    }

    /** Hay áreas en el alcance (los indicadores se calculan por área). */
    public static function areaMode(): bool
    {
        return self::restricted() && self::current()['area_mode'];
    }

    /** Columnas de sede y área de una tabla del registro (workers usa current_site_id / current_area_id). @return array{0: ?string, 1: ?string} */
    public static function columns(string $table): array
    {
        $res = Registry::data()['resources'][$table] ?? null;
        if (! $res || $table === 'membership_scopes') {
            return [null, null];
        }
        $names = array_column($res['fields'], 'name');
        $site = in_array('site_id', $names, true) ? 'site_id' : (in_array('current_site_id', $names, true) ? 'current_site_id' : null);
        $area = in_array('area_id', $names, true) ? 'area_id' : (in_array('current_area_id', $names, true) ? 'current_area_id' : null);

        return [$site, $area];
    }

    /** La tabla se recorta por alcance (tiene sede o área, o es la de sedes o áreas). */
    public static function appliesTo(string $table): bool
    {
        return in_array($table, ['sites', 'areas'], true) || self::columns($table) !== [null, null];
    }

    /**
     * Condición SQL para las filas de una tabla del registro con el alias dado; null si no hay recorte.
     * Con $strict las filas sin sede ni área (de toda la empresa) quedan fuera (para escribir).
     *
     * @return array{0: string, 1: list<string>}|null
     */
    public static function condition(string $table, string $alias = 't', bool $strict = false): ?array
    {
        if (! self::restricted()) {
            return null;
        }
        if ($table === 'sites') {
            return ["{$alias}.id = any(?::uuid[])", [self::arr(self::siteIds())]];
        }
        if ($table === 'areas') {
            return ["{$alias}.id = any(?::uuid[])", [self::arr(self::areaIds())]];
        }
        [$site, $area] = self::columns($table);

        return self::sql($site ? "{$alias}.\"{$site}\"" : null, $area ? "{$alias}.\"{$area}\"" : null, $strict);
    }

    /**
     * Condición SQL sobre expresiones de sede y área (para consultas propias de un módulo); null si no hay recorte.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    public static function sql(?string $siteExpr, ?string $areaExpr, bool $strict = false): ?array
    {
        if (! self::restricted() || ($siteExpr === null && $areaExpr === null)) {
            return null;
        }
        $parts = [];
        $bind = [];
        if ($areaExpr) {
            $parts[] = "{$areaExpr} = any(?::uuid[])";
            $bind[] = self::arr(self::areaIds());
        }
        if ($siteExpr) {
            $parts[] = '('.($areaExpr ? "{$areaExpr} is null and " : '')."{$siteExpr} = any(?::uuid[]))";
            $bind[] = self::arr(self::siteIds());
        }
        if (! $strict) {
            $parts[] = '('.implode(' and ', array_map(fn ($e) => "{$e} is null", array_filter([$siteExpr, $areaExpr]))).')';
        }

        return ['('.implode(' or ', $parts).')', $bind];
    }

    /** Igual que sql() pero como texto « and (...)» con los valores ya escritos (para consultas armadas sin bind). */
    public static function andSql(?string $siteExpr, ?string $areaExpr, bool $strict = false): string
    {
        $c = self::sql($siteExpr, $areaExpr, $strict);
        if (! $c) {
            return '';
        }
        $sql = $c[0];
        foreach ($c[1] as $v) {
            $sql = preg_replace('/\?/', "'".str_replace("'", "''", $v)."'", $sql, 1);
        }

        return ' and '.$sql;
    }

    /** Una sede/área concreta está dentro del alcance (para crear o mover un registro). Sin sede ni área: no. */
    public static function allows(?string $site, ?string $area): bool
    {
        if (! self::restricted()) {
            return true;
        }
        if ($area !== null && $area !== '' && ! in_array($area, self::areaIds(), true)) {
            return false;
        }
        if ($site !== null && $site !== '' && ! in_array($site, self::siteIds(), true)) {
            return false;
        }

        return ($area !== null && $area !== '') || ($site !== null && $site !== '');
    }

    /** La sede o área elegida como filtro está en el alcance; si no, 403. */
    public static function assertSite(?string $site): void
    {
        if ($site !== null && $site !== '' && self::restricted() && ! in_array($site, self::siteIds(), true)) {
            throw ApiProblem::forbidden('Esa sede está fuera del alcance de tu acceso.');
        }
    }

    /** Fila de una tabla del registro visible para el alcance (para endpoints propios que reciben un id). Si no, 404. */
    public static function assertRow(string $table, string $id): void
    {
        if (! ($c = self::condition($table, 't')) || ! \Illuminate\Support\Str::isUuid($id)) {
            return;
        }
        if (! DB::selectOne("select 1 as ok from \"{$table}\" t where t.id = ? and {$c[0]}", array_merge([$id], $c[1]))) {
            throw ApiProblem::notFound('El registro no existe o está fuera del alcance de tu acceso.');
        }
    }

    /** Como assertRow y, además, la fila no es de toda la empresa (para modificarla). Si no, 403. */
    public static function assertRowWritable(string $table, string $id): void
    {
        self::assertRow($table, $id);
        if (($c = self::condition($table, 't', strict: true)) && \Illuminate\Support\Str::isUuid($id)
            && ! DB::selectOne("select 1 as ok from \"{$table}\" t where t.id = ? and {$c[0]}", array_merge([$id], $c[1]))) {
            throw ApiProblem::forbidden('Este registro es de toda la empresa: tu acceso está limitado a algunas sedes o áreas.');
        }
    }

    private static function arr(array $l): string
    {
        return '{'.implode(',', $l).'}';
    }
}
