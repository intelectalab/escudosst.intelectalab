<?php

namespace App\Modules\IAA;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\IPC\Services\RiskEvaluator;
use App\Modules\REC\RecordsService;
use App\Modules\REC\Registry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Asistente (complemento IAA) — IPERC sugerido e investigación guiada (M-IAA SCR-IAA-01, 02 y 05).
 * Responde con la biblioteca que entrena el equipo de EscudoSST y el catálogo de peligros (sin IA de pago).
 * Cada propuesta queda como ai_suggestions «pendiente»; solo una persona la acepta o descarta (la IA nunca aplica cambios).
 *
 *  POST /iaa/iperc/suggest            {position_id, matrix_id?, context?}  filas propuestas para el borrador del IPERC del puesto
 *  POST /iaa/investigation/guide      {investigation_id, answers?}         preguntas guiadas por método y causas propuestas
 *  POST /iaa/suggestions/{id}/accept  {row?: {...}, description?}          aplica: fila IPERC (+ controles) o causa en el árbol
 *  POST /iaa/suggestions/{id}/reject  {notes?}                             descarta
 *  GET  /iaa/history                                                       solicitudes de la empresa y lo que se decidió
 *
 * Al aceptar se usa el mismo motor de registros (/records/IPC/iperc_rows, /records/INV/investigation_causes): mismas
 * validaciones, permisos, bitácora y webhooks que si la persona lo escribiera a mano.
 */
class GuidedController extends Controller
{
    private const HIERARCHY = [
        'elimin' => 'elimination', 'sustit' => 'substitution', 'ingenier' => 'engineering', 'señal' => 'signage', 'epp' => 'ppe', 'protección personal' => 'ppe',
    ];

    public function __construct(private RecordsService $records, private RiskEvaluator $eval, private AssistantController $library) {}

    // ── IPERC sugerido ──────────────────────────────────────────────────────

    public function iperc(Request $request): JsonResponse
    {
        Usage::guard();
        $d = $request->validate([
            'position_id' => ['required', 'uuid'],
            'matrix_id' => ['nullable', 'uuid'],
            'context' => ['nullable', 'string', 'max:1000'],
        ], ['position_id.required' => 'Elige el puesto.']);
        $this->noHealth($d['context'] ?? '', 'context');
        $this->needs('IPC', 'la Matriz IPERC');

        $pos = DB::selectOne('select id, name, description, functions from positions where id = ? and deleted_at is null', [$d['position_id']]);
        if (! $pos) {
            throw ApiProblem::notFound('El puesto no existe.');
        }
        $matrix = DB::selectOne(
            "select m.id, m.code, m.title, m.process, m.risk_criteria_id from iperc_matrices m
              where m.deleted_at is null and m.status = 'active' and ".(! empty($d['matrix_id']) ? 'm.id = ?' : 'm.position_id = ?').' order by m.updated_at desc limit 1',
            [$d['matrix_id'] ?? $pos->id]);
        $draft = $matrix ? DB::selectOne("select id, version_number from iperc_versions where iperc_matrix_id = ? and status = 'draft' and deleted_at is null", [$matrix->id]) : null;
        $criteria = $matrix ? $this->eval->criteria($matrix->risk_criteria_id)
            : (($c = DB::selectOne("select id from risk_criteria where status = 'active' and deleted_at is null order by company_id nulls last, created_at limit 1")) ? $this->eval->criteria($c->id) : null);

        $text = trim(implode(' ', array_filter([$pos->name, $pos->description, $pos->functions, $d['context'] ?? null])));
        $started = microtime(true);
        $proposals = $this->propose($text, $criteria);
        $context = trim((string) ($d['context'] ?? ''));
        $base = [
            'process' => $matrix?->process,
            'activity' => $pos->functions ? mb_substr(trim(strtok($pos->functions, "\n.")), 0, 150) : "Funciones del puesto {$pos->name}",
            'task' => $context !== '' ? mb_substr($context, 0, 150) : "Tareas del puesto {$pos->name}",
            'position_id' => $pos->id,
        ];

        $reqId = $this->request('iperc_suggestion', 'IPC', 'positions', $pos->id, "IPERC sugerido para el puesto {$pos->name}".($context !== '' ? " · {$context}" : ''), $started);
        $out = [];
        foreach ($proposals as $p) {
            $payload = $base + $p;
            $out[] = $this->suggestion($reqId, 'iperc_row', 'iperc_versions', $draft?->id, $payload, $p['rationale'] ?? null);
        }

        return response()->json(['data' => [
            'request_id' => $reqId,
            'position' => ['id' => $pos->id, 'name' => $pos->name],
            'matrix' => $matrix ? ['id' => $matrix->id, 'code' => $matrix->code, 'title' => $matrix->title] : null,
            'draft' => $draft ? ['id' => $draft->id, 'version_number' => (int) $draft->version_number] : null,
            'criteria' => $criteria,
            'note' => ! $matrix ? 'Este puesto aún no tiene matriz IPERC: créala en Matriz IPERC para poder aceptar las sugerencias.'
                : (! $draft ? "La matriz {$matrix->code} no tiene una versión en borrador: abre una nueva versión para aceptar sugerencias." : null),
            'suggestions' => $out,
        ]]);
    }

