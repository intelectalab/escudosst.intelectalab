<?php

namespace App\Modules\MRL;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\REC\RecordsService;
use App\Modules\REC\Registry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Catálogo de normas y «Agregar desde el catálogo» (M-MRL SCR-MRL-01/03):
 *  GET   /mrl/catalog?sector=&topic=&type=&origin=global|company&q=   normas globales de EscudoSST y propias de la empresa,
 *                                                                    con cuántos requisitos trae cada una y cuántos ya están en la matriz
 *  GET   /mrl/catalog/requirements?norm_ids=a,b                     requisitos del catálogo de esas normas, marcando los ya agregados
 *  POST  /mrl/catalog/norms                                         norma propia de la empresa {norm_type, number, title, ...}
 *  PATCH /mrl/catalog/norms/{id}                                    edita una norma propia (las globales no se editan: RN-MRL-01)
 *  POST  /mrl/catalog/add  {items: [{template_id} | {legal_norm_id, article?, requirement}], responsible_user_id, next_evaluation_on?, site_id?, area_id?}
 *                          agrega los requisitos elegidos a la matriz (legal_requirements) con responsable y próxima evaluación
 *                          (RN-MRL-03: por defecto hoy + legal_evaluation_months). No duplica lo que ya está.
 */
class NormCatalogController extends Controller
{
    private const TYPES = ['law', 'supreme_decree', 'ministerial_resolution', 'technical_standard', 'ordinance', 'directive', 'other'];

    public function __construct(private RecordsService $records) {}

