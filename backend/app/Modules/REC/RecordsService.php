<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Modules\REC\Rules\RecordRules;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Motor de registros de los módulos SST: listar, ver, crear, editar y archivar
 * cualquier tabla del catálogo (Registry) de la empresa activa.
 *
 * - Todo corre en la conexión de la app dentro de la transacción de SetTenantContext:
 *   RLS limita cada consulta a la empresa activa (y a los registros globales en tablas híbridas).
 * - Los nombres de tablas y columnas salen solo del catálogo, nunca de la petición.
 * - Los campos [CIFRADO EN APLICACIÓN] se guardan cifrados y solo se descifran con permiso de edición.
 * - Cada cambio queda en audit_logs.
 */
final class RecordsService
{
    public const PER_PAGE = 25;

    /**
     * Rol «trabajador»: en estos módulos solo ve sus propios registros (salud, capacitaciones, EPP, fotocheck, legajo).
     * Ley 29733 y Ley 29783 art. 71: los datos de salud y personales de un trabajador no se muestran a sus compañeros.
     */
    private const SELF_ONLY_MODULES = ['EMO', 'DES', 'VUL', 'VMD', 'CAP', 'FOT', 'EPP', 'TRB', 'CH5', 'AUL', 'SCT'];

    /** Tablas que el trabajador solo consulta: no registra su propia asistencia ni su nota. */
    private const WORKER_READ_ONLY = ['training_attendances', 'training_evaluations', 'training_certificates', 'fitness_status', 'medical_exam_orders', 'credentials', 'ppe_deliveries'];

    /** Etiquetas compuestas para tablas cuya columna de nombre no identifica a la persona o registro ({a} = alias). */
    private const LABEL_SQL = [
        'contractor_workers' => "concat_ws(' ', {a}.first_name, {a}.paternal_last_name, case when {a}.document_last4 is not null then '· ****' || {a}.document_last4 end)",
        'sctr_policies' => "concat_ws(' · ', case {a}.coverage_kind when 'health' then 'Salud' when 'pension' then 'Pensión' end, {a}.insurer_name, {a}.policy_number)",
        'committee_meetings' => "concat('Sesión N.° ', {a}.number, ' · ', case {a}.meeting_type when 'ordinary' then 'Ordinaria' when 'extraordinary' then 'Extraordinaria' else {a}.meeting_type end)",
        'iperc_versions' => "concat('Versión ', {a}.version_number, case {a}.status when 'current' then ' (vigente)' when 'draft' then ' (borrador)' when 'obsolete' then ' (obsoleta)' else '' end)",
        'access_events' => "concat(to_char({a}.occurred_at at time zone 'America/Lima', 'DD/MM/YYYY HH24:MI'), ' · ', case {a}.direction when 'in' then 'Ingreso' when 'out' then 'Salida' else {a}.direction end)",
    ];

    public static function labelSql(string $table, string $alias): ?string
    {
        return isset(self::LABEL_SQL[$table]) ? str_replace('{a}', $alias, self::LABEL_SQL[$table]) : null;
    }

    /**
     * Administrador de contratista (contractor_admin): solo ve y toca lo de SU contratista (membership_scopes
     * «contractor»). Tablas con contractor_id → solo las suyas; la tabla de contratistas → solo su fila; los requisitos
     * (catálogo de lo que se exige) se leen; cualquier otra tabla queda vacía para este rol.
     */
    private function contractorScope(array $res): ?array
    {
        if (app(\App\Core\Tenancy\CompanyContext::class)->roleCode !== 'contractor_admin') {
            return null;
        }
        $cid = \App\Modules\CTR\ContractorStatus::scopedContractorId();
        if (! $cid) {
            return ['false', []];
        }
        if ($res['table'] === 'contractors') {
            return ['t.id = ?', [$cid]];
        }
        if (in_array('contractor_id', array_column($res['fields'], 'name'), true)) {
            return ['t.contractor_id = ?', [$cid]];
        }

        return $res['table'] === 'contractor_requirements' ? null : ['false', []];
    }

    /** Condición «solo lo suyo» para el rol trabajador o el contratista, o null si no aplica. */
    private function selfScope(array $res): ?array
    {
        if ($c = $this->contractorScope($res)) {
            return $c;
        }
        $ctx = app(\App\Core\Tenancy\CompanyContext::class);
        if ($ctx->roleCode !== 'worker' || ! in_array($res['module'], self::SELF_ONLY_MODULES, true)
            || ! in_array('worker_id', array_column($res['fields'], 'name'), true)) {
            return null;
        }
        $worker = DB::selectOne('select worker_id from memberships where id = ?', [$ctx->membershipId])?->worker_id;

        return $worker ? ['t.worker_id = ?', [$worker]] : ['false', []];
    }