    /** Peligros del catálogo y temas de la biblioteca que coinciden con el puesto y el contexto. */
    private function propose(string $text, ?array $criteria): array
    {
        $t = ' '.mb_strtolower($text).' ';
        $eval = $criteria ? $this->defaults($criteria) : [];
        $out = [];
        $seen = [];

        // 1) Temas de la biblioteca (cada tema es un peligro con sus controles por jerarquía).
        $topics = [];
        foreach (DB::select("select id, title, keywords, items from assistant_knowledge where feature = 'controls' and status = 'active'") as $k) {
            $kws = array_values(array_filter(array_map(fn ($x) => mb_strtolower(trim($x, ' "')), explode(',', trim((string) $k->keywords, '{}')))));
            $score = 0;
            foreach ($kws as $kw) {
                if ($kw !== '' && str_contains($t, $kw)) {
                    $score += mb_strlen($kw) >= 6 ? 2 : 1;
                }
            }
            if (str_contains($t, mb_strtolower($k->title))) {
                $score += 3;
            }
            if ($score > 0) {
                $topics[] = [$score, $k, $kws];
            }
        }
        usort($topics, fn ($a, $b) => $b[0] <=> $a[0]);
        foreach (array_slice($topics, 0, 6) as [$score, $k, $kws]) {
            DB::update('update assistant_knowledge set uses = uses + 1 where id = ?', [$k->id]);
            $cat = $this->catalog(array_merge([$k->title], $kws), 1)[0] ?? null;
            $controls = array_map(fn ($i) => ['hierarchy' => $this->hierarchy((string) ($i['group'] ?? '')), 'description' => trim(($i['title'] ?? '').(! empty($i['detail']) && ! str_contains((string) $i['detail'], 'Ley 29783') ? ': '.$i['detail'] : ''))],
                json_decode($k->items, true) ?: []);
            $out[] = [
                'hazard' => $cat->hazard ?? $k->title, 'risk' => $cat->risk ?? "Lesión o daño a la salud por {$this->lower($k->title)}",
                'consequence' => $cat->possible_consequence ?? null, 'hazard_catalog_id' => $cat->id ?? null,
                'controls' => array_values(array_filter($controls, fn ($c) => $c['description'] !== '')),
                'rationale' => "Tema «{$k->title}» de la biblioteca de EscudoSST".($cat ? ' y peligro del catálogo' : '').'.',
                'confidence' => min(0.95, 0.4 + $score * 0.1),
            ] + $eval;
            $seen[mb_strtolower($cat->hazard ?? $k->title)] = true;
        }

        // 2) Peligros del catálogo que nombran palabras del puesto o del contexto.
        $words = array_values(array_unique(array_filter(preg_split('/[^\p{L}]+/u', $t), fn ($w) => mb_strlen($w) >= 5)));
        foreach ($this->catalog($words, 8) as $h) {
            if (count($out) >= 8 || isset($seen[mb_strtolower($h->hazard)])) {
                continue;
            }
            $typical = json_decode((string) $h->typical_controls, true) ?: [];
            $controls = [];
            foreach ($typical as $c) {
                $desc = is_array($c) ? ($c['description'] ?? $c['title'] ?? null) : $c;
                if (is_string($desc) && $desc !== '') {
                    $controls[] = ['hierarchy' => is_array($c) && isset($c['hierarchy']) ? $c['hierarchy'] : $this->hierarchy($desc), 'description' => $desc];
                }
            }
            $out[] = ['hazard' => $h->hazard, 'risk' => $h->risk, 'consequence' => $h->possible_consequence, 'hazard_catalog_id' => $h->id,
                'controls' => $controls, 'rationale' => 'Peligro del catálogo que coincide con el puesto o el contexto.', 'confidence' => 0.4] + $eval;
            $seen[mb_strtolower($h->hazard)] = true;
        }

        return $out;
    }