    public function index(Request $request): JsonResponse
    {
        $where = ['n.deleted_at is null'];
        $bind = [];
        if ($s = trim((string) $request->query('sector'))) {
            $where[] = "(? = any(n.sectors) or (n.sector is not null and lower(n.sector) = lower(?)))";
            array_push($bind, $s, $s);
        }
        if ($t = trim((string) $request->query('topic'))) {
            $where[] = '? = any(n.topics)';
            $bind[] = $t;
        }
        if (in_array($type = (string) $request->query('type'), self::TYPES, true)) {
            $where[] = 'n.norm_type = ?';
            $bind[] = $type;
        }
        $origin = (string) $request->query('origin');
        if ($origin === 'global') {
            $where[] = 'n.company_id is null';
        } elseif ($origin === 'company') {
            $where[] = 'n.company_id is not null';
        }
        if ($q = trim((string) $request->query('q'))) {
            $where[] = "(n.number ilike ? or n.title ilike ? or coalesce(n.summary, '') ilike ?)";
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
            array_push($bind, $like, $like, $like);
        }
        $rows = DB::select(
            'select n.id, n.norm_type, n.number, n.title, n.issuing_entity, n.published_on, n.effective_on, n.status, n.scope, n.sector, n.summary, n.official_url,
                    n.sectors, n.topics, n.company_id is not null as is_company, n.updated_at,
                    (select count(*) from legal_norm_requirements t where t.legal_norm_id = n.id and t.deleted_at is null) as requirements_count,
                    (select count(*) from legal_requirements r where r.legal_norm_id = n.id and r.deleted_at is null) as added_count,
                    (select count(*) from legal_requirements r where r.legal_norm_id = n.id and r.deleted_at is null and r.needs_review) as review_count
               from legal_norms n where '.implode(' and ', $where)."
              order by n.company_id is null desc, case n.norm_type when 'law' then 0 when 'supreme_decree' then 1 when 'ministerial_resolution' then 2 else 3 end,
                       n.published_on desc nulls last, n.number", $bind);
        $all = DB::select('select sectors, topics, sector from legal_norms where deleted_at is null');
        $sectors = $topics = [];
        foreach ($all as $r) {
            foreach ([...$this->arr($r->sectors), ...($r->sector ? [mb_strtolower($r->sector)] : [])] as $x) {
                $sectors[$x] = true;
            }
            foreach ($this->arr($r->topics) as $x) {
                $topics[$x] = true;
            }
        }
        $perms = Permissions::effective();

        return response()->json([
            'data' => array_map(fn ($r) => [
                'id' => $r->id, 'norm_type' => $r->norm_type, 'number' => $r->number, 'title' => $r->title, 'issuing_entity' => $r->issuing_entity,
                'published_on' => $r->published_on, 'effective_on' => $r->effective_on, 'status' => $r->status, 'summary' => $r->summary, 'official_url' => $r->official_url,
                'sectors' => $this->arr($r->sectors) ?: ($r->sector ? [mb_strtolower($r->sector)] : []), 'topics' => $this->arr($r->topics),
                'is_company' => (bool) $r->is_company, 'requirements_count' => (int) $r->requirements_count, 'added_count' => (int) $r->added_count,
                // Requisitos de la empresa que requieren revisión por un cambio de esta norma (RN-MRL-05).
                'review_count' => (int) $r->review_count,
                // Marca «nueva/modificada» (SCR-MRL-01): cambios del catálogo en los últimos 30 días.
                'recently_changed' => strtotime((string) $r->updated_at) > strtotime('-30 days'),
            ], $rows),
            'meta' => [
                'sectors' => array_keys($sectors), 'topics' => array_keys($topics),
                'can_create_norm' => in_array('mrl.legal_norms.create', $perms, true),
                'can_add' => in_array('mrl.legal_requirements.create', $perms, true),
                'evaluation_months' => (int) LegalParams::number('legal_evaluation_months', 12),
            ],
        ]);
    }

    public function requirements(Request $request): JsonResponse
    {
        $ids = array_values(array_filter(explode(',', (string) $request->query('norm_ids')), fn ($x) => Str::isUuid($x)));
        if ($ids === [] || count($ids) > 50) {
            $this->fail('norm_ids', 'Elige de 1 a 50 normas.');
        }
        $arr = '{'.implode(',', $ids).'}';
        $norms = DB::select('select id, norm_type, number, title, company_id is not null as is_company from legal_norms where id = any(?::uuid[]) and deleted_at is null', [$arr]);
        $tpl = DB::select(
            'select t.id, t.legal_norm_id, t.article, t.requirement, t.topic, t.evidence_expected, t.frequency,
                    exists (select 1 from legal_requirements r where r.legal_norm_id = t.legal_norm_id and r.deleted_at is null
                             and lower(btrim(r.requirement)) = lower(btrim(t.requirement))) as added
               from legal_norm_requirements t where t.legal_norm_id = any(?::uuid[]) and t.deleted_at is null order by t.sort_order, t.article nulls last', [$arr]);
        $by = [];
        foreach ($tpl as $t) {
            $by[$t->legal_norm_id][] = ['id' => $t->id, 'article' => $t->article, 'requirement' => $t->requirement, 'topic' => $t->topic,
                'evidence_expected' => $t->evidence_expected, 'frequency' => $t->frequency, 'added' => (bool) $t->added];
        }

        return response()->json(['data' => array_map(fn ($n) => [
            'id' => $n->id, 'norm_type' => $n->norm_type, 'number' => $n->number, 'title' => $n->title, 'is_company' => (bool) $n->is_company,
            'requirements' => $by[$n->id] ?? [],
        ], $norms)]);
    }

    public function storeNorm(Request $request): JsonResponse
    {
        $v = $this->normInput($request, null);
        $id = DB::selectOne(
            'insert into legal_norms (company_id, norm_type, number, title, issuing_entity, published_on, effective_on, scope, sector, sectors, topics, summary, official_url, status, created_by, updated_by)
             values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::text[], ?::text[], ?, ?, ?, ?, ?) returning id',
            [app(CompanyContext::class)->companyId, $v['norm_type'], $v['number'], $v['title'], $v['issuing_entity'], $v['published_on'], $v['effective_on'],
                $v['sectors'] ? 'sector' : 'general', $v['sectors'][0] ?? null, $this->pg($v['sectors']), $this->pg($v['topics']), $v['summary'], $v['official_url'],
                $v['status'], (string) auth()->id(), (string) auth()->id()]
        )->id;

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function updateNorm(Request $request, string $id): JsonResponse
    {
        $n = DB::selectOne('select id, company_id from legal_norms where id = ? and deleted_at is null', [$id]);
        if (! $n) {
            throw ApiProblem::notFound('La norma no existe.');
        }
        if ($n->company_id === null) {
            throw ApiProblem::forbidden('Las normas del catálogo de EscudoSST no se editan. Si encuentras un error, avísanos desde Soporte.');
        }
        $v = $this->normInput($request, $id);
        DB::update(
            'update legal_norms set norm_type = ?, number = ?, title = ?, issuing_entity = ?, published_on = ?, effective_on = ?, scope = ?, sector = ?, sectors = ?::text[], topics = ?::text[],
                    summary = ?, official_url = ?, status = ?, updated_by = ?, updated_at = now() where id = ?',
            [$v['norm_type'], $v['number'], $v['title'], $v['issuing_entity'], $v['published_on'], $v['effective_on'], $v['sectors'] ? 'sector' : 'general', $v['sectors'][0] ?? null,
                $this->pg($v['sectors']), $this->pg($v['topics']), $v['summary'], $v['official_url'], $v['status'], (string) auth()->id(), $id]
        );
        // RN-MRL-05: si la norma propia queda derogada, sus requisitos activos pasan a «derogado».
        if ($v['status'] === 'repealed') {
            DB::update("update legal_requirements set status = 'repealed', updated_by = ?, updated_at = now() where legal_norm_id = ? and deleted_at is null and status = 'active'",
                [(string) auth()->id(), $id]);
        }

        return response()->json(['data' => ['id' => $id]]);
    }

    public function add(Request $request): JsonResponse
    {
        $items = $request->input('items');
        if (! is_array($items) || $items === []) {
            $this->fail('items', 'Elige al menos un requisito para agregar.');
        }
        if (count($items) > 200) {
            $this->fail('items', 'Agrega hasta 200 requisitos a la vez.');
        }
        $responsible = (string) $request->input('responsible_user_id');
        if (! Str::isUuid($responsible) || ! DB::selectOne("select 1 as ok from memberships where user_id = ? and status = 'active' and deleted_at is null", [$responsible])) {
            $this->fail('responsible_user_id', 'Elige al responsable de los requisitos (RN-MRL-03).');
        }
        $months = (int) LegalParams::number('legal_evaluation_months', 12);
        $next = (string) ($request->input('next_evaluation_on') ?: date('Y-m-d', strtotime("+{$months} months")));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $next) || $next < date('Y-m-d')) {
            $this->fail('next_evaluation_on', 'La próxima evaluación debe ser hoy o una fecha futura.');
        }
        $site = $request->input('site_id') ?: null;
        $area = $request->input('area_id') ?: null;

        $res = Registry::resource('MRL', 'legal_requirements');
        $created = [];
        $skipped = 0;
        DB::transaction(function () use ($items, $responsible, $next, $site, $area, $res, &$created, &$skipped) {
            $seen = [];
            foreach ($items as $i => $it) {
                $it = is_array($it) ? $it : [];
                if (! empty($it['template_id'])) {
                    $t = Str::isUuid((string) $it['template_id'])
                        ? DB::selectOne('select legal_norm_id, article, requirement, evidence_expected, frequency from legal_norm_requirements where id = ? and deleted_at is null', [$it['template_id']])
                        : null;
                    if (! $t) {
                        $this->fail("items.{$i}", 'Uno de los requisitos elegidos ya no está en el catálogo.');
                    }
                    $row = (array) $t;
                } else {
                    $text = trim((string) ($it['requirement'] ?? ''));
                    $norm = (string) ($it['legal_norm_id'] ?? '');
                    if (mb_strlen($text) < 5) {
                        $this->fail("items.{$i}", 'Escribe el requisito (al menos 5 caracteres).');
                    }
                    if (! Str::isUuid($norm) || ! DB::selectOne('select 1 as ok from legal_norms where id = ? and deleted_at is null', [$norm])) {
                        $this->fail("items.{$i}", 'Elige la norma del requisito.');
                    }
                    $row = ['legal_norm_id' => $norm, 'article' => trim((string) ($it['article'] ?? '')) ?: null, 'requirement' => mb_substr($text, 0, 2000),
                        'evidence_expected' => trim((string) ($it['evidence_expected'] ?? '')) ?: null, 'frequency' => null];
                }
                $key = $row['legal_norm_id'].'|'.mb_strtolower(trim($row['requirement']));
                if (isset($seen[$key]) || DB::selectOne('select 1 as ok from legal_requirements where legal_norm_id = ? and deleted_at is null and lower(btrim(requirement)) = lower(btrim(?))',
                    [$row['legal_norm_id'], $row['requirement']])) {
                    $skipped++;

                    continue;
                }
                $seen[$key] = true;
                $created[] = $this->records->create($res, array_filter([
                    'legal_norm_id' => $row['legal_norm_id'],
                    'article' => $row['article'],
                    'requirement' => $row['requirement'],
                    'evidence_expected' => $row['evidence_expected'],
                    'frequency' => $row['frequency'],
                    'responsible_user_id' => $responsible,
                    'next_evaluation_on' => $next,
                    'site_id' => $site,
                    'area_id' => $area,
                    'applies' => true,
                    'status' => 'active',
                ], fn ($x) => $x !== null))['id'];
            }
        });

        return response()->json(['data' => ['created' => count($created), 'skipped' => $skipped, 'ids' => $created, 'next_evaluation_on' => $next]], 201);
    }

