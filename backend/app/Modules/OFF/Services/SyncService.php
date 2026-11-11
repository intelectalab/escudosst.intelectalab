<?php

namespace App\Modules\OFF\Services;

use App\Core\Http\ApiProblem;
use App\Core\Http\Middleware\FeatureGate;
use App\Core\Support\LegalParams;
use App\Core\Tenancy\CompanyContext;
use App\Modules\INS\Services\InspectionService;
use App\Modules\REC\RecordsService;
use App\Modules\REC\Registry;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sincronización de la app offline (M-OFF, docs/16 §4-§6).
 *
 * Cada operación lleva su idempotency_key: una clave ya procesada devuelve el resultado guardado (sync_operations).
 * Creaciones y registros solo-anexar se aplican con las mismas validaciones, permisos y plan que en línea
 * (motor de registros). Las ediciones llevan base_version: si el registro cambió o se borró en el servidor, se
 * registra un sync_conflicts y no se pisa nada. Se guardan la hora del dispositivo y la del servidor; si el reloj
 * del dispositivo va adelantado más que la tolerancia, la operación queda marcada para revisión.
 */
class SyncService
{
    /** Registros de campo que se pueden capturar sin señal (docs/16 §2), con su módulo. */
    public const ENTITIES = [
        'inspections' => 'INS',
        'inspection_answers' => 'INS',
        'substandard_reports' => 'ACT',
        'safety_talks' => 'CH5',
        'talk_attendances' => 'CH5',
        'ats_forms' => 'ATS',
        'ats_steps' => 'ATS',
        'ats_participants' => 'ATS',
        'ppe_deliveries' => 'EPP',
        'training_attendances' => 'CAP',
        'drill_participants' => 'SIM',
    ];

    /** Asistencias y participantes: la misma persona dos veces en el mismo registro se marca para revisión. */
    private const APPEND_ONLY = [
        'talk_attendances' => 'safety_talk_id',
        'training_attendances' => 'session_id',
        'ats_participants' => 'ats_form_id',
        'drill_participants' => 'drill_id',
    ];

    public function __construct(private RecordsService $records, private InspectionService $inspections) {}

    /** Dispositivo del usuario en la empresa activa: lo registra la primera vez; uno revocado no sincroniza. */
    public function device(array $input, bool $mayRegister): object
    {
        $uid = trim((string) ($input['device_uid'] ?? ''));
        if ($uid === '' || mb_strlen($uid) > 100) {
            throw $this->invalid('device_uid', 'Falta el identificador del dispositivo.');
        }
        $ctx = app(CompanyContext::class);
        $device = DB::selectOne('select * from devices where device_uid = ? and deleted_at is null', [$uid]);
        if ($device && $device->user_id !== (string) auth()->id()) {
            throw ApiProblem::forbidden('Este dispositivo está registrado a otra persona. Pide al responsable SST que lo revise.');
        }
        if ($device && $device->status !== 'active') {
            throw new ApiProblem(403, 'device_revoked', 'Dispositivo revocado',
                'Este dispositivo fue revocado: los datos guardados en él se borran. Vuelve a ingresar desde un dispositivo autorizado.',
                ['wipe' => true]);
        }
        if (! $device) {
            if (! $mayRegister) {
                throw ApiProblem::forbidden('Tu rol no puede registrar dispositivos para trabajar sin conexión.');
            }
            $platform = in_array($input['platform'] ?? null, ['android', 'ios', 'web_pwa', 'windows', 'other'], true) ? $input['platform'] : 'web_pwa';
            $device = DB::selectOne(
                'insert into devices (company_id, user_id, device_uid, platform, model, app_version, created_by, updated_by)
                 values (?, ?, ?, ?, ?, ?, ?, ?) returning *',
                [$ctx->companyId, (string) auth()->id(), $uid, $platform, $this->text($input['model'] ?? null, 120),
                    $this->text($input['app_version'] ?? null, 40), (string) auth()->id(), (string) auth()->id()]
            );
        }

        return $device;
    }

