<?php

namespace App\Modules\OFF\Services;

use App\Core\Auth\Permissions;
use App\Core\Auth\Scope;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GET /sync/changes?since=<marca>&device_id=... (docs/08 §13, docs/16 §3): cambios del servidor desde la marca para lo
 * que el dispositivo guarda (los mismos catálogos del paquete sin conexión y los registros de la persona), con lápidas
 * («delete») para lo borrado o lo que ya no corresponde guardar, next_since y has_more.
 *
 * La marca es opaca: instante del último cambio entregado con su desempate (entidad e id). Sin «since» se entrega todo lo
 * vigente (sin lápidas). Al terminar, next_since queda un minuto antes de la hora del servidor para no perder cambios de
 * transacciones que aún no habían terminado (repetir un cambio no daña: el dispositivo lo reemplaza).
 * RN-OFF-05: nunca datos médicos; de la aptitud solo el estado del fotocheck.
 */
class ChangeFeed
{
    public const MAX_LIMIT = 500;

    /** Registros de campo propios (creados por la persona) cuyo estado y versión se guardan en el equipo. */
    private const OWN_RECORDS = ['inspections', 'substandard_reports', 'safety_talks', 'ats_forms', 'ppe_deliveries', 'sst_events'];

    /** Estados del permiso que se trabajan en campo (docs/16 §2); los demás salen del equipo. */
    private const PERMIT_FIELD = ['approved', 'active', 'suspended'];

    /** @return array{changes: list<array>, next_since: string, has_more: bool} */
    public function changes(?string $since, int $limit): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $cursor = $this->cursor($since);
        $now = DB::selectOne("select (now() - interval '60 seconds')::text as t")->t;
        $rows = [];
        foreach ($this->sources() as $entity => $src) {
            [$cond, $bind] = $this->after($entity, $cursor);
            foreach (DB::select("select * from ({$src['sql']}) x where {$cond} order by x.ts, x.id limit ".($limit + 1), array_merge($src['bind'], $bind)) as $r) {
                $rows[] = ['entity' => $entity, 'row' => $r];
            }
        }
        usort($rows, fn ($a, $b) => [$this->micro($a['row']->ts), $a['entity'], $a['row']->id] <=> [$this->micro($b['row']->ts), $b['entity'], $b['row']->id]);
        $hasMore = count($rows) > $limit;
        $page = array_slice($rows, 0, $limit);
        $changes = array_map(fn ($x) => $this->present($x['entity'], $x['row']), $page);
        $last = end($page);
        $next = $hasMore && $last ? $this->encode($last['row']->ts, $last['entity'], $last['row']->id) : $this->encode($now, '', '');