    /** @return array<string, mixed> */
    private function normInput(Request $request, ?string $id): array
    {
        $type = (string) $request->input('norm_type');
        if (! in_array($type, self::TYPES, true)) {
            $this->fail('norm_type', 'Elige el tipo de norma.');
        }
        $number = trim((string) $request->input('number'));
        $title = trim((string) $request->input('title'));
        if ($number === '' || mb_strlen($number) > 60) {
            $this->fail('number', 'Escribe el número de la norma.');
        }
        if (mb_strlen($title) < 3 || mb_strlen($title) > 300) {
            $this->fail('title', 'Escribe el título de la norma.');
        }
        if (DB::selectOne('select 1 as ok from legal_norms where norm_type = ? and lower(number) = lower(?) and deleted_at is null and id is distinct from ?::uuid', [$type, $number, $id])) {
            $this->fail('number', 'Esa norma ya está en el catálogo.');
        }
        $url = trim((string) $request->input('official_url')) ?: null;
        if ($url && ! preg_match('#^https?://#i', $url)) {
            $this->fail('official_url', 'El enlace oficial debe empezar con http:// o https://.');
        }
        $date = function (string $f) use ($request): ?string {
            $v = (string) $request->input($f);
            if ($v === '') {
                return null;
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $this->fail($f, 'Fecha no válida.');
            }

            return $v;
        };
        $list = fn (string $f) => array_values(array_unique(array_filter(array_map(fn ($x) => mb_strtolower(trim(preg_replace('/[{},"]/', '', (string) $x))), (array) $request->input($f, [])))));
        $status = (string) ($request->input('status') ?: 'current');

        return [
            'norm_type' => $type, 'number' => $number, 'title' => $title,
            'issuing_entity' => mb_substr(trim((string) $request->input('issuing_entity')), 0, 150) ?: null,
            'published_on' => $date('published_on'), 'effective_on' => $date('effective_on'),
            'sectors' => array_slice($list('sectors'), 0, 10), 'topics' => array_slice($list('topics'), 0, 10),
            'summary' => mb_substr(trim((string) $request->input('summary')), 0, 2000) ?: null, 'official_url' => $url,
            'status' => in_array($status, ['current', 'amended', 'repealed'], true) ? $status : 'current',
        ];
    }

    /** @return list<string> */
    private function arr(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }
        $s = trim((string) $v, '{}');

        return $s === '' ? [] : array_map(fn ($x) => trim($x, '"'), str_getcsv($s));
    }

    private function pg(array $list): ?string
    {
        return $list ? '{'.implode(',', array_map(fn ($x) => '"'.str_replace('"', '', $x).'"', $list)).'}' : null;
    }

    private function fail(string $field, string $message): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $message, ['errors' => [$field => [$message]]]);
    }
}