    /** Estado para el indicador de la app: dispositivo, última sincronización y conflictos abiertos del usuario. */
    public function status(object $device): array
    {
        $open = (int) DB::selectOne(
            "select count(*) as n from sync_conflicts c join sync_batches b on b.id = c.sync_batch_id
              where c.resolution = 'pending' and c.deleted_at is null and b.user_id = ?", [(string) auth()->id()])->n;

        return [
            'device' => ['id' => $device->id, 'status' => $device->status, 'last_sync_at' => $device->last_sync_at],
            'wipe' => false,
            'open_conflicts' => $open,
            'entities' => array_keys(self::ENTITIES),
            'max_operations' => $this->maxOperations(),
        ];
    }

    /** Lote: cada operación se procesa por separado (una que falla no detiene a las demás). */
    public function batch(object $device, array $input): array
    {
        $ops = $input['operations'] ?? null;
        if (! is_array($ops) || $ops === [] || ! array_is_list($ops)) {
            throw $this->invalid('operations', 'El lote no trae operaciones.');
        }
        if (count($ops) > $this->maxOperations()) {
            throw $this->invalid('operations', 'El lote trae demasiadas operaciones: envíalas en partes de hasta '.$this->maxOperations().'.');
        }
        $batchUuid = (string) ($input['batch_uuid'] ?? '');
        if (! Str::isUuid($batchUuid)) {
            throw $this->invalid('batch_uuid', 'Falta el identificador del lote.');
        }

        $ctx = app(CompanyContext::class);
        $user = (string) auth()->id();
        $batch = DB::selectOne('select * from sync_batches where batch_uuid = ?', [$batchUuid]);
        if (! $batch) {
            $batch = DB::selectOne(
                "insert into sync_batches (company_id, device_id, user_id, batch_uuid, idempotency_key, client_created_at, app_version,
                    payload_sha256, operations_count, status, created_by, updated_by)
                 values (?, ?, ?, ?, ?, ?, ?, decode(?, 'hex'), ?, 'processing', ?, ?) returning *",
                [$ctx->companyId, $device->id, $user, $batchUuid, $batchUuid, $this->time($input['client_created_at'] ?? null),
                    $this->text($input['app_version'] ?? null, 40), hash('sha256', json_encode($ops)), count($ops), $user, $user]
            );
        } elseif ($batch->device_id !== $device->id) {
            throw ApiProblem::forbidden('Ese lote es de otro dispositivo.');
        }

        $results = [];
        foreach ($ops as $op) {
            $results[] = $this->operation($batch, $device, is_array($op) ? $op : []);
        }

        $count = fn (string $r) => count(array_filter($results, fn ($x) => $x['result'] === $r));
        $applied = $count('applied');
        $conflicts = $count('conflict');
        $rejected = $count('rejected');
        $status = $rejected === count($results) ? 'rejected' : (($conflicts + $rejected) > 0 ? 'partially_applied' : 'applied');
        DB::update('update sync_batches set applied_count = ?, conflict_count = ?, rejected_count = ?, status = ?, processed_at = now(),
                        operations_count = greatest(operations_count, ?), updated_by = ? where id = ?',
            [$applied, $conflicts, $rejected, $status, count($results), $user, $batch->id]);
        DB::update('update devices set last_sync_at = now(), updated_by = ? where id = ?', [$user, $device->id]);

        return [
            'batch_id' => $batch->id,
            'status' => $status,
            'applied' => $applied,
            'conflicts' => $conflicts,
            'rejected' => $rejected,
            'results' => $results,
        ];
    }