    /** Permisos del usuario sobre una tabla: read, create, update, delete. */
    public function abilities(array $res): array
    {
        $effective = Permissions::effective();
        $p = strtolower($res['module']).'.'.$res['table'].'.';
        $can = fn (string $a) => in_array($p.$a, $effective, true);

        $workerReadOnly = app(\App\Core\Tenancy\CompanyContext::class)->roleCode === 'worker' && in_array($res['table'], self::WORKER_READ_ONLY, true);

        return [
            'read' => $can('read'),
            'create' => $can('create') && ! $workerReadOnly,
            'update' => $can('update') && ! $res['immutable'] && ! $workerReadOnly,
            'delete' => $can('delete') && ! $res['immutable'] && ! $workerReadOnly,
            'export' => $can('export') && ! $workerReadOnly,
        ];
    }

    public function authorize(array $res, string $action): void
    {
        if (! ($this->abilities($res)[$action] ?? false)) {
            throw ApiProblem::forbidden($action === 'read'
                ? 'Tu rol no puede ver estos registros.'
                : 'Tu rol no puede hacer este cambio. Pídelo al responsable SST o al administrador.');
        }
    }

    /** Lista paginada con búsqueda y filtros por opciones, referencias y sí/no. */
    public function list(array $res, array $query): array
    {
        [$select, $from] = $this->selectSql($res, forList: true);
        [$where, $bind] = $this->whereSql($res, $query);

        $page = max(1, (int) ($query['page'] ?? 1));
        $per = min(100, max(5, (int) ($query['per_page'] ?? self::PER_PAGE)));
        $total = (int) DB::selectOne("select count(*) as n {$from} {$where}", $bind)->n;
        $order = $res['updated_at'] ? 't.updated_at desc, t.id desc' : 't.id desc';
        $rows = DB::select("select {$select} {$from} {$where} order by {$order} limit {$per} offset ".(($page - 1) * $per), $bind);

        return [
            'data' => array_map(fn ($r) => $this->present($res, $r, forList: true), $rows),
            'meta' => ['page' => $page, 'per_page' => $per, 'total' => $total, 'pages' => (int) max(1, ceil($total / $per))],
        ];
    }

    public function find(array $res, string $id, bool $decrypt = false): array
    {
        $row = $this->row($res, $id);

        return $this->present($res, $row, decrypt: $decrypt);
    }

    public function create(array $res, array $input): array
    {
        if (app(\App\Core\Tenancy\CompanyContext::class)->roleCode === 'contractor_admin') {
            $cid = \App\Modules\CTR\ContractorStatus::scopedContractorId();
            if (! $cid || $res['table'] === 'contractors' || ! in_array('contractor_id', array_column($res['fields'], 'name'), true)) {
                throw ApiProblem::forbidden('Como contratista solo registras información de tu propia empresa.');
            }
            $input['contractor_id'] = $cid;
        }
        $rules = RecordRules::for($res['table']);
        $values = $this->validate($res, $input, creating: true);
        $extra = [];
        $rules?->prepare('create', $res, $values, null, $extra);
        $this->checkUniques($res, $values, null);

        $ctx = app(CompanyContext::class);
        $user = (string) auth()->id();
        $cols = ['company_id'];
        $exprs = ['?'];
        $bind = [$ctx->companyId];
        if ($res['stamps']) {
            $cols[] = 'created_by';
            $exprs[] = '?';
            $bind[] = $user;
        }
        if ($res['updated_by'] ?? $res['stamps']) {
            $cols[] = 'updated_by';
            $exprs[] = '?';
            $bind[] = $user;
        }
        foreach ($this->writeParts($res, $values, $extra) as [$col, $expr, $b]) {
            $cols[] = $col;
            $exprs[] = $expr;
            array_push($bind, ...$b);
        }

        $id = $this->guard(fn () => DB::selectOne(
            'insert into "'.$res['table'].'" ('.implode(', ', array_map(fn ($c) => '"'.$c.'"', $cols)).') values ('.implode(', ', $exprs).') returning id',
            $bind
        )->id);

        $rules?->after('create', $res, $id, $values, null);
        $this->audit($res, 'create', $id, $this->auditable($res, $values));
        \App\Modules\API\Webhooks::queue($res['table'], 'create', $id, $values);

        return $this->find($res, $id, decrypt: true) + ($rules?->reveal($res, $id) ?? []);
    }

