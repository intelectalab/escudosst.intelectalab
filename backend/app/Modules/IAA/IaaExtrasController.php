<?php

namespace App\Modules\IAA;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\REC\RecordsService;
use App\Modules\REC\Registry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Asistente (M-IAA), con la biblioteca y reglas de EscudoSST:
 *  POST /iaa/document/read {file_id}       SCR-IAA-03: lee un PDF (p. ej. requerimiento SUNAFIL) y propone los datos del expediente
 *  POST /iaa/courses/suggest {position_id?} SCR-IAA-04: propone cursos del aula por cargo según los peligros de su IPERC y las
 *                                           capacitaciones exigidas; al aceptarlas van a la bandeja de recomendaciones por aprobar
 * Todo pasa antes por el redactor (RN-IAA-03) y queda como sugerencia pendiente (RN-IAA-01).
 */
class IaaExtrasController extends Controller
{
    public function readDocument(Request $request): JsonResponse
    {
        Usage::guard();
        $d = $request->validate(['file_id' => ['required', 'uuid']], ['file_id.required' => 'Sube el documento PDF.']);
        $f = DB::selectOne('select id, bucket, object_key, original_name, mime_type, is_medical from files where id = ? and deleted_at is null', [$d['file_id']]);
        if (! $f) {
            throw ApiProblem::notFound('El archivo no existe.');
        }
        if ($f->is_medical) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa el archivo', 'El asistente no lee documentos médicos.', ['errors' => ['file_id' => ['Documento médico.']]]);
        }
        $raw = Storage::disk($f->bucket)->exists($f->object_key) ? Storage::disk($f->bucket)->get($f->object_key) : '';
        if (! str_starts_with((string) $raw, '%PDF')) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa el archivo', 'El archivo no es un PDF.', ['errors' => ['file_id' => ['No es un PDF.']]]);
        }
        $started = microtime(true);
        // Texto del PDF; si es escaneado (foto) se lee con OCR gratuito en el servidor.
        [$text, $ocr] = PdfText::read($raw);
        if (mb_strlen(trim($text)) < 20) {
            throw new ApiProblem(422, 'validation_failed', 'No se pudo leer', 'No se pudo leer el documento (la imagen está borrosa o no tiene texto): escribe los datos a mano.', ['errors' => ['file_id' => ['PDF sin texto.']]]);
        }
        if (preg_match('/\b(diagn[oó]stico|hipertensi[oó]n|diabetes|embarazo|gestante|VIH|c[aá]ncer|depresi[oó]n)\b/iu', $text)) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa el archivo', 'El documento tiene datos de salud: el asistente no lo procesa.', ['errors' => ['file_id' => ['Datos de salud.']]]);
        }
        [$clean, $found] = Redactor::redact($text);
        $fields = self::sunafilFields($clean);
        $req = $this->request('document_reading', 'SUN', 'files', $f->id, 'Lectura de '.$f->original_name, $started, $found);
        $s = $this->suggestion($req, 'dossier_fields', 'inspection_dossiers', null, $fields + ['file_id' => $f->id],
            $ocr ? 'Datos leídos de un documento escaneado (OCR). Revísalos con cuidado antes de crear el expediente: la lectura de imágenes puede confundir letras.'
                : 'Datos leídos del documento. Revísalos antes de crear el expediente: el asistente puede equivocarse.');

        return response()->json(['data' => ['suggestion' => $s, 'redacted' => $found, 'ocr' => $ocr, 'preview' => mb_substr($clean, 0, 1200)]], 201);
    }

    /** Campos de un requerimiento de SUNAFIL (orden, inspector, comparecencia, periodo y documentos pedidos). */
    public static function sunafilFields(string $t): array
    {
        $one = fn (string $re) => preg_match($re, $t, $m) ? trim($m[1]) : null;
        $order = $one('/orden\s+de\s+inspecci[oó]n\s*(?:n[°ºo.]*\s*)?[:\-]?\s*([0-9][\w\-\/]*)/iu');
        $inspector = $one('/inspector(?:a)?(?:\s+(?:auxiliar|del\s+trabajo|comisionad[oa]))?\s*[:\-]\s*([^\n\[]{4,80})/iu');
        $date = $one('/comparecencia[^\n]{0,80}?(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4})/iu') ?? $one('/(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4})/u');
        $time = $one('/comparecencia[^\n]{0,120}?(\d{1,2}:\d{2})/iu') ?? $one('/\b(\d{1,2}:\d{2})\s*(?:horas|hrs|h\b|a\.?\s?m|p\.?\s?m)/iu');
        $appearance = null;
        if ($date) {
            [$dd, $mm, $yy] = preg_split('/[\/\-]/', $date);
            $appearance = sprintf('%04d-%02d-%02d', $yy, $mm, $dd).($time ? 'T'.str_pad($time, 5, '0', STR_PAD_LEFT).':00' : 'T09:00:00');
        }
        $period = preg_match('/periodo[^\n]{0,40}?(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4})[^\n]{0,20}?(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4})/iu', $t, $pm)
            ? array_map(function ($x) { [$d, $m, $y] = preg_split('/[\/\-]/', $x); return sprintf('%04d-%02d-%02d', $y, $m, $d); }, [$pm[1], $pm[2]]) : null;
        // Documentos pedidos: líneas numeradas o con viñeta.
        $docs = [];
        foreach (preg_split('/\n/', $t) as $line) {
            if (preg_match('/^\s*(?:\d{1,2}[\.\)\-]|[a-z]\)|[•\-–])\s*(.{6,200})$/u', $line, $lm)) {
                $docs[] = trim($lm[1]);
            }
        }

        return ['inspection_order_number' => $order, 'inspector_name' => $inspector, 'appearance_at' => $appearance,
            'period_from' => $period[0] ?? null, 'period_to' => $period[1] ?? null, 'requested_documents' => array_slice($docs, 0, 40),
            'confidence' => round((($order ? 1 : 0) + ($appearance ? 1 : 0) + ($inspector ? 1 : 0) + ($docs ? 1 : 0)) / 4, 2)];
    }

    /** Aplica la lectura: crea el expediente en cola con los datos (editables por la persona antes de aceptar). */
    public static function applyDossier(object $s, array $payload, array $edit): array
    {
        $v = array_merge($payload, array_intersect_key($edit, array_flip(['inspection_order_number', 'inspector_name', 'appearance_at', 'period_from', 'period_to', 'title'])));
        $year = (int) date('Y');
        $row = app(RecordsService::class)->create(Registry::resource('SUN', 'inspection_dossiers'), array_filter([
            'title' => $v['title'] ?? ('Requerimiento SUNAFIL'.(! empty($v['inspection_order_number']) ? ' · Orden '.$v['inspection_order_number'] : '')),
            'request_type' => 'sunafil_inspection',
            'inspection_order_number' => $v['inspection_order_number'] ?? null,
            'inspector_name' => $v['inspector_name'] ?? null,
            'appearance_at' => $v['appearance_at'] ?? null,
            'requirement_file_id' => $payload['file_id'] ?? null,
            'period_from' => $v['period_from'] ?? "{$year}-01-01",
            'period_to' => $v['period_to'] ?? date('Y-m-d'),
        ], fn ($x) => $x !== null && $x !== ''));

        return ['entity' => 'inspection_dossiers', 'ids' => [$row['id']]];
    }

    public function courses(Request $request): JsonResponse
    {
        Usage::guard();
        $d = $request->validate(['position_id' => ['nullable', 'uuid']]);
        $started = microtime(true);
        $courses = DB::select("select id, title, coalesce(target_audience, '') || ' ' || coalesce(category, '') || ' ' || coalesce(summary, '') as extra from courses
                                where deleted_at is null and status = 'published'");
        $positions = DB::select('select p.id, p.name from positions p where p.deleted_at is null'.(! empty($d['position_id']) ? ' and p.id = ?' : '').' order by p.name limit 200',
            ! empty($d['position_id']) ? [$d['position_id']] : []);
        $req = $this->request('other', 'AUL', 'positions', $d['position_id'] ?? null, 'Cursos sugeridos por cargo', $started, []);
        $out = [];
        foreach ($positions as $p) {
            // Datos seudonimizados (RN-IAA-09): solo el cargo, sus peligros y capacitaciones exigidas; nunca nombres ni salud.
            $ctx = mb_strtolower($p->name.' '.implode(' ', array_column(DB::select(
                "select r.hazard || ' ' || coalesce(r.risk, '') as t from iperc_rows r join iperc_matrices m on m.current_version_id = r.iperc_version_id
                  where m.deleted_at is null and r.deleted_at is null and (m.position_id = ? or r.position_id = ?) limit 80", [$p->id, $p->id]), 't'))
                .' '.implode(' ', array_column(DB::select('select t.title from position_training_requirements q join trainings t on t.id = q.training_id
                                                           where q.position_id = ? and q.deleted_at is null', [$p->id]), 'title')));
            $workers = array_column(DB::select("select id from workers where current_position_id = ? and status = 'active' and deleted_at is null", [$p->id]), 'id');
            if (! $workers) {
                continue;
            }
            foreach ($courses as $c) {
                $hits = array_values(array_filter(self::words($c->title.' '.$c->extra), fn ($w) => str_contains($ctx, $w)));
                if (count($hits) < 1) {
                    continue;
                }
                $pending = array_column(DB::select("select w.id from workers w where w.id = any(?::uuid[])
                    and not exists (select 1 from course_enrollments e where e.worker_id = w.id and e.course_id = ? and e.deleted_at is null and e.status <> 'cancelled')
                    and not exists (select 1 from course_recommendations r where r.worker_id = w.id and r.course_id = ? and r.deleted_at is null and r.status not in ('dismissed', 'expired'))",
                    ['{'.implode(',', $workers).'}', $c->id, $c->id]), 'id');
                if (! $pending) {
                    continue;
                }
                // Si ya hay una sugerencia pendiente igual, se muestra esa (no se duplica).
                $same = DB::selectOne("select * from ai_suggestions where suggestion_type = 'course_recommendation' and status = 'pending' and deleted_at is null
                                        and target_id = ? and payload->>'course_id' = ? limit 1", [$p->id, $c->id]);
                if ($same) {
                    $out[] = ['id' => $same->id, 'type' => $same->suggestion_type, 'target_id' => $same->target_id, 'status' => $same->status, 'rationale' => $same->rationale,
                        'confidence' => $same->confidence === null ? null : (float) $same->confidence, 'payload' => json_decode($same->payload, true)];

                    continue;
                }
                $out[] = $this->suggestion($req, 'course_recommendation', 'positions', $p->id, [
                    'course_id' => $c->id, 'course' => $c->title, 'position_id' => $p->id, 'position' => $p->name, 'workers' => count($pending),
                    'confidence' => min(1, round(count($hits) / 3, 2)),
                ], "El cargo «{$p->name}» tiene peligros o capacitaciones relacionados con: ".implode(', ', array_slice($hits, 0, 4)).'. '.count($pending).' trabajador(es) aún no lo llevan.');
            }
        }

        return response()->json(['data' => ['suggestions' => $out]], 201);
    }

    /** Al aceptar: una recomendación «sugerida» por trabajador en la bandeja del aula; solo se envía cuando alguien la aprueba allí. */
    public static function applyCourse(object $s, array $payload): array
    {
        $ctx = app(CompanyContext::class);
        $uid = (string) auth()->id();
        $ids = [];
        foreach (DB::select("select w.id from workers w where w.current_position_id = ? and w.status = 'active' and w.deleted_at is null
                              and not exists (select 1 from course_recommendations r where r.worker_id = w.id and r.course_id = ? and r.deleted_at is null and r.status not in ('dismissed', 'expired'))",
            [$payload['position_id'], $payload['course_id']]) as $w) {
            $ids[] = DB::selectOne("insert into course_recommendations (company_id, course_id, worker_id, source, recommended_by, target_scope, target_position_id, reason_code, message,
                                        ai_suggestion_id, channel, status, dedupe_key, created_by, updated_by)
                                    values (?, ?, ?, 'ai', ?, 'position', ?, 'ai_position_match', ?, ?, 'in_app', 'suggested', ?, ?, ?) returning id",
                [$ctx->companyId, $payload['course_id'], $w->id, $uid, $payload['position_id'], mb_substr((string) $s->rationale, 0, 500), $s->id,
                    'ai:'.$payload['course_id'].':'.$w->id, $uid, $uid])->id;
        }

        return ['entity' => 'course_recommendations', 'ids' => $ids];
    }

    /** Palabras significativas (5+ letras, sin las comunes). */
    private static function words(string $s): array
    {
        $stop = ['curso', 'cursos', 'básico', 'básicos', 'basico', 'conceptos', 'personal', 'trabajo', 'trabajos', 'virtual', 'general', 'seguridad', 'salud', 'correcto', 'sobre', 'para'];
        $w = preg_split('/[^\p{L}]+/u', mb_strtolower($s)) ?: [];

        return array_values(array_unique(array_filter($w, fn ($x) => mb_strlen($x) >= 5 && ! in_array($x, $stop, true))));
    }

    private function request(string $feature, string $module, string $subjectType, ?string $subjectId, string $summary, float $started, array $found): string
    {
        $ctx = app(CompanyContext::class);
        $user = (string) auth()->id();

        return DB::selectOne(
            "insert into ai_requests (company_id, user_id, feature, module_code, subject_type, subject_id, provider, model, prompt_template_code, prompt_version,
                input_redacted, contains_medical_data, input_summary, latency_ms, status, completed_at, created_by, updated_by)
             values (?, ?, ?, ?, ?, ?, 'escudosst_library', 'reglas', ?, '1', true, false, ?, ?, 'completed', now(), ?, ?) returning id",
            [$ctx->companyId, $user, $feature, $module, $subjectType, $subjectId, $feature, mb_substr($summary.($found ? ' (datos ocultos: '.implode(', ', $found).')' : ''), 0, 200),
                (int) ((microtime(true) - $started) * 1000), $user, $user]
        )->id;
    }

    private function suggestion(string $reqId, string $type, string $targetType, ?string $targetId, array $payload, ?string $rationale): array
    {
        $ctx = app(CompanyContext::class);
        $confidence = $payload['confidence'] ?? null;
        unset($payload['confidence']);
        $s = DB::selectOne(
            "insert into ai_suggestions (company_id, ai_request_id, suggestion_type, target_type, target_id, payload, rationale, confidence, status, expires_at, created_by, updated_by)
             values (?, ?, ?, ?, ?, ?::jsonb, ?, ?, 'pending', now() + interval '30 days', ?, ?) returning *",
            [$ctx->companyId, $reqId, $type, $targetType, $targetId, json_encode($payload, JSON_UNESCAPED_UNICODE), $rationale, $confidence, (string) auth()->id(), (string) auth()->id()]);

        return ['id' => $s->id, 'type' => $s->suggestion_type, 'target_id' => $s->target_id, 'status' => $s->status, 'rationale' => $s->rationale,
            'confidence' => $s->confidence === null ? null : (float) $s->confidence, 'payload' => json_decode($s->payload, true)];
    }
}