    /** Resolución de un conflicto (SCR-OFF-04): conservar el servidor, aplicar el dispositivo, combinar o descartar. */
    public function resolve(string $id, array $input, bool $all): array
    {
        $c = Str::isUuid($id) ? DB::selectOne('select c.*, b.user_id as batch_user_id from sync_conflicts c join sync_batches b on b.id = c.sync_batch_id
                                                 where c.id = ? and c.deleted_at is null for update of c', [$id]) : null;
        if ($c && ! $all && $c->batch_user_id !== (string) auth()->id()) {
            throw ApiProblem::forbidden('Este conflicto es de otra persona: lo resuelve el responsable SST.');
        }
        if (! $c) {
            throw ApiProblem::notFound('El conflicto no existe o es de otra empresa.');
        }
        if ($c->resolution !== 'pending') {
            throw new ApiProblem(409, 'conflict', 'Conflicto ya resuelto', 'Este conflicto ya fue resuelto.');
        }
        $resolution = $input['resolution'] ?? null;
        if (! in_array($resolution, ['server_wins', 'client_wins', 'merged', 'discarded'], true)) {
            throw $this->invalid('resolution', 'Elige qué versión conservar.');
        }
        $notes = $this->text($input['notes'] ?? null, 1000);
        $applyPayload = match ($resolution) {
            'client_wins' => json_decode($c->client_payload, true)['payload'] ?? [],
            'merged' => is_array($input['payload'] ?? null) ? $input['payload'] : throw $this->invalid('payload', 'Indica los valores combinados.'),
            default => null,
        };

        if ($applyPayload !== null) {
            $module = self::ENTITIES[$c->entity_type] ?? null;
            if (! $module || $c->operation !== 'update' || ! $c->entity_id || $c->entity_type === 'inspection_answers') {
                throw new ApiProblem(422, 'validation_failed', 'No se puede aplicar',
                    'Este conflicto no es una edición: conserva el valor del servidor o descártalo.');
            }
            $res = Registry::resource($module, $c->entity_type);
            app(FeatureGate::class)->handle(request(), fn () => new Response, $module);
            $this->records->authorize($res, 'update');
            $this->records->update($res, $c->entity_id, $applyPayload);
        }

        DB::update('update sync_conflicts set resolution = ?, resolved_by = ?, resolved_at = now(), resolution_notes = ?, updated_by = ? where id = ?',
            [$resolution, (string) auth()->id(), $notes, (string) auth()->id(), $c->id]);
        $ctx = app(CompanyContext::class);
        DB::insert(
            "insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes)
             values (?, ?, ?, 'user', 'update', 'OFF', 'sync_conflicts', ?, ?::jsonb)",
            [$ctx->companyId, (string) auth()->id(), $ctx->roleCode, $c->id,
                json_encode(['resolution' => $resolution, 'entity_type' => $c->entity_type, 'entity_id' => $c->entity_id, 'notes' => $notes], JSON_UNESCAPED_UNICODE)]
        );

        return ['id' => $c->id, 'resolution' => $resolution];
    }

    /** Conflictos abiertos de los lotes del usuario (o de toda la empresa para quien puede resolverlos todos). */
    public function conflicts(bool $all): array
    {
        $rows = DB::select(
            "select c.id, c.entity_type, c.entity_id, c.operation, c.conflict_type, c.client_version, c.server_version,
                    c.client_payload, c.server_snapshot, c.created_at, u.full_name as user_name
               from sync_conflicts c join sync_batches b on b.id = c.sync_batch_id left join users u on u.id = b.user_id
              where c.resolution = 'pending' and c.deleted_at is null".($all ? '' : ' and b.user_id = ?').'
              order by c.created_at desc limit 100', $all ? [] : [(string) auth()->id()]);

        return array_map(fn ($r) => [
            'id' => $r->id, 'entity_type' => $r->entity_type, 'entity_id' => $r->entity_id, 'operation' => $r->operation,
            'conflict_type' => $r->conflict_type, 'client_version' => $r->client_version, 'server_version' => $r->server_version,
            'client_payload' => json_decode($r->client_payload, true)['payload'] ?? null,
            'server_snapshot' => $r->server_snapshot ? json_decode($r->server_snapshot, true) : null,
            'created_at' => $r->created_at, 'user_name' => $r->user_name,
        ], $rows);
    }

    // ── Una operación ─────────────────────────────────────────────────────────

    private function operation(object $batch, object $device, array $op): array
    {
        $key = (string) ($op['idempotency_key'] ?? '');
        $kind = $op['op'] ?? null;
        $entity = (string) ($op['entity'] ?? '');
        if (mb_strlen($key) < 8 || mb_strlen($key) > 80) {
            return ['idempotency_key' => $key, 'result' => 'rejected', 'reason' => 'La operación no trae una clave válida.'];
        }

        $prev = DB::selectOne('select * from sync_operations where idempotency_key = ?', [$key]);
        if ($prev) {
            return $this->present($prev) + ['replay' => true];
        }

        $clientId = isset($op['entity_id']) && Str::isUuid((string) $op['entity_id']) ? (string) $op['entity_id'] : null;
        $captured = $this->time($op['created_at_device'] ?? null);
        $base = [$batch, $device, $key, $entity, in_array($kind, ['create', 'update'], true) ? $kind : 'create', $clientId, $captured];

        if (! isset(self::ENTITIES[$entity]) || ! in_array($kind, ['create', 'update'], true)) {
            return $this->store($base, 'rejected', null, 'Este registro no se puede capturar sin conexión.');
        }
        $payload = is_array($op['payload'] ?? null) ? $this->translate($op['payload']) : [];
        $module = self::ENTITIES[$entity];

        try {
            return DB::transaction(function () use ($base, $entity, $module, $kind, $payload, $clientId, $op, $captured, $batch, $device) {
                // Plan y permisos de hoy (si se retiraron, se rechaza con motivo y queda en el dispositivo).
                app(FeatureGate::class)->handle(request(), fn () => new Response, $module);
                $res = $entity === 'inspection_answers' ? Registry::resource('INS', 'inspections') : Registry::resource($module, $entity);
                $this->records->authorize($res, $entity === 'inspection_answers' ? 'update' : $kind);

                if ($kind === 'update') {
                    return $this->update($base, $res, $this->translateId($clientId), $op['base_version'] ?? null, $payload, $op);
                }
                if ($entity === 'inspection_answers') {
                    return $this->answer($base, $payload, $op);
                }
                if (isset(self::APPEND_ONLY[$entity]) && $this->duplicate($entity, $payload)) {
                    return $this->conflict($base, null, 'duplicate', null, null, $op, null);
                }
                $row = $this->records->create($res, $payload);
                $this->stamp($entity, $row['id'], $base[2], $batch->id, $device->id, $captured);

                return $this->store($base, 'applied', $row['id']);
            });
        } catch (ApiProblem $e) {
            $reason = $e->detail ?? $e->title;
            if ($e->status === 422 && ! empty($e->extra['errors'])) {
                $reason .= ' '.implode(' ', array_map(fn ($m) => is_array($m) ? implode(' ', $m) : (string) $m, $e->extra['errors']));
            }
            if ($e->status === 409) {
                return $this->conflict($base, $clientId, 'state_transition', $op['base_version'] ?? null, null, $op, null);
            }

            return $this->store($base, 'rejected', null, trim($reason));
        }
    }

    private function update(array $base, array $res, ?string $id, mixed $baseVersion, array $payload, array $op): array
    {
        if (! $id) {
            return $this->store($base, 'rejected', null, 'Falta el registro a editar.');
        }
        $current = DB::selectOne('select id, version, deleted_at from "'.$res['table'].'" where id = ? for update', [$id]);
        if (! $current || $current->deleted_at !== null) {
            return $this->conflict($base, $id, 'deleted_on_server', $baseVersion, $current?->version, $op, null);
        }
        if (! is_numeric($baseVersion) || (int) $baseVersion !== (int) $current->version) {
            return $this->conflict($base, $id, 'version_mismatch', $baseVersion, (int) $current->version, $op, $this->records->find($res, $id));
        }
        $this->records->update($res, $id, $payload);

        return $this->store($base, 'applied', $id);
    }

    /** Respuesta de una inspección (solo-anexar): {inspection_id, item_id, answer, comment}. */
    private function answer(array $base, array $payload, array $op): array
    {
        $inspection = (string) ($payload['inspection_id'] ?? '');
        $item = (string) ($payload['item_id'] ?? '');
        if (! Str::isUuid($inspection) || ! Str::isUuid($item)) {
            return $this->store($base, 'rejected', null, 'Falta la inspección o el ítem de la respuesta.');
        }
        $this->inspections->answer($inspection, [['item_id' => $item, 'answer' => $payload['answer'] ?? null, 'comment' => $payload['comment'] ?? null]]);
        $row = DB::selectOne('select id from inspection_answers where inspection_id = ? and template_item_id = ? and deleted_at is null', [$inspection, $item]);
        DB::update('update inspection_answers set sync_batch_id = ?, device_id = ?, client_created_at = coalesce(client_created_at, ?) where id = ?',
            [$base[0]->id, $base[1]->id, $base[6], $row->id]);

        return $this->store($base, 'applied', $row->id);
    }

    private function duplicate(string $entity, array $payload): bool
    {
        $parent = self::APPEND_ONLY[$entity];
        foreach (['worker_id', 'contractor_worker_id'] as $person) {
            if (! empty($payload[$parent]) && ! empty($payload[$person]) && Str::isUuid((string) $payload[$parent]) && Str::isUuid((string) $payload[$person])) {
                $n = DB::selectOne("select count(*) as n from \"{$entity}\" where \"{$parent}\" = ? and \"{$person}\" = ? and deleted_at is null",
                    [$payload[$parent], $payload[$person]])->n;
                if ($n > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function conflict(array $base, ?string $entityId, string $type, mixed $clientVersion, ?int $serverVersion, array $op, ?array $snapshot): array
    {
        [$batch, , $key, $entity, $kind] = $base;
        DB::insert(
            'insert into sync_conflicts (company_id, sync_batch_id, operation_idempotency_key, entity_type, entity_id, operation, client_version,
                server_version, client_payload, server_snapshot, conflict_type, created_by, updated_by)
             values (?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?, ?, ?)',
            [app(CompanyContext::class)->companyId, $batch->id, $key, $entity, $entityId, $kind, is_numeric($clientVersion) ? (int) $clientVersion : null,
                $serverVersion, json_encode(['payload' => $op['payload'] ?? null, 'base_version' => $op['base_version'] ?? null], JSON_UNESCAPED_UNICODE),
                $snapshot === null ? null : json_encode($snapshot, JSON_UNESCAPED_UNICODE), $type, (string) auth()->id(), (string) auth()->id()]
        );
        $reason = match ($type) {
            'duplicate' => 'Esta persona ya estaba registrada: queda para revisión.',
            'deleted_on_server' => 'El registro fue archivado en el servidor: queda para revisión.',
            'state_transition' => 'El registro cambió de estado en el servidor: queda para revisión.',
            default => 'El registro también cambió en el servidor: queda para revisión, no se pisó ninguna versión.',
        };

        return $this->store($base, 'conflict', $entityId, $reason, $type);
    }

    /** Guarda el resultado por idempotency_key (para responder igual a un reenvío) y lo devuelve. */
    private function store(array $base, string $result, ?string $entityId, ?string $reason = null, ?string $conflictType = null): array
    {
        [$batch, $device, $key, $entity, $kind, $clientId, $captured] = $base;
        $review = $captured !== null && strtotime($captured) > time() + (int) (LegalParams::number('clock_skew_tolerance_minutes', 10) * 60);
        $row = DB::selectOne(
            'insert into sync_operations (company_id, sync_batch_id, device_id, idempotency_key, entity_type, operation, client_entity_id, entity_id,
                result, reason, needs_review, captured_at)
             values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) returning *',
            [app(CompanyContext::class)->companyId, $batch->id, $device->id, $key, mb_substr($entity, 0, 80), $kind, $clientId, $entityId,
                $result, $conflictType ? $conflictType.': '.$reason : $reason, $review ? 'true' : 'false', $captured]
        );

        return $this->present($row);
    }

    private function present(object $row): array
    {
        $reason = $row->reason;
        $type = null;
        if ($row->result === 'conflict' && $reason && preg_match('/^([a-z_]+): (.*)$/s', $reason, $m)) {
            [$type, $reason] = [$m[1], $m[2]];
        }
        $version = null;
        if ($row->result === 'applied' && $row->entity_id && isset(self::ENTITIES[$row->entity_type])) {
            $version = DB::selectOne('select version from "'.$row->entity_type.'" where id = ?', [$row->entity_id])?->version;
        }

        return array_filter([
            'idempotency_key' => $row->idempotency_key,
            'result' => $row->result,
            'entity' => $row->entity_type,
            'client_entity_id' => $row->client_entity_id,
            'entity_id' => $row->entity_id,
            'version' => $version,
            'conflict_type' => $type,
            'reason' => $reason,
            'needs_review' => (bool) $row->needs_review ?: null,
        ], fn ($v) => $v !== null);
    }

    /** Ids generados en el dispositivo → ids del servidor (registros creados en lotes anteriores o en este). */
    private function translate(array $payload): array
    {
        foreach ($payload as $k => $v) {
            if (is_string($v) && Str::isUuid($v)) {
                $payload[$k] = $this->translateId($v);
            }
        }

        return $payload;
    }

    private function translateId(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }
        $hit = DB::selectOne("select entity_id from sync_operations where client_entity_id = ? and operation = 'create' and entity_id is not null limit 1", [$id]);

        return $hit->entity_id ?? $id;
    }

    private function stamp(string $table, string $id, string $key, string $batchId, string $deviceId, ?string $captured): void
    {
        DB::update("update \"{$table}\" set idempotency_key = ?, sync_batch_id = ?, device_id = ?, client_created_at = ? where id = ?",
            [$key, $batchId, $deviceId, $captured, $id]);
    }

    private function maxOperations(): int
    {
        return max(1, (int) LegalParams::number('offline_batch_max_operations', 100));
    }

    private function time(mixed $v): ?string
    {
        if (! is_string($v) || $v === '') {
            return null;
        }
        $t = strtotime($v);

        return $t === false ? null : date(DATE_ATOM, $t);
    }

    private function text(mixed $v, int $max): ?string
    {
        $v = is_string($v) ? trim($v) : null;

        return $v === null || $v === '' ? null : mb_substr($v, 0, $max);
    }

    private function invalid(string $field, string $msg): ApiProblem
    {
        return new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}