    public function update(array $res, string $id, array $input): array
    {
        $current = $this->row($res, $id);
        if ($res['hybrid'] && $current->company_id === null) {
            throw ApiProblem::forbidden('Este registro es del catálogo general de EscudoSST: no se puede editar. Crea uno propio.');
        }
        $rules = RecordRules::for($res['table']);
        $values = $this->validate($res, $input, creating: false);
        $extra = [];
        $rules?->prepare('update', $res, $values, $current, $extra);
        // M-FIR RN-FIR-03: con todas sus firmas completas el registro queda bloqueado (solo puede seguir su flujo de estados).
        if (array_diff(array_keys($values), ['status']) !== [] || $extra !== []) {
            $this->assertNotFullySigned($res['table'], $id);
        }
        $this->checkUniques($res, $values, $current);

        $sets = [];
        $bind = [];
        foreach ($this->writeParts($res, $values, $extra) as [$col, $expr, $b]) {
            $sets[] = '"'.$col.'" = '.$expr;
            array_push($bind, ...$b);
        }
        if ($sets === []) {
            return $this->find($res, $id, decrypt: true);
        }
        if ($res['updated_by'] ?? $res['stamps']) {
            $sets[] = 'updated_by = ?';
            $bind[] = (string) auth()->id();
        }
        $bind[] = $id;
        $this->guard(fn () => DB::update('update "'.$res['table'].'" set '.implode(', ', $sets).' where id = ?', $bind));

        $rules?->after('update', $res, $id, $values, $current);
        $this->audit($res, 'update', $id, $this->diff($res, $values, $current));
        \App\Modules\API\Webhooks::queue($res['table'], 'update', $id, $values);

        return $this->find($res, $id, decrypt: true);
    }

