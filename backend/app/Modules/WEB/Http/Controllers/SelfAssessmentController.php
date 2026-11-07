<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use App\Modules\WEB\Mail\SiteRequestMail;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Autodiagnóstico Ley 29783 (SCR-WEB-29 pública /herramientas/autodiagnostico, SCR-WEB-42 panel; RN-WEB-37 a 39).
 *  Público: GET  /public/tools/self-assessment     cuestionario publicado (sin pesos)
 *           POST /public/tools/self-assessment     respuestas + datos de contacto → puntaje, nivel y pendientes por sección
 *  Panel:   GET  /internal/site/self-assessment                     versiones, envíos por nivel y últimos envíos
 *           GET  /internal/site/self-assessment/{id}                 versión con sus preguntas
 *           POST /internal/site/self-assessment/{id}/copy            nueva versión en borrador a partir de otra
 *           PATCH /internal/site/self-assessment/{id}                datos de la versión (borrador)
 *           PUT  /internal/site/self-assessment/{id}/questions       preguntas de la versión (borrador)
 *           POST /internal/site/self-assessment/{id}/publish         publicar (solo el Dueño); la anterior se archiva
 *
 * Una versión publicada no se edita (lo impide la base); los cambios van en una versión nueva. El informe se envía por
 * correo con el resultado y el informe en PDF (guardado privado, se descarga con un enlace firmado que vence a las
 * 72 horas). No pregunta por personas ni datos de salud (RN-WEB-56).
 *  GET /public/tools/self-assessment/report/{id}   PDF, solo con firma vigente
 */
class SelfAssessmentController extends Controller
{
    public const LEVELS = ['low' => 'Bajo', 'medium' => 'Medio', 'high' => 'Alto'];

    private const MESSAGES = [
        'required' => 'Completa este campo.',
        'email' => 'Escribe un correo válido.',
        'max' => 'Es muy largo.',
        'min' => 'Es muy corto.',
        'in' => 'Elige una opción de la lista.',
        'accepted' => 'Para recibir tu resultado, acepta la política de privacidad.',
        'numeric' => 'Escribe un número.',
        'between' => 'Debe estar entre :min y :max.',
    ];

    private function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    // ---------------------------------------------------------------- Público

    public function show(): JsonResponse
    {
        $r = json_decode($this->db()->selectOne('select public_self_assessment() as r')->r, true);
        if (($r['result'] ?? '') !== 'ok') {
            return response()->json(['data' => null])->header('Cache-Control', 'public, max-age=60');
        }
        unset($r['result']);

        return response()->json(['data' => $r])->header('Cache-Control', 'public, max-age=60');
    }

    public function submit(Request $request): JsonResponse
    {
        if (filled($request->input('website'))) {
            throw ApiProblem::notFound('No disponible.');
        }
        $d = $request->validate([
            'questionnaire_id' => ['required', 'uuid'],
            'answers' => ['required', 'array', 'min:1'],
            'answers.*' => ['in:yes,partial,no'],
            'full_name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'email', 'max:160'],
            'company_name' => ['required', 'string', 'min:2', 'max:160'],
            'sector' => ['nullable', 'in:'.implode(',', SuccessStoriesController::SECTORS)],
            'workers_range' => ['nullable', 'string', 'max:20'],
            'privacy_consent' => ['accepted'],
            'marketing_consent' => ['boolean'],
        ], self::MESSAGES + ['answers.required' => 'Responde las preguntas.']);

        $questions = $this->db()->select(
            'select id, section, question_text from assessment_questions where questionnaire_id = ? and deleted_at is null order by sort_order',
            [$d['questionnaire_id']]
        );
        $missing = array_filter($questions, fn ($q) => ! isset($d['answers'][$q->id]));
        if ($missing) {
            throw new ApiProblem(422, 'validation_failed', 'Faltan respuestas', 'Responde todas las preguntas.', ['errors' => ['answers' => ['Faltan '.count($missing).' preguntas por responder.']]]);
        }
        $payload = $d + ['privacy_consent' => true, 'marketing_consent' => $request->boolean('marketing_consent'), 'source_page' => '/herramientas/autodiagnostico'];
        $ipHash = hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
        try {
            $r = json_decode($this->db()->selectOne(
                "select public_submit_self_assessment(?::jsonb, decode(?, 'hex'), ?) as r",
                [json_encode($payload, JSON_UNESCAPED_UNICODE), $ipHash, (string) Str::uuid7()]
            )->r, true);
        } catch (\Illuminate\Database\QueryException $e) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa tus respuestas', 'El cuestionario cambió o hay respuestas inválidas. Recarga la página.', ['errors' => ['answers' => ['Recarga la página e inténtalo de nuevo.']]]);
        }

