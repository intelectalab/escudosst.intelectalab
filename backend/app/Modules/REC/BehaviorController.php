<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Observación de conducta segura (M-OBS):
 *  GET  /obs/lists                  SCR-OBS-03: versión vigente, borrador y versiones anteriores de la lista de comportamientos
 *  POST /obs/lists                  guarda el borrador {items: [{category, behavior}], change_summary?} (RN-OBS-05)
 *  POST /obs/lists/publish          publica el borrador: queda vigente y la anterior obsoleta
 *  POST /obs/observe                SCR-OBS-01: captura rápida {site_id, area_id?, task_observed?, observed_worker_count?, feedback_given,
 *                                   comments?, items: [{category, behavior, result}]}; sin identidad del observado (RN-OBS-01); queda enviada
 *  GET  /obs/trends?year=           SCR-OBS-02: % seguro por categoría, por área y por mes (RN-OBS-02), con el umbral bbs_alert_pct
 */
class BehaviorController extends Controller
{
    private const CATEGORIES = ['ppe', 'body_position', 'tools', 'procedures', 'housekeeping', 'communication', 'other'];

    public function lists(): JsonResponse
    {
        $this->can('obs.behavior_observations.read');
        $rows = DB::select("select l.id, l.version_number, l.status, l.items, l.change_summary, l.published_at, u.full_name as published_by
                              from behavior_lists l left join users u on u.id = l.published_by where l.deleted_at is null order by l.version_number desc");
        foreach ($rows as $r) {
            $r->items = json_decode((string) $r->items, true) ?: [];
        }

        return response()->json(['data' => [
            'current' => collect($rows)->firstWhere('status', 'current'),
            'draft' => collect($rows)->firstWhere('status', 'draft'),
            'history' => array_values(array_filter($rows, fn ($r) => $r->status === 'obsolete')),
        ]]);
    }

    public function saveList(Request $request): JsonResponse
    {
        $this->can('obs.behavior_observations.update');
        $d = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.category' => ['required', 'in:'.implode(',', self::CATEGORIES)],
            'items.*.behavior' => ['required', 'string', 'max:300'],
            'change_summary' => ['nullable', 'string', 'max:1000'],
        ], ['items.required' => 'Agrega al menos un comportamiento.', 'items.*.behavior.required' => 'Cada comportamiento necesita su descripción.']);
        $items = array_values(array_map(fn ($i) => ['category' => $i['category'], 'behavior' => trim($i['behavior'])], $d['items']));
        $uid = (string) auth()->id();
        $draft = DB::selectOne("select id from behavior_lists where status = 'draft' and deleted_at is null");
        if ($draft) {
            DB::update('update behavior_lists set items = ?::jsonb, change_summary = ?, updated_by = ?, updated_at = now() where id = ?',
                [json_encode($items, JSON_UNESCAPED_UNICODE), $d['change_summary'] ?? null, $uid, $draft->id]);
            $id = $draft->id;
        } else {
            $n = (int) DB::selectOne('select coalesce(max(version_number), 0) + 1 as n from behavior_lists where deleted_at is null')->n;
            $id = DB::selectOne("insert into behavior_lists (company_id, version_number, status, items, change_summary, created_by, updated_by) values (?, ?, 'draft', ?::jsonb, ?, ?, ?) returning id",
                [app(CompanyContext::class)->companyId, $n, json_encode($items, JSON_UNESCAPED_UNICODE), $d['change_summary'] ?? null, $uid, $uid])->id;
        }