    /** RN-FIR-03: un registro con firmas pedidas y todas firmadas no se edita; se corrige con una versión o anexo. */
    private function assertNotFullySigned(string $table, string $id): void
    {
        if ($table === 'signature_requests') {
            return;
        }
        $s = DB::selectOne("select count(*) as n, count(*) filter (where status = 'signed') as signed from signature_requests
                             where subject_type = ? and subject_id = ? and deleted_at is null and status <> 'cancelled'", [$table, $id]);
        if ($s && (int) $s->n > 0 && (int) $s->n === (int) $s->signed) {
            throw new ApiProblem(409, 'conflict', 'Registro firmado', 'El registro ya tiene todas sus firmas: no se edita. Para corregirlo crea una nueva versión o un anexo.');
        }
    }

    public function delete(array $res, string $id): void
    {
        $current = $this->row($res, $id);
        if ($res['hybrid'] && $current->company_id === null) {
            throw ApiProblem::forbidden('Este registro es del catálogo general de EscudoSST: no se puede archivar.');
        }
        RecordRules::for($res['table'])?->prepareDelete($res, $current);
        $this->assertNotFullySigned($res['table'], $id);

        $this->guard(fn () => $res['soft_delete']
            ? DB::update('update "'.$res['table'].'" set deleted_at = now()'.(($res['updated_by'] ?? $res['stamps']) ? ', updated_by = ?' : '').' where id = ?',
                ($res['updated_by'] ?? $res['stamps']) ? [(string) auth()->id(), $id] : [$id])
            : DB::delete('delete from "'.$res['table'].'" where id = ?', [$id]));

        $label = $res['label'] !== 'id' ? ($current->{$res['label']} ?? null) : null;
        $this->audit($res, 'delete', $id, ['label' => $label]);
    }

    /** Opciones para un campo de referencia: [{id, label}] de la tabla destino (RLS aplica). */
    public function options(array $res, string $fieldName, ?string $q, ?string $id = null): array
    {
        $f = Registry::field($res, $fieldName);
        if (! $f || $f['type'] !== 'ref') {
            throw ApiProblem::notFound('Ese campo no tiene opciones.');
        }
        $target = $f['ref'];

        if ($target === 'users') {
            $sql = "select u.id, u.full_name as label from users u
                      join memberships m on m.user_id = u.id and m.company_id = ? and m.deleted_at is null and m.status = 'active'
                     where u.deleted_at is null";
            $bind = [app(CompanyContext::class)->companyId];
            $labelExpr = 'u.full_name';
            $alias = 'u';
        } else {
            $label = Registry::labelColumn($target) ?? 'id';
            $labelExpr = 'r."'.$label.'"::text';
            $code = Registry::has($target) && Registry::field(Registry::data()['resources'][$target], 'code') && $label !== 'code';
            if ($code) {
                $labelExpr = "concat_ws(' · ', r.\"code\", r.\"{$label}\")";
            }
            $labelExpr = self::labelSql($target, 'r') ?? $labelExpr;
            $sql = "select r.id, {$labelExpr} as label from \"{$target}\" r where true";
            $bind = [];
            $alias = 'r';
            if (Registry::softDelete($target)) {
                $sql .= ' and r.deleted_at is null';
            }
            // Catálogo híbrido: si la empresa tiene su propia copia (mismo código), se ofrece solo esa.
            $targetRes = Registry::data()['resources'][$target] ?? null;
            if ($targetRes && $targetRes['hybrid'] && Registry::field($targetRes, 'code')) {
                $sql .= " and not (r.company_id is null and exists (select 1 from \"{$target}\" o where o.company_id is not null and o.code = r.code"
                    .(Registry::softDelete($target) ? ' and o.deleted_at is null' : '').'))';
            }
            // Para registros nuevos no se ofrecen cesados (RN-TRB-07) ni sedes temporales ya terminadas (RN-TEN-07);
            // su historial sigue mostrando la etiqueta (búsqueda por id).
            if ($id === null && $target === 'workers') {
                $sql .= " and r.status <> 'terminated'";
            }
            if ($id === null && $target === 'sites') {
                $sql .= " and not (coalesce(r.is_temporary, false) and r.ends_on < (now() at time zone 'America/Lima')::date)";
            }
        }
        if ($id !== null) {
            if (! Str::isUuid($id)) {
                return [];
            }
            $sql .= " and {$alias}.id = ?";
            $bind[] = $id;
        } elseif ($q !== null && trim($q) !== '') {
            $sql .= " and {$labelExpr} ilike ?";
            $bind[] = '%'.addcslashes(trim($q), '%_\\').'%';
        }

        return array_map(fn ($r) => ['id' => $r->id, 'label' => (string) $r->label],
            DB::select($sql." order by 2 limit 50", $bind));
    }

    // ── Lectura ───────────────────────────────────────────────────────────────

    private function row(array $res, string $id): object
    {
        if (! Str::isUuid($id)) {
            throw ApiProblem::notFound('El registro no existe.');
        }
        [$select, $from] = $this->selectSql($res, forList: false);
        $scope = $this->selfScope($res);
        $row = DB::selectOne("select {$select} {$from} where t.id = ?".($res['soft_delete'] ? ' and t.deleted_at is null' : '').($scope ? " and {$scope[0]}" : ''),
            array_merge([$id], $scope[1] ?? []));
        if ($row === null) {
            throw ApiProblem::notFound('El registro no existe o es de otra empresa.');
        }

        return $row;
    }

    private function selectSql(array $res, bool $forList): array
    {
        $parts = ['t.id', 't.company_id'];
        if ($res['updated_at']) {
            $parts[] = 't.updated_at';
        }
        foreach ($res['fields'] as $f) {
            $c = $f['name'];
            if ($forList && empty($f['list']) && ! in_array($f['type'], ['enum', 'bool', 'ref'], true)) {
                continue;
            }
            if ($forList && ! empty($f['encrypted'])) {
                continue;
            }
            $parts[] = match ($f['type']) {
                'tags' => "array_to_json(t.\"{$c}\") as \"{$c}\"",
                'time' => "to_char(t.\"{$c}\", 'HH24:MI') as \"{$c}\"",
                default => "t.\"{$c}\"",
            };
            if ($f['type'] === 'file') {
                $parts[] = "(select r.original_name from files r where r.id = t.\"{$c}\") as \"{$c}__label\"";
            }
            if ($f['type'] === 'ref' && (! $forList || ! empty($f['list']))) {
                $target = $f['ref'];
                $label = $target === 'users' ? 'full_name' : (Registry::labelColumn($target) ?? 'id');
                $expr = self::labelSql($target, 'r') ?? "r.\"{$label}\"::text";
                $parts[] = "(select {$expr} from \"{$target}\" r where r.id = t.\"{$c}\") as \"{$c}__label\"";
            }
        }

        return [implode(', ', $parts), 'from "'.$res['table'].'" t'];
    }

    private function whereSql(array $res, array $query): array
    {
        $where = [];
        $bind = [];
        if ($res['soft_delete']) {
            $where[] = 't.deleted_at is null';
        }
        $q = trim((string) ($query['q'] ?? ''));
        if ($q !== '') {
            $or = [];
            foreach ($res['fields'] as $f) {
                if (in_array($f['type'], ['text', 'longtext'], true) && empty($f['encrypted'])) {
                    $or[] = "t.\"{$f['name']}\" ilike ?";
                    $bind[] = '%'.addcslashes($q, '%_\\').'%';
                }
            }
            if ($or !== []) {
                $where[] = '('.implode(' or ', $or).')';
            }
        }
        foreach ((array) ($query['f'] ?? []) as $key => $value) {
            [$where, $bind] = $this->filter($res, (string) $key, $value, $where, $bind);
        }
        if (($query['own'] ?? null) === '1' && $res['hybrid']) {
            $where[] = 't.company_id is not null';
        }
        if ($scope = $this->selfScope($res)) {
            $where[] = $scope[0];
            array_push($bind, ...$scope[1]);
        }

        return [$where ? 'where '.implode(' and ', $where) : '', $bind];
    }

    /**
     * Un filtro de la lista. Formas (llegan como f[clave]=valor, p. ej. desde el tablero):
     *  - campo=valor             igualdad (opción, referencia, sí/no); «a,b» en opciones = cualquiera de ellas
     *  - campo__lt / __lte / __gt / __gte = valor   comparación en fechas y números; «today» es la fecha de hoy (Lima)
     *  - campo__null = 1 | 0     vacío o con valor
     *  - source_id=uuid          registros generados desde otro (acciones correctivas y similares)
     */
    private function filter(array $res, string $key, mixed $value, array $where, array $bind): array
    {
        if ($value === '' || $value === null || is_array($value)) {
            return [$where, $bind];
        }
        $value = (string) $value;
        [$name, $op] = array_pad(explode('__', $key, 2), 2, null);
        if ($name === 'source_id' && $op === null && Registry::field($res, 'source_type') && Str::isUuid($value)) {
            $where[] = 't.source_id = ?';
            $bind[] = $value;

            return [$where, $bind];
        }
        $f = Registry::field($res, $name);
        if (! $f) {
            return [$where, $bind];
        }
        $col = "t.\"{$f['name']}\"";
        if ($op === 'null' && in_array($value, ['1', '0'], true)) {
            $where[] = $col.($value === '1' ? ' is null' : ' is not null');

            return [$where, $bind];
        }
        if (in_array($op, ['lt', 'lte', 'gt', 'gte'], true) && in_array($f['type'], ['date', 'datetime', 'int', 'number'], true)) {
            $sym = ['lt' => '<', 'lte' => '<=', 'gt' => '>', 'gte' => '>='][$op];
            if ($value === 'today' && in_array($f['type'], ['date', 'datetime'], true)) {
                $where[] = "{$col} {$sym} (now() at time zone 'America/Lima')::date";
            } elseif (in_array($f['type'], ['date', 'datetime'], true) ? (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) : is_numeric($value)) {
                $where[] = "{$col} {$sym} ?";
                $bind[] = $value;
            }

            return [$where, $bind];
        }
        if ($op !== null) {
            return [$where, $bind];
        }
        if ($f['type'] === 'enum') {
            $vals = array_values(array_intersect(explode(',', $value), array_column($f['options'], 'value')));
            if ($vals !== []) {
                $where[] = "{$col} in (".implode(',', array_fill(0, count($vals), '?')).')';
                array_push($bind, ...$vals);
            }
        } elseif ($f['type'] === 'ref' && Str::isUuid($value)) {
            $where[] = "{$col} = ?";
            $bind[] = $value;
        } elseif ($f['type'] === 'bool' && in_array($value, ['true', 'false', '1', '0'], true)) {
            $where[] = "{$col} = ?";
            $bind[] = in_array($value, ['true', '1'], true) ? 'true' : 'false';
        }

        return [$where, $bind];
    }

    private function present(array $res, object $row, bool $forList = false, bool $decrypt = false): array
    {
        $out = ['id' => $row->id, 'own' => $row->company_id !== null];
        if (property_exists($row, 'updated_at')) {
            $out['updated_at'] = $row->updated_at;
        }
        foreach ($res['fields'] as $f) {
            $c = $f['name'];
            if (! property_exists($row, $c)) {
                continue;
            }
            $v = $row->{$c};
            if (! empty($f['encrypted']) && $v !== null) {
                $v = $decrypt ? $this->decrypt($v) : '••••';
            } elseif (in_array($f['type'], ['json', 'tags'], true) && is_string($v)) {
                $v = json_decode($v, true);
            } elseif ($f['type'] === 'bool' && $v !== null) {
                $v = (bool) $v;
            } elseif (in_array($f['type'], ['int'], true) && $v !== null) {
                $v = (int) $v;
            } elseif ($f['type'] === 'number' && $v !== null) {
                $v = (float) $v;
            }
            // Ingresos antiguos con motivos médicos: fuera de la base médica se ven como «Requisito pendiente» (RN-ING-02).
            if ($c === 'deny_reasons' && $res['table'] === 'access_events' && is_array($v)
                && ! in_array(app(CompanyContext::class)->roleCode, ['occupational_doctor', 'health_staff'], true)) {
                $v = \App\Modules\ING\GateController::safeReasons($v);
            }
            $out[$c] = $v;
            if (property_exists($row, $c.'__label')) {
                $out[$c.'__label'] = $row->{$c.'__label'};
            }
        }

        return $out;
    }

    private function decrypt(string $v): ?string
    {
        try {
            return Crypt::decryptString($v);
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Escritura ─────────────────────────────────────────────────────────────

    /**
     * Valida la entrada contra el catálogo y la normaliza.
     *
     * @return array<string, mixed> solo los campos enviados (en edición) o todos los editables (en alta)
     */
    private function validate(array $res, array $input, bool $creating): array
    {
        $errors = [];
        $values = [];
        foreach ($res['fields'] as $f) {
            $c = $f['name'];
            if (! empty($f['readonly'])) {
                continue;
            }
            $present = array_key_exists($c, $input);
            // Lo que no se envía no se toca: en una alta, la base pone su valor por defecto.
            if (! $present && (! $creating || empty($f['required']))) {
                continue;
            }
            $v = $present ? $input[$c] : null;
            if (is_string($v)) {
                $v = trim($v);
                if ($v === '') {
                    $v = null;
                }
            }
            if ($v === null) {
                if (! empty($f['required']) && ($creating || $present)) {
                    $errors[$c][] = "Completa «{$f['label']}».";
                }
                if ($present || $creating) {
                    $values[$c] = null;
                }

                continue;
            }
            $msg = $this->checkType($f, $v);
            if ($msg !== null) {
                $errors[$c][] = $msg;

                continue;
            }
            $values[$c] = $v;
        }
        if ($creating) {
            // En alta, los campos opcionales sin valor no se envían: la base pone su valor por defecto.
            $values = array_filter($values, fn ($v, $c) => $v !== null || ! empty(Registry::field($res, $c)['required']), ARRAY_FILTER_USE_BOTH);
        }
        if ($errors !== []) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Hay campos por corregir.', ['errors' => $errors]);
        }

        // Referencias: deben existir y ser visibles para la empresa (RLS).
        foreach ($values as $c => &$v) {
            $f = Registry::field($res, $c);
            if ($f['type'] === 'ref' && $v !== null && ! $this->refExists($f['ref'], $v)) {
                $errors[$c][] = "El valor elegido en «{$f['label']}» no existe.";
            }
        }
        unset($v);
        if ($errors !== []) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Hay campos por corregir.', ['errors' => $errors]);
        }

        return $values;
    }

    private function checkType(array $f, mixed &$v): ?string
    {
        $label = $f['label'];
        switch ($f['type']) {
            case 'text':
            case 'longtext':
                if (! is_string($v) && ! is_numeric($v)) {
                    return "«{$label}» debe ser texto.";
                }
                $v = (string) $v;
                $max = $f['max'] ?? ($f['type'] === 'text' ? 500 : 20000);
                if (mb_strlen($v) > $max) {
                    return "«{$label}» admite hasta {$max} caracteres.";
                }

                return null;
            case 'int':
                if (filter_var($v, FILTER_VALIDATE_INT) === false) {
                    return "«{$label}» debe ser un número entero.";
                }
                $v = (int) $v;

                return $v < -2147483648 || $v > 2147483647 ? "«{$label}» está fuera de rango." : null;
            case 'number':
                if (! is_numeric($v)) {
                    return "«{$label}» debe ser un número.";
                }
                $v = (float) $v;

                return null;
            case 'bool':
                $b = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($b === null) {
                    return "«{$label}» debe ser sí o no.";
                }
                $v = $b;

                return null;
            case 'date':
                return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))
                    ? null : "«{$label}» debe ser una fecha válida.";
            case 'datetime':
                return is_string($v) && strtotime($v) !== false ? null : "«{$label}» debe ser una fecha y hora válida.";
            case 'time':
                return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $v) ? null : "«{$label}» debe ser una hora (HH:MM).";
            case 'enum':
                return in_array($v, array_column($f['options'], 'value'), true) ? null : "Elige una opción válida en «{$label}».";
            case 'file':
                return is_string($v) && Str::isUuid($v) && DB::selectOne("select 1 as ok from files where id = ? and deleted_at is null", [$v])
                    ? null : "El archivo de «{$label}» no existe; vuelve a subirlo.";
            case 'ref':
            case 'source':
                return is_string($v) && Str::isUuid($v) ? null : "Elige una opción válida en «{$label}».";
            case 'json':
                if (! is_array($v)) {
                    return "«{$label}» tiene un formato no válido.";
                }