        // Pendientes por sección: lo respondido «no» o «en parte», para que el resultado diga qué hacer.
        $pending = [];
        foreach ($questions as $q) {
            $a = $d['answers'][$q->id];
            if ($a !== 'yes') {
                $pending[$q->section][] = ['question' => $q->question_text, 'answer' => $a];
            }
        }
        $r['pending'] = $pending;
        $r['level_label'] = self::LEVELS[$r['level']] ?? $r['level'];
        $r['report_url'] = null;
        if (! ($r['replay'] ?? false)) {
            $r['report_url'] = $this->buildReport($d, $r);
            $this->mailReport($d, $r);
        }

        return response()->json(['data' => $r]);
    }

    /** Genera el PDF, lo guarda privado y devuelve el enlace firmado (o null si falló: el resultado igual se muestra). */
    private function buildReport(array $d, array $r): ?string
    {
        try {
            $s = (array) $this->db()->selectOne('select legal_name, ruc, trade_name, primary_domain from site_settings where singleton');
            $q = $this->db()->selectOne('select version, to_validate from assessment_questionnaires where id = ?', [$d['questionnaire_id']]);
            $messages = ['low' => 'Tienes brechas importantes: conviene empezar pronto.', 'medium' => 'Vas avanzando, pero hay puntos que una inspección observaría.', 'high' => 'Buen nivel. Mantén los registros al día.'];
            $html = view('reports.self-assessment', [
                'r' => $r, 'company' => $d['company_name'], 'brand' => $s['trade_name'] ?? 'EscudoSST', 'version' => $q->version,
                'toValidate' => (bool) $q->to_validate, 'date' => now()->format('d/m/Y'), 'message' => $messages[$r['level']] ?? '',
                'site' => rtrim((string) config('escudo.frontend_url'), '/'),
                'legal' => ($s['legal_name'] ?? '').(($s['ruc'] ?? null) ? ' · RUC '.$s['ruc'] : ''),
            ])->render();
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4');
            $dompdf->render();
            $pdf = $dompdf->output();

            $disk = config('escudo.site_assets_disk', 'local');
            $fileId = (string) Str::uuid7();
            $path = "private/report-{$fileId}.pdf";
            Storage::disk($disk)->put($path, $pdf);
            $this->db()->insert(
                "insert into site_assets (id, kind, disk, path, mime_type, size_bytes, original_name) values (?, 'report', ?, ?, 'application/pdf', ?, ?)",
                [$fileId, $disk, $path, strlen($pdf), 'autodiagnostico-ley-29783.pdf']
            );
            $this->db()->update('update assessment_submissions set report_file_id = ?, report_sent_at = now() where id = ?', [$fileId, $r['id']]);

            return URL::temporarySignedRoute('public.self-assessment.report', now()->addHours(72), ['id' => $r['id']]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo generar el informe PDF del autodiagnóstico', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** Descarga del informe: solo con enlace firmado vigente (middleware «signed»). */
    public function report(string $id): StreamedResponse
    {
        $a = $this->db()->selectOne(
            'select a.disk, a.path from assessment_submissions s join site_assets a on a.id = s.report_file_id where s.id = ?', [$id]
        );
        if (! $a || ! Storage::disk($a->disk)->exists($a->path)) {
            throw ApiProblem::notFound('El informe no está disponible.');
        }

        return Storage::disk($a->disk)->download($a->path, 'autodiagnostico-ley-29783.pdf', [
            'Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function mailReport(array $d, array $r): void
    {
        $rows = [['Puntaje total', $r['score_total'].' %'], ['Nivel', $r['level_label']]];
        foreach (($r['score_by_section'] ?? []) as $section => $pct) {
            $rows[] = [$section, $pct.' %'];
        }
        $todo = [];
        foreach ($r['pending'] as $section => $items) {
            foreach ($items as $it) {
                $todo[] = "• {$section}: ".$it['question'].($it['answer'] === 'partial' ? ' (en parte)' : '');
            }
        }
        $note = ($todo ? "Lo que te falta trabajar:\n".implode("\n", array_slice($todo, 0, 15))."\n\n" : '')
            .'Resultado referencial'.(! empty($r['to_validate']) ? '; cuestionario en validación por especialista SST' : '').'. No es una auditoría ni asesoría legal.';
        try {
            Mail::to($d['email'])->send(new SiteRequestMail(
                explode(' ', trim($d['full_name']))[0], 'Tu autodiagnóstico de la Ley 29783',
                "Gracias por responder el autodiagnóstico para {$d['company_name']}. Este es tu resultado por sección.",
                $rows, $note.($r['report_url'] ? "\n\nEl enlace del informe en PDF vence en 72 horas." : ''),
                $r['report_url'] ? 'Descargar mi informe (PDF)' : 'Ver cómo cerrar las brechas',
                $r['report_url'] ?: rtrim((string) config('escudo.frontend_url'), '/').'/demo'
            ));
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar el informe del autodiagnóstico', ['error' => $e->getMessage()]);
        }
    }

    // ---------------------------------------------------------------- Panel

    public function index(): JsonResponse
    {
        $versions = $this->db()->select(
            'select q.id, q.title, q.version, q.status, q.to_validate, q.published_at, q.archived_at, q.updated_at,
                    (select count(*) from assessment_questions a where a.questionnaire_id = q.id and a.deleted_at is null) as questions,
                    (select count(*) from assessment_submissions s where s.questionnaire_id = q.id) as submissions
               from assessment_questionnaires q where q.deleted_at is null order by q.version desc'
        );
        $levels = $this->db()->select('select level, count(*) as n, round(avg(score_total), 1) as avg from assessment_submissions group by level');
        $recent = $this->db()->select(
            'select s.created_at, s.score_total, s.level, s.score_by_section_json, l.full_name, l.company_name, q.version
               from assessment_submissions s left join marketing_leads l on l.id = s.lead_id join assessment_questionnaires q on q.id = s.questionnaire_id
              order by s.created_at desc limit 20'
        );

        return response()->json(['data' => ['versions' => $versions, 'levels' => $levels, 'recent' => $recent]]);
    }

    public function showVersion(string $id): JsonResponse
    {
        $q = $this->find($id);

        return response()->json(['data' => (array) $q + ['questions' => $this->db()->select(
            'select id, section, question_text, help_text, weight, answer_type, sort_order, legal_reference
               from assessment_questions where questionnaire_id = ? and deleted_at is null order by sort_order',
            [$id]
        )]]);
    }

    /** Nueva versión en borrador copiando preguntas de otra (la publicada no se edita). */
    public function copy(Request $request, string $id, AccountsService $accounts): JsonResponse
    {
        $src = $this->find($id);
        if ($this->db()->selectOne("select 1 from assessment_questionnaires where status = 'draft' and deleted_at is null")) {
            $this->fail('id', 'Ya hay una versión en borrador: edítala o publícala antes de crear otra.');
        }
        $actor = $request->user()->id;
        $newId = $this->db()->transaction(function () use ($src, $actor) {
            $next = (int) $this->db()->selectOne('select coalesce(max(version), 0) + 1 as v from assessment_questionnaires')->v;
            $newId = $this->db()->selectOne(
                "insert into assessment_questionnaires (title, version, description, status, to_validate, level_thresholds_json, created_by, updated_by)
                 values (?, ?, ?, 'draft', ?, ?::jsonb, ?, ?) returning id",
                [$src->title, $next, $src->description, $src->to_validate, $src->level_thresholds_json, $actor, $actor]
            )->id;
            $this->db()->insert(
                'insert into assessment_questions (questionnaire_id, section, question_text, help_text, weight, answer_type, sort_order, legal_reference, created_by)
                 select ?, section, question_text, help_text, weight, answer_type, sort_order, legal_reference, ? from assessment_questions
                  where questionnaire_id = ? and deleted_at is null',
                [$newId, $actor, $src->id]
            );

            return $newId;
        });
        $accounts->audit($actor, 'self_assessment_version_created', 'assessment_questionnaires', $newId, ['from' => $src->id]);

        return response()->json(['data' => ['id' => $newId]], 201);
    }

    public function update(Request $request, string $id, AccountsService $accounts): Response
    {
        $q = $this->draft($id);
        $d = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:140'],
            'description' => ['nullable', 'string', 'max:800'],
            'to_validate' => ['boolean'],
            'low_max' => ['required', 'numeric', 'between:1,98'],
            'medium_max' => ['required', 'numeric', 'between:2,99'],
        ], self::MESSAGES);
        if ($d['medium_max'] <= $d['low_max']) {
            $this->fail('medium_max', 'El límite del nivel medio debe ser mayor que el del bajo.');
        }
        $this->db()->update(
            'update assessment_questionnaires set title = ?, description = ?, to_validate = ?, level_thresholds_json = ?::jsonb, updated_by = ? where id = ?',
            [trim($d['title']), $d['description'] ?? null, (bool) ($d['to_validate'] ?? true), json_encode(['low_max' => 0 + $d['low_max'], 'medium_max' => 0 + $d['medium_max']]), $request->user()->id, $q->id]
        );
        $accounts->audit($request->user()->id, 'self_assessment_version_updated', 'assessment_questionnaires', $id, []);

        return response()->noContent();
    }

    /** Reemplaza las preguntas del borrador (en el orden enviado). */
    public function saveQuestions(Request $request, string $id, AccountsService $accounts): Response
    {
        $this->draft($id);
        $d = $request->validate([
            'questions' => ['required', 'array', 'min:1', 'max:80'],
            'questions.*.section' => ['required', 'string', 'min:2', 'max:80'],
            'questions.*.question_text' => ['required', 'string', 'min:10', 'max:300'],
            'questions.*.help_text' => ['nullable', 'string', 'max:500'],
            'questions.*.weight' => ['required', 'numeric', 'between:0.1,10'],
            'questions.*.answer_type' => ['required', 'in:yes_no,yes_partial_no'],
            'questions.*.legal_reference' => ['nullable', 'string', 'max:200'],
        ], self::MESSAGES + ['questions.*.question_text.required' => 'Escribe la pregunta.', 'questions.*.section.required' => 'Indica la sección.']);
        $actor = $request->user()->id;
        $this->db()->transaction(function () use ($d, $id, $actor) {
            $this->db()->update('update assessment_questions set deleted_at = now(), updated_by = ? where questionnaire_id = ? and deleted_at is null', [$actor, $id]);
            foreach (array_values($d['questions']) as $i => $q) {
                $this->db()->insert(
                    'insert into assessment_questions (questionnaire_id, section, question_text, help_text, weight, answer_type, sort_order, legal_reference, created_by)
                     values (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id, trim($q['section']), trim($q['question_text']), ($q['help_text'] ?? null) ?: null, $q['weight'], $q['answer_type'], $i, ($q['legal_reference'] ?? null) ?: null, $actor]
                );
            }
        });
        $accounts->audit($actor, 'self_assessment_questions_saved', 'assessment_questionnaires', $id, ['questions' => count($d['questions'])]);

        return response()->noContent();
    }

    public function publish(Request $request, string $id, AccountsService $accounts): JsonResponse
    {
        $this->draft($id);
        $db = $this->db();
        $r = $db->transaction(function () use ($db, $id, $request) {
            $db->select("select set_config('app.user_id', ?, true)", [$request->user()->id]);

            return json_decode($db->selectOne('select web_publish_questionnaire(?) as r', [$id])->r, true);
        });
        $accounts->audit($request->user()->id, 'self_assessment_published', 'assessment_questionnaires', $id, ['version' => $r['version'] ?? null]);

        return response()->json(['data' => $r]);
    }

    // ---------------------------------------------------------------- Apoyo

    private function find(string $id): object
    {
        return $this->db()->selectOne('select * from assessment_questionnaires where id = ? and deleted_at is null', [$id]) ?? throw ApiProblem::notFound('La versión no existe.');
    }

    private function draft(string $id): object
    {
        $q = $this->find($id);
        if ($q->status !== 'draft') {
            $this->fail('id', 'Una versión publicada o archivada no se edita: crea una versión nueva.');
        }

        return $q;
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}