        return response()->json(['data' => ['id' => $id]]);
    }

    public function publish(): JsonResponse
    {
        $this->can('obs.behavior_observations.update');
        $draft = DB::selectOne("select id, version_number from behavior_lists where status = 'draft' and deleted_at is null")
            ?? throw new ApiProblem(409, 'conflict', 'Sin borrador', 'No hay un borrador de la lista para publicar.');
        $uid = (string) auth()->id();
        DB::transaction(function () use ($draft, $uid) {
            DB::update("update behavior_lists set status = 'obsolete', updated_by = ?, updated_at = now() where status = 'current' and deleted_at is null", [$uid]);
            DB::update("update behavior_lists set status = 'current', published_at = now(), published_by = ?, updated_by = ?, updated_at = now() where id = ?", [$uid, $uid, $draft->id]);
        });

        return response()->json(['data' => ['id' => $draft->id, 'version_number' => (int) $draft->version_number]]);
    }

    public function observe(Request $request, RecordsService $records): JsonResponse
    {
        $this->can('obs.behavior_observations.create');
        $this->can('obs.behavior_observation_items.create');
        $d = $request->validate([
            'site_id' => ['required', 'uuid'],
            'area_id' => ['nullable', 'uuid'],
            'task_observed' => ['nullable', 'string', 'max:300'],
            'observed_worker_count' => ['nullable', 'integer', 'min:1', 'max:500'],
            'feedback_given' => ['required', 'boolean'],
            'comments' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.category' => ['required', 'in:'.implode(',', self::CATEGORIES)],
            'items.*.behavior' => ['required', 'string', 'max:300'],
            'items.*.result' => ['required', 'in:safe,at_risk,not_observed'],
        ], ['site_id.required' => 'Elige la sede.', 'feedback_given.required' => 'Indica si diste retroalimentación.']);
        if (! array_filter($d['items'], fn ($i) => $i['result'] !== 'not_observed')) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Marca al menos un comportamiento como seguro o en riesgo.', ['errors' => ['items' => ['Marca al menos un comportamiento como seguro o en riesgo.']]]);
        }
        $list = DB::selectOne("select id from behavior_lists where status = 'current' and deleted_at is null")?->id;
        $resObs = Registry::resource('OBS', 'behavior_observations');
        $resItem = Registry::resource('OBS', 'behavior_observation_items');
        $id = DB::transaction(function () use ($records, $resObs, $resItem, $d, $list) {
            $o = $records->create($resObs, array_filter([
                'observed_on' => now('America/Lima')->format('Y-m-d'), 'observer_user_id' => (string) auth()->id(), 'site_id' => $d['site_id'], 'area_id' => $d['area_id'] ?? null,
                'task_observed' => $d['task_observed'] ?? null, 'observed_worker_count' => $d['observed_worker_count'] ?? null, 'feedback_given' => $d['feedback_given'],
                'comments' => $d['comments'] ?? null,
            ], fn ($x) => $x !== null));
            // RN-OBS-05: la observación guarda la versión de la lista con que se hizo (la pone el sistema).
            DB::update('update behavior_observations set behavior_list_id = ? where id = ?', [$list, $o['id']]);
            foreach ($d['items'] as $i) {
                if ($i['result'] !== 'not_observed') {
                    $records->create($resItem, ['observation_id' => $o['id'], 'category' => $i['category'], 'behavior' => $i['behavior'], 'result' => $i['result']]);
                }
            }
            $records->update($resObs, $o['id'], ['status' => 'submitted']);

            return $o['id'];
        });
        $c = DB::selectOne('select safe_count, at_risk_count from behavior_observations where id = ?', [$id]);

        return response()->json(['data' => ['id' => $id, 'safe' => (int) $c->safe_count, 'at_risk' => (int) $c->at_risk_count]], 201);
    }

    public function trends(Request $request): JsonResponse
    {
        $this->can('obs.behavior_observations.read');
        $year = (int) $request->query('year', now('America/Lima')->format('Y'));
        $base = "from behavior_observation_items i join behavior_observations o on o.id = i.observation_id and o.deleted_at is null and o.status in ('submitted', 'reviewed')
                 left join areas a on a.id = o.area_id
                 where i.deleted_at is null and i.result in ('safe', 'at_risk') and extract(year from o.observed_on) = ?";
        $pct = fn ($rows) => array_map(function ($r) {
            $r->safe = (int) $r->safe;
            $r->total = (int) $r->total;
            $r->pct = $r->total ? (int) round($r->safe * 100 / $r->total) : null;

            return $r;
        }, $rows);

        return response()->json(['data' => [
            'year' => $year,
            'alert_pct' => (int) LegalParams::number('bbs_alert_pct', 80),
            'by_category' => $pct(DB::select("select i.category as key, count(*) filter (where i.result = 'safe') as safe, count(*) as total {$base} group by i.category order by i.category", [$year])),
            'by_area' => $pct(DB::select("select coalesce(a.name, 'Sin área') as key, count(*) filter (where i.result = 'safe') as safe, count(*) as total {$base}
                                          group by a.name order by a.name nulls last", [$year])),
            'by_month' => $pct(DB::select("select extract(month from o.observed_on)::int as key, count(*) filter (where i.result = 'safe') as safe, count(*) as total {$base}
                                           group by 1 order by 1", [$year])),
            'observations' => (int) DB::selectOne("select count(*) as n from behavior_observations where deleted_at is null and status in ('submitted', 'reviewed') and extract(year from observed_on) = ?", [$year])->n,
        ]]);
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede hacer esto con las observaciones.');
        }
    }
}