                return strlen((string) json_encode($v)) > 20000 ? "«{$label}» es demasiado grande." : null;
            case 'tags':
                if (! is_array($v)) {
                    return "«{$label}» debe ser una lista.";
                }
                $v = array_values(array_filter(array_map(fn ($x) => is_scalar($x) ? trim((string) $x) : '', $v), fn ($x) => $x !== ''));
                if (in_array($f['item'] ?? 'text', ['integer', 'smallint'], true)) {
                    foreach ($v as $x) {
                        if (filter_var($x, FILTER_VALIDATE_INT) === false) {
                            return "«{$label}» solo admite números enteros.";
                        }
                    }
                }

                return count($v) > 200 ? "«{$label}» admite hasta 200 elementos." : null;
        }

        return "«{$label}» no se puede editar aquí.";
    }

    private function refExists(string $table, string $id): bool
    {
        if ($table === 'users') {
            return DB::selectOne('select 1 as ok from memberships where user_id = ? and company_id = ? and deleted_at is null',
                [$id, app(CompanyContext::class)->companyId]) !== null;
        }

        return DB::selectOne('select 1 as ok from "'.$table.'" where id = ?'.(Registry::softDelete($table) ? ' and deleted_at is null' : ''), [$id]) !== null;
    }

    /** Pares [columna, expresión SQL, valores] para insert/update. */
    private function writeParts(array $res, array $values, array $extra): array
    {
        $parts = [];
        foreach ($values as $c => $v) {
            $f = Registry::field($res, $c);
            if ($v === null) {
                $parts[] = [$c, 'null', []];

                continue;
            }
            if (! empty($f['encrypted'])) {
                $parts[] = [$c, '?', [Crypt::encryptString((string) $v)]];

                continue;
            }
            $parts[] = match ($f['type']) {
                'bool' => [$c, '?::boolean', [$v ? 'true' : 'false']],
                'json' => [$c, '?::jsonb', [json_encode($v, JSON_UNESCAPED_UNICODE)]],
                'tags' => [$c, "array(select json_array_elements_text(?::json))::{$f['item']}[]", [json_encode($v, JSON_UNESCAPED_UNICODE)]],
                default => [$c, '?', [$v]],
            };
        }
        foreach ($extra as $c => [$expr, $bind]) {
            $parts[] = [$c, $expr, $bind];
        }

        return $parts;
    }

    /** Unicidad (código, nombre…) con un mensaje claro antes de que lo rechace la base. */
    private function checkUniques(array $res, array $values, ?object $current): void
    {
        foreach ($res['uniques'] as $cols) {
            $fields = array_map(fn ($c) => Registry::field($res, $c), $cols);
            // Los únicos con estado o sí/no suelen ser índices parciales (p. ej. «una sola vigente»): los valida la base.
            if (in_array(null, $fields, true) || array_filter($fields, fn ($f) => in_array($f['type'], ['bool', 'json', 'tags'], true) || ! empty($f['encrypted']) || $f['name'] === 'status')) {
                continue;
            }
            if (! array_filter($cols, fn ($c) => array_key_exists($c, $values))) {
                continue;
            }
            // Índice parcial con otra condición (p. ej. «una sola versión vigente», «una asignación abierta»): lo valida la base.
            if ($this->isPartialUnique($res['table'], $cols)) {
                continue;
            }
            $where = [];
            $bind = [];
            foreach ($cols as $c) {
                $v = array_key_exists($c, $values) ? $values[$c] : ($current?->{$c} ?? null);
                if ($v === null) {
                    continue 2;
                }
                $where[] = "\"{$c}\" = ?";
                $bind[] = $v;
            }
            $sql = 'select 1 as ok from "'.$res['table'].'" where '.implode(' and ', $where);
            if ($res['soft_delete']) {
                $sql .= ' and deleted_at is null';
            }
            if ($res['hybrid']) {
                $sql .= ' and company_id is not null';
            }
            if ($current) {
                $sql .= ' and id <> ?';
                $bind[] = $current->id;
            }
            if (DB::selectOne($sql, $bind)) {
                $last = end($fields);
                $names = implode(' y ', array_map(fn ($f) => '«'.$f['label'].'»', $fields));
                throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', "Ya existe un registro con ese mismo {$names}.",
                    ['errors' => [$last['name'] => ["Ya existe un registro con ese mismo {$names}."]]]);
            }
        }
    }

    /** ¿El único de esas columnas es un índice parcial con una condición distinta de «no archivado»? */
    private function isPartialUnique(string $table, array $cols): bool
    {
        static $cache = [];
        $cache[$table] ??= DB::select(
            "select array_to_string(array(select a.attname from unnest(i.indkey) k join pg_attribute a on a.attrelid = i.indrelid and a.attnum = k where a.attname <> 'company_id' order by a.attname), ',') as cols,
                    coalesce(pg_get_expr(i.indpred, i.indrelid), '') as pred
               from pg_index i where i.indisunique and i.indrelid = ?::regclass",
            ['public.'.$table]
        );
        $want = $cols;
        sort($want);
        foreach ($cache[$table] as $ix) {
            if ($ix->cols === implode(',', $want)) {
                $pred = trim(preg_replace('/\(?\s*deleted_at IS NULL\s*\)?/i', '', $ix->pred), " ()\t\n");
                if ($pred !== '' && ! preg_match('/^(AND\s*)?$/i', $pred)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Traduce los rechazos de la base (reglas, únicos, referencias) a mensajes claros. */
    private function guard(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (QueryException $e) {
            $state = $e->errorInfo[0] ?? $e->getCode();
            $detail = match ($state) {
                '23505' => 'Ya existe un registro con esos mismos datos (por ejemplo, el mismo código).',
                '23514' => 'Algunos datos no cumplen las reglas del registro (por ejemplo, fechas en orden o valores obligatorios según el tipo).',
                '23503' => 'Uno de los datos relacionados no existe o está en uso por otros registros.',
                '23502' => 'Falta completar un dato obligatorio.',
                '22P02', '22007', '22008', '22003' => 'Hay un valor con formato no válido.',
                'P0001' => trim(preg_replace('/^.*ERROR:\s*|\(Connection.*$|CONTEXT:.*$/s', '', $e->getMessage())) ?: 'La operación no está permitida.',
                default => null,
            };
            if ($detail === null) {
                throw $e;
            }
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $detail);
        }
    }

    // ── Auditoría ─────────────────────────────────────────────────────────────

    private function auditable(array $res, array $values): array
    {
        $out = [];
        foreach ($values as $c => $v) {
            $out[$c] = ! empty(Registry::field($res, $c)['encrypted']) ? '[cifrado]' : $v;
        }

        return $out;
    }

    private function diff(array $res, array $values, object $current): array
    {
        $out = [];
        foreach ($values as $c => $v) {
            $f = Registry::field($res, $c);
            $old = $current->{$c} ?? null;
            if (! empty($f['encrypted'])) {
                $out[$c] = '[cifrado]';

                continue;
            }
            if (in_array($f['type'], ['json', 'tags'], true) && is_string($old)) {
                $old = json_decode($old, true);
            }
            $norm = fn ($x) => match (true) {
                $x === null => null,
                is_bool($x) => $x ? '1' : '0',
                is_array($x) => json_encode($x, JSON_UNESCAPED_UNICODE),
                is_float($x) || is_int($x) || is_numeric($x) => (string) (0 + $x),
                default => (string) $x,
            };
            if ($norm($f['type'] === 'bool' && $old !== null ? (bool) $old : $old) !== $norm($v)) {
                $out[$c] = ['from' => $old, 'to' => $v];
            }
        }

        return $out;
    }

    private function audit(array $res, string $action, string $id, array $changes): void
    {
        $ctx = app(CompanyContext::class);
        DB::insert(
            "insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes, ip, user_agent)
             values (?, ?, ?, 'user', ?, ?, ?, ?, ?::jsonb, ?::inet, ?)",
            [$ctx->companyId, (string) auth()->id(), $ctx->roleCode, $action, $res['module'], $res['table'], $id,
                json_encode($changes, JSON_UNESCAPED_UNICODE), request()->ip(), mb_substr((string) request()->userAgent(), 0, 300) ?: null]
        );
    }
}
