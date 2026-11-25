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

    /** @var array<string, array{restricted: bool, sites: list<string>, areas: list<string>, area_mode: bool, modules: ?list<string>, period: ?array{0: string, 1: string}}> */
    private static array $cache = [];

    public static function flush(): void
    {
        self::$cache = [];
    }

    /** @return array{restricted: bool, sites: list<string>, areas: list<string>, area_mode: bool, modules: ?list<string>, period: ?array{0: string, 1: string}} */
    public static function current(): array
    {
        if (! app()->bound(CompanyContext::class)) {
            return ['restricted' => false, 'sites' => [], 'areas' => [], 'area_mode' => false, 'modules' => null, 'period' => null];
        }
        $ctx = app(CompanyContext::class);

        return self::$cache[$ctx->membershipId] ??= self::load(DB::connection(), $ctx->membershipId, $ctx->roleCode);
    }

    private static function load(\Illuminate\Database\ConnectionInterface $db, string $membershipId, string $roleCode): array
    {
        $m = $db->selectOne('select is_support_session, allowed_modules::text as mods, audit_period_from::text as pf, audit_period_to::text as pt from memberships where id = ?', [$membershipId]);
        $rows = $db->select("select scope_type, site_id, area_id, trim(module_code) as module_code from membership_scopes
                             where membership_id = ? and deleted_at is null and scope_type in ('site', 'area', 'module')", [$membershipId]);

        $modules = null;
        $listed = $m && $m->mods !== null ? array_values(array_filter(array_map(fn ($x) => trim($x, ' "'), explode(',', trim($m->mods, '{}'))))) : null;
        $scoped = array_values(array_filter(array_map(fn ($r) => $r->scope_type === 'module' ? strtoupper((string) $r->module_code) : null, $rows)));
        if ($listed !== null || $scoped !== []) {
            $modules = array_values(array_unique(array_merge(array_map('strtoupper', $listed ?? []), $scoped, self::BASE_MODULES)));
        }
        // docs/05 §4.5: el auditor externo ve los registros del periodo de su auditoría (desde/hasta).
        $period = $roleCode === 'external_auditor' && ($m->pf ?? null) && ($m->pt ?? null) ? [(string) $m->pf, (string) $m->pt] : null;

        $out = ['restricted' => false, 'sites' => [], 'areas' => [], 'area_mode' => false, 'modules' => $modules, 'period' => $period];
        if (($m->is_support_session ?? false) || in_array($roleCode, self::UNSCOPED_ROLES, true)) {
            return $out;
        }
        $siteScopes = array_values(array_unique(array_filter(array_map(fn ($r) => $r->scope_type === 'site' ? $r->site_id : null, $rows))));
        $areaRoots = array_values(array_unique(array_filter(array_map(fn ($r) => $r->scope_type === 'area' ? $r->area_id : null, $rows))));
        if ($siteScopes === [] && $areaRoots === []) {
            return $out;
        }
        $arr = fn (array $l) => '{'.implode(',', $l).'}';
        $subtree = $areaRoots ? array_column($db->select(
            'with recursive t as (select id from areas where id = any(?::uuid[]) and deleted_at is null
                                  union select a.id from areas a join t on a.parent_area_id = t.id where a.deleted_at is null) select id from t',
            [$arr($areaRoots)]), 'id') : [];
        $areaSites = $areaRoots ? array_column($db->select('select distinct site_id from areas where id = any(?::uuid[])', [$arr($areaRoots)]), 'site_id') : [];
        $fullSites = array_values(array_diff($siteScopes, $areaSites));
        $siteAreas = $fullSites ? array_column($db->select('select id from areas where site_id = any(?::uuid[]) and deleted_at is null', [$arr($fullSites)]), 'id') : [];

        return [
            'restricted' => true,
            'sites' => array_values(array_unique(array_merge($fullSites, $areaSites))) ?: [self::NONE],
            'areas' => array_values(array_unique(array_merge($subtree, $siteAreas))) ?: [self::NONE],
            // Hay áreas en el alcance: lo de nivel sede de esas sedes se ve, pero no las demás áreas de la sede.
            'area_mode' => $areaRoots !== [],
            'modules' => $modules,
            'period' => $period,
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

    /** La tabla se recorta por alcance (tiene sede o área, es la de sedes o áreas, o es hija de una que se recorta). */
    public static function appliesTo(string $table): bool
    {
        return in_array($table, ['sites', 'areas'], true) || self::columns($table) !== [null, null] || self::parent($table) !== null;
    }

    /** @var array<string, array{0: string, 1: string}|false> */
    private static array $parents = [];

    /**
     * Registro padre de una tabla hija sin sede ni área propias (ítems, asistencias, participantes, causas, filas IPERC…):
     * el primer campo de referencia obligatorio del registro cuyo destino se recorta por alcance (con sede/área propias o,
     * a su vez, hijo de uno). Así el hijo hereda el alcance del padre. Las referencias a usuarios no cuentan.
     *
     * @return array{0: string, 1: string}|null [columna, tabla padre]
     */
    public static function parent(string $table, int $depth = 0): ?array
    {
        if (isset(self::$parents[$table])) {
            return self::$parents[$table] ?: null;
        }
        $res = Registry::data()['resources'][$table] ?? null;
        if (! $res || $depth > 4 || in_array($table, ['sites', 'areas', 'membership_scopes'], true) || self::columns($table) !== [null, null]) {
            return null;
        }
        $found = false;
        foreach ($res['fields'] as $f) {
            if (($f['type'] ?? '') !== 'ref' || empty($f['required']) || in_array($f['ref'], ['users', $table, 'sites', 'areas'], true)) {
                continue;
            }
            if (self::columns($f['ref']) !== [null, null] || self::parent($f['ref'], $depth + 1) !== null) {
                $found = [$f['name'], $f['ref']];
                break;
            }
        }
        if ($depth === 0 || $found) {
            self::$parents[$table] = $found;
        }

        return $found ?: null;
    }

    /**
     * Condición SQL para las filas de una tabla del registro con el alias dado; null si no hay recorte.
     * Con $strict las filas sin sede ni área (de toda la empresa) quedan fuera (para escribir).
     *
     * @return array{0: string, 1: list<string>}|null
     */
    public static function condition(string $table, string $alias = 't', bool $strict = false): ?array
    {
        return self::conditionFor($table, $alias, $strict, true);
    }

    /** @return array{0: string, 1: list<string>}|null */
    private static function conditionFor(string $table, string $alias, bool $strict, bool $withPeriod): ?array
    {
        $restricted = self::restricted();
        $period = $withPeriod ? self::period() : null;
        if (! $restricted && $period === null) {
            return null;
        }
        if ($table === 'sites' || $table === 'areas') {
            return $restricted ? ["{$alias}.id = any(?::uuid[])", [self::arr($table === 'sites' ? self::siteIds() : self::areaIds())]] : null;
        }
        [$site, $area] = self::columns($table);
        $own = $site !== null || $area !== null;
        $date = $period ? self::dateExpr($table, $alias) : null;
        $parts = [];
        $bind = [];
        if ($restricted && $own && ($c = self::sql($site ? "{$alias}.\"{$site}\"" : null, $area ? "{$alias}.\"{$area}\"" : null, $strict))) {
            $parts[] = $c[0];
            array_push($bind, ...$c[1]);
        }
        if ($date) {
            // docs/05 §4.5: el auditor externo ve los registros del periodo de su auditoría (fecha principal del registro).
            $parts[] = "({$date} is null or {$date} between ?::date and ?::date)";
            array_push($bind, $period[0], $period[1]);
        }
        // Tabla hija: hereda del padre lo que no tiene propio (sede/área y, sin fecha propia, el periodo).
        $needSite = $restricted && ! $own;
        $needPeriod = $period !== null && $date === null;
        if (($needSite || $needPeriod) && ($p = self::parent($table))
            && ($pc = self::conditionFor($p[1], $alias.'_p', $strict, $needPeriod))) {
            $parts[] = "exists (select 1 from \"{$p[1]}\" {$alias}_p where {$alias}_p.id = {$alias}.\"{$p[0]}\" and {$pc[0]})";
            array_push($bind, ...$pc[1]);
        }

        return $parts ? ['('.implode(' and ', $parts).')', $bind] : null;
    }

    /**
     * Fecha principal de los registros con fecha propia (eventos, inspecciones, charlas, permisos, entregas…) para el
     * periodo del auditor externo (docs/05 §4.5, M-AUI RN-AUI-08). Los datos maestros (trabajadores, sedes, documentos,
     * matrices, procedimientos) no se recortan por fecha. «@month»: tablas por periodo (año y mes).
     */
    private const PERIOD_DATES = [
        'sst_events' => 'occurred_at', 'substandard_reports' => 'occurred_at', 'investigations' => 'started_on',
        'inspections' => 'scheduled_on', 'findings' => 'created_at', 'corrective_actions' => 'created_at',
        'safety_talks' => 'talk_on', 'ats_forms' => 'performed_on', 'work_permits' => 'valid_from',
        'behavior_observations' => 'observed_on', 'ppe_deliveries' => 'delivered_at', 'ppe_stock_movements' => 'moved_at',
        'drills' => 'scheduled_at', 'training_sessions' => 'starts_at', 'audits' => 'starts_on',
        'measurements' => 'measured_at', 'committee_meetings' => 'scheduled_at', 'equipment_checks' => 'checked_at',
        'access_events' => 'occurred_at', 'absences' => 'starts_on', 'ergonomic_assessments' => 'assessed_on',
        'activity_executions' => 'executed_on', 'budget_executions' => 'executed_on', 'requirement_evaluations' => 'evaluated_on',
        'baseline_assessments' => 'assessment_on', 'change_requests' => 'requested_on', 'management_reviews' => 'review_on',
        'self_diagnostics' => 'diagnostic_on', 'vendor_evaluations' => 'evaluated_on', 'risst_deliveries' => 'delivered_on',
        'waste_records' => 'generated_on', 'man_hours' => '@month', 'monthly_statistics' => '@month',
    ];

    private static function dateExpr(string $table, string $alias): ?string
    {
        $col = self::PERIOD_DATES[$table] ?? null;
        if ($col === null) {
            return null;
        }
        if ($col === '@month') {
            return "make_date({$alias}.period_year, {$alias}.period_month, 1)";
        }
        $res = Registry::data()['resources'][$table] ?? null;
        $type = $res ? (Registry::field($res, $col)['type'] ?? 'datetime') : 'datetime';

        return $type === 'date' ? "{$alias}.\"{$col}\"" : "({$alias}.\"{$col}\" at time zone 'America/Lima')::date";
    }

    /** Periodo de auditoría del auditor externo (memberships.audit_period_from/to). @return array{0: string, 1: string}|null */
    public static function period(): ?array
    {
        return self::current()['period'] ?? null;
    }

    /** Hay algún recorte de registros (sede/área o periodo de auditoría). */
    public static function filtersRecords(): bool
    {
        return self::restricted() || self::period() !== null;
    }

    /**
     * Al crear o mover un registro hijo: el padre elegido debe estar en el alcance y no ser de toda la empresa.
     * true si la tabla no es hija o no hay recorte.
     */
    public static function allowsParentOf(string $table, array $row): bool
    {
        if (! self::restricted() || self::columns($table) !== [null, null] || ! ($p = self::parent($table))) {
            return true;
        }
        $pid = $row[$p[0]] ?? null;
        if (! is_string($pid) || ! \Illuminate\Support\Str::isUuid($pid)) {
            return true; // la validación del campo obligatorio responde
        }
        $c = self::condition($p[1], 'x', strict: true);

        return ! $c || (bool) DB::selectOne("select 1 as ok from \"{$p[1]}\" x where x.id = ? and {$c[0]}", array_merge([$pid], $c[1]));
    }

    /**
     * Para los endpoints propios de cada módulo que reciben un id: 404 si el registro (o, en una tabla hija, su padre) está
     * fuera del alcance; con $write, además 403 si es de toda la empresa (assertRowWritable).
     */
    public static function assertRecord(string $table, ?string $id, bool $write = false): void
    {
        if ($id !== null && $id !== '') {
            $write ? self::assertRowWritable($table, $id) : self::assertRow($table, $id);
        }
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

    /** Como condition() pero como texto « and (...)» con los valores ya escritos (tablas del registro, incluidas las hijas). */
    public static function andTable(string $table, string $alias = 't', bool $strict = false): string
    {
        $c = self::condition($table, $alias, $strict);
        if (! $c) {
            return '';
        }
        $sql = $c[0];
        foreach ($c[1] as $v) {
            $sql = preg_replace('/\?/', "'".str_replace("'", "''", $v)."'", $sql, 1);
        }

        return ' and '.$sql;
    }

    /**
     * Para tablas sin sede ni área propias que apuntan a un trabajador (fotochecks, matrículas, verificaciones…): texto
     * « and (...)» que deja las filas cuyo trabajador está en el alcance. Las filas sin trabajador propio (contratistas,
     * participantes externos) son de toda la empresa y se leen. '' si no hay recorte.
     */
    public static function andWorker(string $workerExpr): string
    {
        $c = self::andTable('workers', 'scope_w');
        if ($c === '') {
            return '';
        }

        return " and ({$workerExpr} is null or exists (select 1 from workers scope_w where scope_w.id = {$workerExpr}".$c.'))';
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

    // ── Destinatarios de avisos ──────────────────────────────────────────────

    /**
     * M-NOT §4 («los permisos se acotan además por membership_scopes») y docs/05 §2: de los usuarios que recibirían un aviso
     * por su rol, deja solo a quienes ven el registro del aviso por el alcance de su membresía en la empresa (el resto de
     * la empresa no recibe avisos de sedes o áreas que no ve). Los registros de toda la empresa llegan a todos. Sirve
     * también fuera de una petición (alertas diarias) con la conexión de la empresa.
     *
     * @param  list<?string>  $users
     * @return list<string>
     */
    public static function usersSeeing(array $users, string $table, ?string $id, ?\Illuminate\Database\ConnectionInterface $db = null): array
    {
        $users = array_values(array_unique(array_filter($users)));
        if ($users === [] || ! $id || ! \Illuminate\Support\Str::isUuid($id) || ! self::appliesTo($table)) {
            return $users;
        }
        $db ??= DB::connection();
        $loc = self::location($db, $table, $id);
        if ($loc === null || ($loc[0] === null && $loc[1] === null)) {
            return $users;
        }
        $members = $db->select("select m.id, m.user_id, coalesce(r.base_role_code, r.code) as role from memberships m join roles r on r.id = m.role_id
                                 where m.user_id = any(?::uuid[]) and m.status = 'active' and m.deleted_at is null
                                   and m.company_id = nullif(current_setting('app.company_id', true), '')::uuid", [self::arr($users)]);
        $hidden = [];
        foreach ($members as $m) {
            $s = self::$cache[$m->id] ??= self::load($db, $m->id, $m->role);
            $sees = ! $s['restricted'] || ($loc[1] !== null ? in_array($loc[1], $s['areas'], true) : in_array($loc[0], $s['sites'], true));
            $hidden[$m->user_id] = ($hidden[$m->user_id] ?? true) && ! $sees;
        }

        return array_values(array_filter($users, fn ($u) => ! ($hidden[$u] ?? false)));
    }

    /** Sede y área de un registro (propias o de su registro padre). null si la tabla no tiene alcance o no existe. @return array{0: ?string, 1: ?string}|null */
    private static function location(\Illuminate\Database\ConnectionInterface $db, string $table, string $id, int $depth = 0): ?array
    {
        if ($depth > 5) {
            return null;
        }
        if ($table === 'sites') {
            return [$id, null];
        }
        if ($table === 'areas') {
            $a = $db->selectOne('select site_id from areas where id = ?', [$id]);

            return $a ? [$a->site_id, $id] : null;
        }
        [$site, $area] = self::columns($table);
        if ($site !== null || $area !== null) {
            $r = $db->selectOne('select '.($site ? "\"{$site}\"" : 'null').' as s, '.($area ? "\"{$area}\"" : 'null')." as a from \"{$table}\" where id = ?", [$id]);

            return $r ? [$r->s, $r->a] : null;
        }
        if (! ($p = self::parent($table))) {
            return null;
        }
        $fk = $db->selectOne("select \"{$p[0]}\" as v from \"{$table}\" where id = ?", [$id])?->v;

        return $fk ? self::location($db, $p[1], (string) $fk, $depth + 1) : null;
    }

    private static function arr(array $l): string
    {
        return '{'.implode(',', $l).'}';
    }
}