        // Sin marca (descarga inicial) no hay lápidas que entregar.
        return ['changes' => $cursor === null ? array_values(array_filter($changes, fn ($c) => $c['op'] === 'upsert')) : $changes,
            'next_since' => $next, 'has_more' => $hasMore];
    }

    /** Consultas por entidad: id, ts (instante del cambio, en texto), dead (lápida) y los datos que guarda el equipo. @return array<string, array{sql: string, bind: list<mixed>}> */
    private function sources(): array
    {
        $me = (string) auth()->id();
        $mine = Permissions::effective();
        $workerScope = Scope::andSql('w.current_site_id', 'w.current_area_id', true);
        $out = [
            'workers' => ['sql' => "select w.id, w.updated_at::text as ts, (w.deleted_at is not null or w.status not in ('active', 'on_leave')) as dead,
                                           jsonb_build_object('id', w.id, 'full_name', w.full_name, 'document_last4', w.document_last4, 'status', w.status,
                                             'site_id', w.current_site_id, 'area_id', w.current_area_id, 'position_id', w.current_position_id) as data
                                      from workers w where true{$workerScope}", 'bind' => []],
            'sites' => ['sql' => "select s.id, s.updated_at::text as ts, (s.deleted_at is not null or s.status <> 'active') as dead, jsonb_build_object('id', s.id, 'name', s.name) as data
                                    from sites s where true".Scope::andTable('sites', 's'), 'bind' => []],
            // La plantilla cambia también cuando cambia uno de sus ítems: va completa con sus ítems.
            'inspection_templates' => ['sql' => "select t.id, greatest(t.updated_at, coalesce((select max(i.updated_at) from inspection_template_items i where i.template_id = t.id), t.updated_at))::text as ts,
                                                   (t.deleted_at is not null or t.status <> 'active') as dead,
                                                   jsonb_build_object('id', t.id, 'code', t.code, 'name', t.name, 'inspection_type', t.inspection_type, 'version_number', t.version_number,
                                                     'items', coalesce((select jsonb_agg(jsonb_build_object('template_id', i.template_id, 'section', i.section, 'item_code', i.item_code, 'question', i.question,
                                                         'answer_type', i.answer_type, 'is_critical', i.is_critical, 'requires_photo_on_fail', i.requires_photo_on_fail) order by i.sort_order)
                                                       from inspection_template_items i where i.template_id = t.id and i.deleted_at is null), '[]'::jsonb)) as data
                                              from inspection_templates t", 'bind' => []],
            'ppe_items' => ['sql' => "select p.id, p.updated_at::text as ts, (p.deleted_at is not null or not coalesce(p.is_active, true)) as dead,
                                        jsonb_build_object('id', p.id, 'code', p.code, 'name', p.name, 'category', p.category, 'unit', p.unit) as data from ppe_items p", 'bind' => []],
            'credentials' => ['sql' => "select c.id, c.updated_at::text as ts, c.deleted_at is not null as dead,
                                          jsonb_build_object('id', c.id, 'worker_id', c.worker_id, 'credential_number', c.credential_number, 'status', c.status, 'expires_on', c.expires_on,
                                            'blocking_reasons', c.blocking_reasons) as data
                                     from credentials c join workers w on w.id = c.worker_id where true{$workerScope}", 'bind' => []],
        ];
        foreach (self::OWN_RECORDS as $t) {
            $cols = array_column(DB::select("select column_name from information_schema.columns where table_schema = 'public' and table_name = ?", [$t]), 'column_name');
            $extra = implode(', ', array_map(fn ($c) => "'{$c}', r.\"{$c}\"", array_intersect(['code', 'status', 'title', 'topic', 'description'], $cols)));
            $out[$t] = ['sql' => "select r.id, r.updated_at::text as ts, r.deleted_at is not null as dead,
                                    jsonb_build_object('id', r.id, 'version', r.version".($extra ? ", {$extra}" : '').') as data
                               from "'.$t.'" r where r.created_by = ?', 'bind' => [$me]];
        }
        // PETAR en campo (docs/16 §2): los permisos en curso del alcance (o en los que participa, si es trabajador) con lo que
        // muestra la vista de campo; los cerrados, vencidos o rechazados salen del equipo.
        if (in_array('ptr.work_permits.read', $mine, true)) {
            $worker = app(CompanyContext::class)->roleCode === 'worker';
            $where = $worker
                ? " and (w.requested_by = ? or exists (select 1 from workers me where me.user_id = ? and me.deleted_at is null and (me.id = w.executor_leader_worker_id
                       or exists (select 1 from jsonb_array_elements(case when jsonb_typeof(w.crew) = 'array' then w.crew else '[]'::jsonb end) c where c->>'worker_id' = me.id::text))))"
                : Scope::andTable('work_permits', 'w');
            $out['work_permits'] = ['sql' => "select w.id, w.updated_at::text as ts, (w.deleted_at is not null or w.status <> all(?::text[])) as dead,
                                               jsonb_build_object('id', w.id, 'version', w.version, 'code', w.code, 'status', w.status, 'work_description', w.work_description,
                                                 'location_text', w.location_text, 'valid_from', w.valid_from, 'valid_until', w.valid_until, 'suspended_reason', w.suspended_reason,
                                                 'site_id', w.site_id, 'site_id__label', s.name, 'permit_type_id', w.permit_type_id, 'permit_type_id__label', t.name,
                                                 'requires_gas_test', coalesce(t.requires_gas_test, false), 'gas_measurements', w.gas_measurements, 'area_left_safe', w.area_left_safe,
                                                 'requested_by', w.requested_by, 'executor_leader_worker_id', w.executor_leader_worker_id,
                                                 'crew', coalesce((select jsonb_agg(jsonb_build_object('worker_id', c->>'worker_id', 'name', c->>'name', 'role', c->>'role'))
                                                                     from jsonb_array_elements(case when jsonb_typeof(w.crew) = 'array' then w.crew else '[]'::jsonb end) c), '[]'::jsonb)) as data
                                          from work_permits w left join sites s on s.id = w.site_id left join permit_types t on t.id = w.permit_type_id
                                         where true{$where}", 'bind' => $worker ? ['{'.implode(',', self::PERMIT_FIELD).'}', $me, $me] : ['{'.implode(',', self::PERMIT_FIELD).'}']];
        }

        return $out;
    }

    private function present(string $entity, object $r): array
    {
        if ($r->dead) {
            return ['entity' => $entity, 'id' => $r->id, 'op' => 'delete', 'ts' => $r->ts];
        }

        return ['entity' => $entity, 'id' => $r->id, 'op' => 'upsert', 'ts' => $r->ts, 'data' => json_decode($r->data, true)];
    }

    /** Filas posteriores a la marca para una entidad (desempate por entidad e id con el mismo instante). @return array{0: string, 1: list<mixed>} */
    private function after(string $entity, ?array $c): array
    {
        if ($c === null) {
            return ['not x.dead', []];
        }
        if ($c['e'] === '' || strcmp($entity, $c['e']) < 0) {
            return ['x.ts::timestamptz > ?::timestamptz', [$c['t']]];
        }
        if (strcmp($entity, $c['e']) > 0) {
            return ['x.ts::timestamptz >= ?::timestamptz', [$c['t']]];
        }

        return ['(x.ts::timestamptz > ?::timestamptz or (x.ts::timestamptz = ?::timestamptz and x.id > ?::uuid))', [$c['t'], $c['t'], $c['i']]];
    }

    /** @return array{t: string, e: string, i: string}|null */
    private function cursor(?string $since): ?array
    {
        if ($since === null || trim($since) === '') {
            return null;
        }
        $raw = base64_decode(strtr($since, '-_', '+/'), true);
        $j = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($j) && isset($j['t']) && is_string($j['t']) && strtotime($j['t']) !== false) {
            return ['t' => $j['t'], 'e' => (string) ($j['e'] ?? ''), 'i' => Str::isUuid((string) ($j['i'] ?? '')) ? (string) $j['i'] : ''];
        }
        // También se acepta un instante ISO 8601 (por ejemplo, la hora del paquete sin conexión).
        if (strtotime($since) !== false && preg_match('/^\d{4}-\d{2}-\d{2}/', $since)) {
            return ['t' => $since, 'e' => '', 'i' => ''];
        }
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'La marca de sincronización no es válida.', ['errors' => ['since' => ['La marca de sincronización no es válida.']]]);
    }

    private function encode(string $ts, string $entity, string $id): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(['t' => $ts, 'e' => $entity, 'i' => $id])), '+/', '-_'), '=');
    }

    /** Instante en microsegundos para ordenar entre entidades. */
    private function micro(string $ts): int
    {
        $d = new \DateTimeImmutable($ts);

        return (int) $d->format('U') * 1000000 + (int) $d->format('u');
    }
}