    private function catalog(array $words, int $limit): array
    {
        $words = array_slice(array_values(array_filter($words, fn ($w) => mb_strlen((string) $w) >= 4)), 0, 12);
        if ($words === []) {
            return [];
        }
        $or = implode(' or ', array_fill(0, count($words), '(hazard ilike ? or risk ilike ?)'));
        $bind = [];
        foreach ($words as $w) {
            array_push($bind, "%{$w}%", "%{$w}%");
        }

        return DB::select("select id, hazard, risk, possible_consequence, typical_controls from hazard_catalog
                            where deleted_at is null and is_active and ({$or}) order by company_id nulls last, hazard limit {$limit}", $bind);
    }

    /** Evaluación propuesta: el valor medio de cada escala (la persona la ajusta al aceptar). */
    private function defaults(array $c): array
    {
        $mid = function (array $scale) {
            $v = array_column($scale, 'value');
            sort($v);

            return $v === [] ? null : (int) $v[intdiv(count($v) - 1, 2)];
        };
        $out = ['severity_value' => $mid($c['severity_scale'])];
        if ($c['method'] === 'pxs_index') {
            $out['probability_detail'] = [];
            foreach ($c['factors'] as $f) {
                $out['probability_detail'][$f['code']] = $mid($f['levels']);
            }
        } else {
            $out['probability_value'] = $mid($c['probability_scale']);
        }

        return $out;
    }

    private function hierarchy(string $group): string
    {
        $g = mb_strtolower($group);
        foreach (self::HIERARCHY as $k => $v) {
            if (str_contains($g, $k)) {
                return $v;
            }
        }

        return 'administrative';
    }

    // ── Investigación guiada ────────────────────────────────────────────────

    private const QUESTIONS = [
        'cause_tree' => ['¿Qué pasó exactamente? Describe el hecho final (la lesión o el daño).', '¿Qué hecho inmediato lo produjo?', '¿Qué fue necesario para que ese hecho ocurriera? (actos y condiciones)', '¿Qué cambió respecto a lo habitual en la tarea?', '¿Qué barrera o control debió impedirlo y por qué no funcionó?'],
        'five_whys' => ['¿Qué pasó?', '¿Por qué pasó?', '¿Por qué ocurrió eso?', '¿Y por qué?', '¿Qué falla del sistema de gestión hay detrás?'],
        'scat' => ['¿Cuál fue el tipo de contacto (golpe, atrapamiento, caída, contacto eléctrico…)?', '¿Qué actos subestándar hubo?', '¿Qué condiciones subestándar había?', '¿Qué factores personales influyeron (capacitación, experiencia, fatiga)?', '¿Qué factores del trabajo influyeron (procedimiento, equipo, supervisión, mantenimiento)?', '¿Qué faltó en el programa de control (IPERC, inspecciones, estándares)?'],
        'fishbone' => ['Personas: ¿qué hicieron o no hicieron?', 'Método: ¿había procedimiento y se siguió?', 'Máquina o equipo: ¿estaba en buen estado y con guardas?', 'Material: ¿había algo inadecuado?', 'Entorno: ¿orden, iluminación, clima, espacio?', 'Gestión: ¿supervisión, capacitación, IPERC?'],
    ];

    public function investigation(Request $request): JsonResponse
    {
        Usage::guard();
        $d = $request->validate([
            'investigation_id' => ['required', 'uuid'],
            'answers' => ['nullable', 'array', 'max:10'],
            'answers.*' => ['nullable', 'string', 'max:1000'],
        ], ['investigation_id.required' => 'Elige la investigación.']);
        $this->needs('INV', 'Investigación de accidentes');
        $answers = array_values(array_filter(array_map(fn ($a) => trim((string) $a), $d['answers'] ?? [])));
        $this->noHealth(implode(' ', $answers), 'answers');

        $inv = DB::selectOne(
            'select i.id, i.code, i.method, i.status, e.id as event_id, e.code as event_code, e.event_type, e.description, e.activity_at_time
               from investigations i join sst_events e on e.id = i.event_id where i.id = ? and i.deleted_at is null', [$d['investigation_id']]);
        if (! $inv) {
            throw ApiProblem::notFound('La investigación no existe.');
        }
        $started = microtime(true);
        $text = implode(' ', array_filter([$inv->description, $inv->activity_at_time, ...$answers]));
        [$items, $note, $topic] = $this->library->answer('causes', $text);

        $reqId = $this->request('investigation_guide', 'INV', 'investigations', $inv->id, "Investigación guiada {$inv->code} (evento {$inv->event_code})", $started);
        $out = [];
        foreach ($items as $i) {
            $desc = trim(($i['title'] ?? '').(! empty($i['detail']) ? ': '.$i['detail'] : ''));
            if ($desc === '') {
                continue;
            }
            [$level, $category] = $this->causeKind((string) ($i['group'] ?? ''), $desc);
            $out[] = $this->suggestion($reqId, 'investigation_cause', 'investigations', $inv->id,
                ['description' => mb_substr($desc, 0, 1000), 'cause_level' => $level, 'cause_category' => $category, 'group' => $i['group'] ?? null], $note);
        }

        return response()->json(['data' => [
            'request_id' => $reqId,
            'investigation' => ['id' => $inv->id, 'code' => $inv->code, 'method' => $inv->method, 'event_code' => $inv->event_code, 'event_type' => $inv->event_type],
            'questions' => self::QUESTIONS[$inv->method] ?? self::QUESTIONS['cause_tree'],
            'note' => $note,
            'topic' => $topic,
            'suggestions' => $out,
        ]]);
    }

    /** Nivel y categoría de la causa según el grupo con que la escribió el equipo. */
    private function causeKind(string $group, string $desc): array
    {
        $g = mb_strtolower($group.' '.$desc);
        $level = str_contains($g, 'falta de control') || str_contains($g, 'sistema') || str_contains($g, 'gestión') ? 'lack_of_control'
            : (str_contains($g, 'básica') || str_contains($g, 'basica') || str_contains($g, 'factor') ? 'basic'
                : (str_contains($g, 'raíz') ? 'root' : 'immediate'));
        $category = match (true) {
            $level === 'lack_of_control' => 'management_system',
            str_contains($g, 'condici') => 'unsafe_condition',
            str_contains($g, 'acto') => 'unsafe_act',
            str_contains($g, 'personal') => 'personal_factor',
            $level === 'basic' => 'job_factor',
            default => null,
        };

        return [$level, $category];
    }

    // ── Aceptar o descartar ─────────────────────────────────────────────────

    public function accept(Request $request, string $id): JsonResponse
    {
        $s = $this->find($id);
        $payload = json_decode($s->payload, true) ?: [];

        $applied = DB::transaction(function () use ($s, $payload, $request) {
            if ($s->suggestion_type === 'iperc_row') {
                return $this->applyRow($s, $payload, (array) $request->input('row', []));
            }
            if ($s->suggestion_type === 'investigation_cause') {
                return $this->applyCause($s, $payload, $request->input('description'), $request->input('cause_level'));
            }
            throw new ApiProblem(409, 'conflict', 'No se puede aplicar', 'Esta sugerencia es solo de consulta.');
        });

        return response()->json(['data' => $applied]);
    }

    private function applyRow(object $s, array $payload, array $edit): array
    {
        $this->needs('IPC', 'la Matriz IPERC');
        $version = $s->target_id;
        if (! $version || ! DB::selectOne("select 1 as ok from iperc_versions where id = ? and status = 'draft' and deleted_at is null", [$version])) {
            throw new ApiProblem(409, 'conflict', 'No hay borrador', 'La matriz del puesto no tiene una versión en borrador. Abre una nueva versión y vuelve a pedir sugerencias.');
        }
        $allowed = ['process', 'activity', 'task', 'is_routine', 'position_id', 'hazard_catalog_id', 'hazard', 'risk', 'consequence', 'exposed_workers',
            'probability_value', 'probability_detail', 'severity_value', 'residual_probability', 'residual_severity', 'notes'];
        $rowInput = array_intersect_key(array_merge($payload, $edit), array_flip($allowed));
        $edited = array_intersect_key($edit, array_flip($allowed)) !== [] || array_key_exists('controls', $edit);
        $resRow = Registry::resource('IPC', 'iperc_rows');
        $this->records->authorize($resRow, 'create');
        $row = $this->records->create($resRow, ['iperc_version_id' => $version] + $rowInput);
        DB::update('update iperc_rows set ai_assisted = true, ai_suggestion_id = ? where id = ?', [$s->id, $row['id']]);

        $controls = [];
        $list = array_key_exists('controls', $edit) && is_array($edit['controls']) ? $edit['controls'] : ($payload['controls'] ?? []);
        if ($list !== []) {
            $resCtl = Registry::resource('IPC', 'iperc_controls');
            $this->records->authorize($resCtl, 'create');
            foreach (array_slice($list, 0, 12) as $c) {
                $desc = trim((string) ($c['description'] ?? ''));
                if ($desc === '') {
                    continue;
                }
                $controls[] = $this->records->create($resCtl, ['iperc_row_id' => $row['id'], 'hierarchy' => $c['hierarchy'] ?? 'administrative',
                    'description' => mb_substr($desc, 0, 500), 'is_existing' => false])['id'];
            }
        }
        $this->review($s->id, $edited ? 'partially_accepted' : 'accepted', 'iperc_rows', [$row['id']]);

        return ['status' => $edited ? 'partially_accepted' : 'accepted', 'row_id' => $row['id'], 'row_number' => $row['row_number'] ?? null, 'controls' => count($controls), 'version_id' => $version];
    }

    private function applyCause(object $s, array $payload, mixed $description, mixed $level): array
    {
        $this->needs('INV', 'Investigación de accidentes');
        $desc = is_string($description) && trim($description) !== '' ? trim($description) : $payload['description'];
        $lvl = is_string($level) && in_array($level, ['immediate', 'basic', 'root', 'lack_of_control'], true) ? $level : $payload['cause_level'];
        $res = Registry::resource('INV', 'investigation_causes');
        $this->records->authorize($res, 'create');
        $order = (int) DB::selectOne('select coalesce(max(sort_order), 0) + 1 as n from investigation_causes where investigation_id = ? and deleted_at is null', [$s->target_id])->n;
        $cause = $this->records->create($res, array_filter([
            'investigation_id' => $s->target_id, 'cause_level' => $lvl, 'cause_category' => $payload['cause_category'] ?? null,
            'description' => mb_substr($desc, 0, 1000), 'sort_order' => $order,
        ], fn ($v) => $v !== null));
        DB::update('update investigation_causes set ai_suggestion_id = ? where id = ?', [$s->id, $cause['id']]);
        DB::update('update investigations set ai_assisted = true, ai_suggestion_id = coalesce(ai_suggestion_id, ?) where id = ? and not ai_assisted', [$s->id, $s->target_id]);
        $edited = $desc !== $payload['description'] || $lvl !== $payload['cause_level'];
        $this->review($s->id, $edited ? 'partially_accepted' : 'accepted', 'investigation_causes', [$cause['id']]);

        return ['status' => $edited ? 'partially_accepted' : 'accepted', 'cause_id' => $cause['id']];
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $s = $this->find($id);
        $this->review($s->id, 'rejected', null, null, mb_substr(trim((string) $request->input('notes')), 0, 500) ?: null);

        return response()->json(['data' => ['status' => 'rejected']]);
    }

    private function find(string $id): object
    {
        if (! Str::isUuid($id) || ! ($s = DB::selectOne('select * from ai_suggestions where id = ? and deleted_at is null', [$id]))) {
            throw ApiProblem::notFound('La sugerencia no existe.');
        }
        if ($s->status !== 'pending') {
            throw new ApiProblem(409, 'conflict', 'Ya se revisó', 'Esta sugerencia ya fue aceptada o descartada.');
        }

        return $s;
    }

    private function review(string $id, string $status, ?string $entity, ?array $ids, ?string $notes = null): void
    {
        $user = (string) auth()->id();
        DB::update(
            'update ai_suggestions set status = ?, reviewed_by = ?, reviewed_at = now(), review_notes = ?, applied_entity_type = ?, applied_entity_ids = ?::uuid[], updated_by = ?, updated_at = now() where id = ?',
            [$status, $user, $notes, $entity, $ids ? '{'.implode(',', $ids).'}' : null, $user, $id]);
    }

    // ── Historial ──────────────────────────────────────────────────────────

    public function history(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $total = (int) DB::selectOne('select count(*) as n from ai_requests where deleted_at is null')->n;
        $rows = DB::select(
            "select r.id, r.feature, r.module_code, r.subject_type, r.subject_id, r.input_summary, r.status, r.created_at, u.full_name as user_name,
                    count(s.id) as suggestions,
                    count(s.id) filter (where s.status in ('accepted','partially_accepted')) as accepted,
                    count(s.id) filter (where s.status = 'rejected') as rejected,
                    count(s.id) filter (where s.status = 'pending') as pending
               from ai_requests r left join users u on u.id = r.user_id left join ai_suggestions s on s.ai_request_id = r.id and s.deleted_at is null
              where r.deleted_at is null group by r.id, u.full_name order by r.created_at desc limit 30 offset ".(($page - 1) * 30));

        return response()->json(['data' => array_map(fn ($r) => [...(array) $r, 'suggestions' => (int) $r->suggestions, 'accepted' => (int) $r->accepted,
            'rejected' => (int) $r->rejected, 'pending' => (int) $r->pending], $rows),
            'meta' => ['page' => $page, 'total' => $total, 'pages' => (int) max(1, ceil($total / 30))]]);
    }

    /** Sugerencias pendientes de una solicitud (para retomar desde el historial). */
    /**
     * SCR-IAA-06 «Consumo» y §11: solicitudes del mes contra el cupo del plan, por mes y caso de uso (últimos 6 meses),
     * por persona y la tasa de aceptación de las propuestas. Sin precios.
     */
    public function usage(): JsonResponse
    {
        ['used' => $used, 'limit' => $limit] = Usage::month();
        $months = DB::select("select to_char(date_trunc('month', created_at at time zone 'America/Lima'), 'YYYY-MM') as month, feature, count(*)::int as n
                                from ai_requests where deleted_at is null and created_at >= date_trunc('month', now()) - interval '5 months'
                               group by 1, 2 order by 1, 2");
        $people = DB::select("select coalesce(u.full_name, 'Sin usuario') as name, count(*)::int as n,
                                     count(*) filter (where exists (select 1 from ai_suggestions s where s.ai_request_id = r.id and s.status = 'accepted'))::int as accepted
                                from ai_requests r left join users u on u.id = r.user_id
                               where r.deleted_at is null and r.created_at >= date_trunc('month', now()) - interval '5 months'
                               group by 1 order by 2 desc limit 20");
        $s = DB::selectOne("select count(*) filter (where status = 'accepted')::int as accepted, count(*) filter (where status = 'rejected')::int as rejected,
                                   count(*) filter (where status = 'pending')::int as pending from ai_suggestions where deleted_at is null
                               and created_at >= date_trunc('month', now()) - interval '5 months'");
        $decided = $s->accepted + $s->rejected;

        return response()->json(['data' => ['used' => $used, 'limit' => $limit, 'months' => $months, 'people' => $people,
            'suggestions' => ['accepted' => $s->accepted, 'rejected' => $s->rejected, 'pending' => $s->pending,
                'acceptance_pct' => $decided ? round(100 * $s->accepted / $decided) : null]]]);
    }

    public function suggestions(string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            throw ApiProblem::notFound('La solicitud no existe.');
        }

        return response()->json(['data' => array_map(fn ($s) => $this->present($s),
            DB::select('select * from ai_suggestions where ai_request_id = ? and deleted_at is null order by created_at', [$id]))]);
    }

    // ── Apoyo ──────────────────────────────────────────────────────────────

    private function request(string $feature, string $module, string $subjectType, string $subjectId, string $summary, float $started): string
    {
        $ctx = app(CompanyContext::class);
        $user = (string) auth()->id();

        return DB::selectOne(
            "insert into ai_requests (company_id, user_id, feature, module_code, subject_type, subject_id, provider, model, prompt_template_code, prompt_version,
                input_redacted, contains_medical_data, input_summary, latency_ms, status, completed_at, created_by, updated_by)
             values (?, ?, ?, ?, ?, ?, 'escudosst_library', 'biblioteca', ?, '1', true, false, ?, ?, 'completed', now(), ?, ?) returning id",
            [$ctx->companyId, $user, $feature, $module, $subjectType, $subjectId, $feature, mb_substr($summary, 0, 200), (int) ((microtime(true) - $started) * 1000), $user, $user]
        )->id;
    }

    private function suggestion(string $reqId, string $type, string $targetType, ?string $targetId, array $payload, ?string $rationale): array
    {
        $ctx = app(CompanyContext::class);
        $confidence = $payload['confidence'] ?? null;
        unset($payload['confidence'], $payload['rationale']);
        $s = DB::selectOne(
            "insert into ai_suggestions (company_id, ai_request_id, suggestion_type, target_type, target_id, payload, rationale, confidence, status, expires_at, created_by, updated_by)
             values (?, ?, ?, ?, ?, ?::jsonb, ?, ?, 'pending', now() + interval '30 days', ?, ?) returning *",
            [$ctx->companyId, $reqId, $type, $targetType, $targetId, json_encode($payload, JSON_UNESCAPED_UNICODE), $rationale, $confidence, (string) auth()->id(), (string) auth()->id()]);

        return $this->present($s);
    }

    private function present(object $s): array
    {
        return ['id' => $s->id, 'type' => $s->suggestion_type, 'target_id' => $s->target_id, 'status' => $s->status, 'rationale' => $s->rationale,
            'confidence' => $s->confidence === null ? null : (float) $s->confidence, 'payload' => json_decode($s->payload, true)];
    }

    private function needs(string $module, string $name): void
    {
        if (! DB::selectOne("select 1 as ok from feature_flags where module_code = ? and mode = 'enabled' and deleted_at is null", [$module])) {
            throw ApiProblem::forbidden("Tu plan no tiene {$name} habilitado.");
        }
    }

    private function noHealth(string $text, string $field): void
    {
        if (preg_match('/\b(diagn[oó]stico|hipertensi[oó]n|diabetes|embarazo|gestante|VIH|c[aá]ncer|depresi[oó]n)\b/iu', $text)) {
            $msg = 'No escribas datos de salud de personas: el asistente no los necesita.';
            throw new ApiProblem(422, 'validation_failed', 'Revisa el texto', $msg, ['errors' => [$field => [$msg]]]);
        }
    }

    private function lower(string $s): string
    {
        return mb_strtolower(mb_substr($s, 0, 1)).mb_substr($s, 1);
    }
}
