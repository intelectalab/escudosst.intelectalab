<?php

namespace App\Modules\AUL;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Aula virtual (AUL) para quien aprende:
 *  GET  /aul/my                                         mis cursos (matrículas del trabajador o usuario)
 *  GET  /aul/enrollments/{id}                           el curso: secciones, lecciones y mi avance (el examen sin respuestas)
 *  POST /aul/enrollments/{id}/lessons/{lesson}/done     marcar una lección como vista
 *  POST /aul/enrollments/{id}/lessons/{lesson}/attempts iniciar (o retomar) un intento: preguntas sorteadas y tiempo límite
 *  PUT  /aul/enrollments/{id}/attempts/{attempt}        autoguardado {answers}
 *  POST /aul/enrollments/{id}/lessons/{lesson}/exam     enviar el intento {attempt_id?, answers} → nota y aprobado (o «por calificar»)
 *  POST /aul/enrollments/{id}/heartbeat                 latido de permanencia {client_session_id, seq, lesson_id?, interactions, video_seconds}
 *  PUT  /aul/questions/{id}/options                     alternativas de una pregunta [{text, correct}] o respuestas aceptadas
 * El curso se completa cuando están vistas todas las lecciones obligatorias y aprobados sus exámenes.
 */
class AulController extends Controller
{
    public function my(): JsonResponse
    {
        [$worker, $user] = $this->me();

        return response()->json(['data' => DB::select(
            "select e.id, e.status, e.progress_pct, e.final_score, e.passed, e.completed_at, e.access_until, c.title, c.hours, c.summary
               from course_enrollments e join courses c on c.id = e.course_id
              where e.deleted_at is null and e.status <> 'cancelled' and (e.worker_id = ? or e.user_id = ?) order by e.enrolled_at desc",
            [$worker, $user]
        )]);
    }

    /** SCR-AUL-21: «Recomendados para ti» (al verlos quedan vistos). */
    public function myRecommendations(): JsonResponse
    {
        [$worker] = $this->me();
        if (! $worker) {
            return response()->json(['data' => []]);
        }
        $rows = DB::select(
            "select r.id, r.reason_code, r.message, r.status, c.id as course_id, c.title, c.summary, c.hours, c.allow_self_enrollment
               from course_recommendations r join courses c on c.id = r.course_id and c.deleted_at is null and c.status = 'published'
              where r.worker_id = ? and r.deleted_at is null and r.status in ('sent', 'viewed', 'clicked') and (r.expires_at is null or r.expires_at > now())
              order by r.created_at desc limit 20", [$worker]);
        if ($rows) {
            DB::update("update course_recommendations set status = 'viewed', viewed_at = coalesce(viewed_at, now()) where id = any(?::uuid[]) and status = 'sent'",
                ['{'.implode(',', array_column($rows, 'id')).'}']);
        }

        return response()->json(['data' => $rows]);
    }

    /** «Matricularme»: el trabajador se matricula desde la recomendación si el curso permite automatrícula (queda como convertida). */
    public function enrollFromRecommendation(string $id): JsonResponse
    {
        $r = $this->myRecommendation($id);
        $c = DB::selectOne("select id, allow_self_enrollment from courses where id = ? and status = 'published' and deleted_at is null", [$r->course_id]);
        if (! $c || ! $c->allow_self_enrollment) {
            throw new ApiProblem(409, 'conflict', 'Sin automatrícula', 'Este curso no permite matricularse por cuenta propia: pídelo al área de SST.');
        }
        [$worker, $user] = $this->me();
        $existing = DB::selectOne("select id from course_enrollments where course_id = ? and worker_id = ? and deleted_at is null and status <> 'cancelled'", [$c->id, $worker]);
        $eid = $existing?->id ?? DB::selectOne("insert into course_enrollments (company_id, course_id, worker_id, user_id, source, enrolled_by, enrolled_at, status, created_by)
                                                 values (?, ?, ?, ?, 'self_enrollment', ?, now(), 'enrolled', ?) returning id",
            [$r->company_id, $c->id, $worker, $user, $user, $user])->id;
        DB::update("update course_recommendations set status = 'converted', clicked_at = coalesce(clicked_at, now()), converted_at = now(), converted_enrollment_id = ? where id = ?", [$eid, $r->id]);

        return response()->json(['data' => ['enrollment_id' => $eid]], 201);
    }

    /** «No me interesa»: la descarta con motivo (cuenta en el reporte de conversión). */
    public function dismissRecommendation(Request $request, string $id): JsonResponse
    {
        $r = $this->myRecommendation($id);
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);
        DB::update("update course_recommendations set status = 'dismissed', dismissed_at = now(), dismiss_reason = ? where id = ?", [$d['reason'] ?? 'No me interesa', $r->id]);

        return response()->json(['data' => ['dismissed' => true]]);
    }

    private function myRecommendation(string $id): object
    {
        [$worker] = $this->me();
        $r = Str::isUuid($id) && $worker ? DB::selectOne("select * from course_recommendations where id = ? and worker_id = ? and deleted_at is null and status in ('sent', 'viewed', 'clicked')", [$id, $worker]) : null;

        return $r ?? throw ApiProblem::notFound('La recomendación no existe o ya no está vigente.');
    }

    public function show(string $id): JsonResponse
    {
        $e = $this->enrollment($id);
        // RN-AUL-16: con compromiso obligatorio el contenido no se abre hasta aceptarlo.
        if ($c = $this->pendingCommitment($e)) {
            $course = DB::selectOne('select id, title, summary, hours from courses where id = ?', [$e->course_id]);

            return response()->json(['data' => ['enrollment' => ['id' => $e->id, 'status' => $e->status], 'course' => $course, 'sections' => [], 'lessons' => [],
                'commitment' => ['required' => true, 'text' => $c->commitment_text]]]);
        }

        return response()->json(['data' => self::player($e->course_id, $e)]);
    }

    /** POST /aul/enrollments/{id}/commitment — acepta el compromiso de culminación {target_date?}; guarda el texto aceptado y la fecha. */
    public function commitment(Request $request, string $id): JsonResponse
    {
        $e = $this->enrollment($id);
        $d = $request->validate(['target_date' => ['nullable', 'date', 'after_or_equal:today']]);
        $c = DB::selectOne('select completion_commitment, commitment_text from courses where id = ?', [$e->course_id]);
        if (! $c || $c->completion_commitment === 'none' || ! $c->completion_commitment) {
            throw new ApiProblem(409, 'conflict', 'Sin compromiso', 'Este curso no tiene compromiso de culminación.');
        }
        if ($e->commitment_accepted_at) {
            throw new ApiProblem(409, 'conflict', 'Ya aceptado', 'Ya aceptaste el compromiso de este curso.');
        }
        DB::insert('insert into completion_commitments (company_id, enrollment_id, course_id, commitment_text_snapshot, text_version, accepted_at, accepted_by_user_id, ip_hash, user_agent, created_by)
                    values (?, ?, ?, ?, ?, now(), ?, decode(?, \'hex\'), ?, ?)',
            [$e->company_id, $e->id, $e->course_id, $c->commitment_text, 1, (string) auth()->id(),
                hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')), mb_substr((string) $request->userAgent(), 0, 300), (string) auth()->id()]);
        DB::update('update course_enrollments set commitment_accepted_at = now(), due_on = coalesce(?::date, due_on), updated_at = now() where id = ?', [$d['target_date'] ?? null, $e->id]);

        return response()->json(['data' => ['accepted' => true]], 201);
    }

    /** Curso con compromiso obligatorio aún no aceptado. */
    private function pendingCommitment(object $e): ?object
    {
        if ($e->commitment_accepted_at) {
            return null;
        }
        $c = DB::selectOne('select completion_commitment, commitment_text from courses where id = ?', [$e->course_id]);

        return $c && $c->completion_commitment === 'mandatory' ? $c : null;
    }

    private function requireCommitment(object $e): void
    {
        if ($this->pendingCommitment($e)) {
            throw new ApiProblem(409, 'commitment_required', 'Acepta el compromiso', 'Antes de empezar el curso acepta el compromiso de culminación.');
        }
    }

    /**
     * El curso como lo ve quien aprende. Con $e = null es la vista previa del administrador (sin matrícula ni avance).
     * Las evaluaciones no traen preguntas: se sortean al iniciar el intento (RN-AUL-13).
     */
    public static function player(string $courseId, ?object $e): array
    {
        $course = DB::selectOne('select id, title, summary, description, hours, passing_score, sequential, min_active_time_pct, completion_commitment from courses where id = ?', [$courseId]);
        $progress = $e ? (json_decode($e->lesson_progress ?? '{}', true) ?: []) : [];
        $sections = DB::select('select id, section_number, title, description, unlock_rule, unlock_on from course_sections where course_id = ? and deleted_at is null and is_visible order by sort_order, section_number', [$courseId]);
        $lessons = DB::select('select id, course_id, section_id, lesson_number, title, lesson_type, content_html, video_provider, video_external_id, external_url, file_id, duration_minutes, is_required,
                                      question_bank_id, exam_config, passing_score, max_attempts, time_limit_minutes from course_lessons where course_id = ? and deleted_at is null order by sort_order, lesson_number', [$courseId]);
        foreach ($lessons as $l) {
            $l->done = ! empty($progress[$l->id]['done']);
            $l->best_score = $progress[$l->id]['score'] ?? null;
            if ($l->lesson_type === 'exam') {
                $cfg = ExamEngine::config($l);
                $l->num_questions = (int) ($cfg['num_questions'] ?? 0) ?: null;
                $l->max_attempts = ExamEngine::maxAttempts($l);
                $l->attempts_used = $e ? ExamEngine::attemptsUsed($e->id, $l->id) : 0;
                $l->pending_review = $e ? (bool) DB::selectOne("select 1 as ok from quiz_attempts where enrollment_id = ? and lesson_id = ? and deleted_at is null and status = 'submitted'", [$e->id, $l->id]) : false;
                $open = $e ? ExamEngine::openAttempt($e->id, $l->id) : null;
                $l->open_attempt = $open && ! ExamEngine::expired($open) ? self::attemptView($open) : null;
                $last = $e ? DB::selectOne("select score, max_score, grade_label, passed, feedback from quiz_attempts where enrollment_id = ? and lesson_id = ? and deleted_at is null and status = 'graded'
                                             order by attempt_number desc limit 1", [$e->id, $l->id]) : null;
                $l->last_result = $last ? ['score_pct' => (float) $last->max_score > 0 ? round((float) $last->score * 100 / (float) $last->max_score, 2) : null, 'label' => $last->grade_label,
                    'passed' => (bool) $last->passed, 'feedback' => $last->feedback] : null;
            }
            unset($l->exam_config, $l->question_bank_id, $l->course_id);
        }
        foreach (self::locks($course, $sections, $lessons, $progress) as $lid => $why) {
            foreach ($lessons as $l) {
                if ($l->id === $lid) {
                    $l->locked = $why !== null;
                    $l->locked_reason = $why;
                }
            }
        }

        return ['enrollment' => $e ? ['id' => $e->id, 'status' => $e->status, 'progress_pct' => (float) $e->progress_pct, 'final_score' => $e->final_score, 'final_score_label' => $e->final_score_label,
            'passed' => $e->passed, 'certificate_id' => $e->certificate_id, 'active_seconds_total' => (int) $e->active_seconds_total] : null,
            'course' => $course, 'sections' => $sections, 'lessons' => $lessons, 'heartbeat_seconds' => Heartbeats::beatSeconds()];
    }

    /**
     * RN-AUL-05: qué lección está bloqueada y por qué. Cada sección respeta su unlock_rule (siempre, al completar las obligatorias de
     * la sección anterior, o desde una fecha); con recorrido secuencial, una lección obligatoria se abre al completar la obligatoria
     * anterior. @return array<string, ?string> id de lección => motivo del bloqueo (null = abierta)
     */
    public static function locks(object $course, array $sections, array $lessons, array $progress): array
    {
        $done = fn ($l) => ! empty($progress[$l->id]['done']);
        $order = array_flip(array_map(fn ($s) => $s->id, $sections));
        usort($lessons, fn ($a, $b) => ($order[$a->section_id] ?? -1) <=> ($order[$b->section_id] ?? -1));
        $today = now('America/Lima')->format('Y-m-d');
        $sectionOpen = [];
        $prevDone = true;
        foreach ($sections as $s) {
            $required = array_filter($lessons, fn ($l) => $l->section_id === $s->id && $l->is_required);
            $why = match ($s->unlock_rule ?? 'always') {
                'after_previous' => $prevDone ? null : 'Se abre al completar la sección anterior.',
                'from_date' => $s->unlock_on && $s->unlock_on > $today ? 'Se abre el '.date('d/m/Y', strtotime($s->unlock_on)).'.' : null,
                default => null,
            };
            $sectionOpen[$s->id] = $why;
            $prevDone = $why === null && ! array_filter($required, fn ($l) => ! $done($l));
        }
        $out = [];
        $blockedBy = null;
        foreach ($lessons as $l) {
            $why = $l->section_id ? ($sectionOpen[$l->section_id] ?? null) : null;
            if ($why === null && $course->sequential && $blockedBy) {
                $why = 'Se abre al completar «'.$blockedBy.'».';
            }
            $out[$l->id] = $why;
            if ($course->sequential && $l->is_required && ! $done($l) && ! $blockedBy) {
                $blockedBy = $l->title;
            }
        }

        return $out;
    }

    /** RN-AUL-05/09: el servidor no acepta avance en una lección bloqueada. */
    private function assertOpen(object $e, object $l): void
    {
        $course = DB::selectOne('select sequential from courses where id = ?', [$e->course_id]);
        $sections = DB::select('select id, unlock_rule, unlock_on from course_sections where course_id = ? and deleted_at is null and is_visible order by sort_order, section_number', [$e->course_id]);
        $lessons = DB::select('select id, section_id, title, is_required from course_lessons where course_id = ? and deleted_at is null order by sort_order, lesson_number', [$e->course_id]);
        $why = self::locks($course, $sections, $lessons, json_decode($e->lesson_progress ?? '{}', true) ?: [])[$l->id] ?? null;
        if ($why !== null) {
            throw new ApiProblem(409, 'locked', 'Lección bloqueada', $why);
        }
    }

    public static function attemptView(object $a): array
    {
        $saved = json_decode($a->answers ?? '{}', true) ?: [];

        return ['id' => $a->id, 'attempt_number' => (int) $a->attempt_number, 'started_at' => $a->started_at, 'expires_at' => $a->expires_at,
            'questions' => ExamEngine::publicQuestions(json_decode($a->question_set ?? '[]', true) ?: []), 'answers' => (object) ($saved['given'] ?? $saved)];
    }

    public function done(string $id, string $lesson): JsonResponse
    {
        $e = $this->enrollment($id);
        $l = $this->lesson($e, $lesson);
        if ($l->lesson_type === 'exam') {
            throw new ApiProblem(409, 'conflict', 'Es un examen', 'Esta lección es un examen: se completa al aprobarlo.');
        }
        $this->requireCommitment($e);
        $this->assertOpen($e, $l);
        // RN-AUL-04/09: el servidor comprueba que la lección se vio: texto y documento con su permanencia mínima, video con su % reproducido.
        $seen = DB::selectOne('select coalesce(sum(active_seconds), 0) as active, coalesce(max(video_max_position_seconds), 0) as pos, coalesce(sum(video_seconds), 0) as video
                                 from learning_activity_sessions where enrollment_id = ? and lesson_id = ? and deleted_at is null and not is_flagged', [$e->id, $l->id]);
        if (in_array($l->lesson_type, ['text', 'document'], true)) {
            $min = (int) \App\Core\Support\LegalParams::number('aul_min_active_seconds', 30);
            if ((int) $seen->active < $min) {
                throw new ApiProblem(409, 'not_seen', 'Aún no se completa', 'Lee el contenido un poco más: faltan '.($min - (int) $seen->active).' segundos de permanencia.');
            }
        }
        if ($l->lesson_type === 'video' && (int) $l->duration_minutes > 0) {
            $pct = (int) ($l->min_view_pct ?: \App\Core\Support\LegalParams::number('aul_min_video_pct', 90));
            $watched = max((int) $seen->pos, (int) $seen->video) * 100 / ((int) $l->duration_minutes * 60);
            if ($watched + 0.01 < $pct) {
                throw new ApiProblem(409, 'not_seen', 'Aún no se completa', "Mira el video hasta el final: llevas ".((int) round($watched))." % y se pide {$pct} %.");
            }
        }

        return response()->json(['data' => Progress::mark($e, $l->id, ['done' => true, 'at' => now()->toIso8601String()])]);
    }

    /** Inicia (o retoma) un intento con las preguntas sorteadas. */
    public function startAttempt(string $id, string $lesson): JsonResponse
    {
        $e = $this->enrollment($id);
        $this->requireCommitment($e);
        $l = $this->examLesson($e, $lesson);
        $this->assertOpen($e, $l);
        $a = DB::transaction(fn () => ExamEngine::start($e, $l));

        return response()->json(['data' => self::attemptView($a)], 201);
    }

    /** Autoguardado de respuestas de un intento abierto. */
    public function saveAnswers(Request $request, string $id, string $attempt): JsonResponse
    {
        $e = $this->enrollment($id);
        $a = $this->attempt($e, $attempt);
        ExamEngine::save($a, (array) $request->input('answers', []));

        return response()->json(['data' => ['saved' => true]]);
    }

    /** Envía el intento {attempt_id?, answers}: sin intento abierto se sortea uno (compatibilidad). */
    public function exam(Request $request, string $id, string $lesson): JsonResponse
    {
        $e = $this->enrollment($id);
        $l = $this->examLesson($e, $lesson);
        $res = DB::transaction(function () use ($request, $e, $l) {
            $aid = $request->input('attempt_id');
            $a = is_string($aid) && $aid !== '' ? $this->attempt($e, $aid) : (ExamEngine::openAttempt($e->id, $l->id) ?? ExamEngine::start($e, $l));
            if ($a->lesson_id !== $l->id) {
                throw ApiProblem::notFound('El intento no es de esta evaluación.');
            }

            return ExamEngine::submit($e, $l, $a, $request->has('answers') ? (array) $request->input('answers', []) : null);
        });

        return response()->json(['data' => $res]);
    }

    /** Latido de actividad (RN-AUL-07/08/09). */
    public function heartbeat(Request $request, string $id): JsonResponse
    {
        $e = $this->enrollment($id);
        $this->requireCommitment($e);
        $d = $request->validate([
            'client_session_id' => ['required', 'string', 'max:70', 'regex:/^[A-Za-z0-9_\-:.]+$/'], 'lesson_id' => ['nullable', 'uuid'], 'seq' => ['required', 'integer', 'min:1'],
            'interactions' => ['nullable', 'integer', 'min:0', 'max:10000'], 'video_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'visible' => ['boolean'], 'ended' => ['boolean'], 'device_type' => ['nullable', 'in:desktop,mobile,tablet,other'],
        ]);
        if (! empty($d['lesson_id'])) {
            $this->lesson($e, $d['lesson_id']);
        }

        return response()->json(['data' => DB::transaction(fn () => Heartbeats::beat($e, $d))]);
    }

    public function options(Request $request, string $id): JsonResponse
    {
        if (! in_array('aul.questions.update', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no edita preguntas.');
        }
        $q = Str::isUuid($id) ? DB::selectOne('select * from questions where id = ? and deleted_at is null', [$id]) : null;
        if (! $q) {
            throw ApiProblem::notFound('La pregunta no existe o es de otra empresa.');
        }
        if ($q->question_type === 'short_answer') {
            $d = $request->validate(['accepted' => ['required', 'array', 'min:1'], 'accepted.*' => ['required', 'string', 'max:200']], ['accepted.required' => 'Escribe al menos una respuesta aceptada.']);
            DB::update('update questions set accepted_answers = ?::jsonb, updated_by = ? where id = ?', [json_encode(array_values($d['accepted']), JSON_UNESCAPED_UNICODE), (string) auth()->id(), $id]);

            return response()->json(['data' => ['accepted' => $d['accepted']]]);
        }
        $d = $request->validate(['options' => ['required', 'array', 'min:2', 'max:8'], 'options.*.text' => ['required', 'string', 'max:300'], 'options.*.correct' => ['boolean']],
            ['options.min' => 'Agrega al menos dos alternativas.']);
        $correct = count(array_filter($d['options'], fn ($o) => ! empty($o['correct'])));
        if ($correct === 0 || (in_array($q->question_type, ['single_choice', 'true_false'], true) && $correct !== 1)) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa las alternativas', $q->question_type === 'multiple_choice' ? 'Marca al menos una alternativa correcta.' : 'Marca una sola alternativa correcta.',
                ['errors' => ['options' => ['Marca la alternativa correcta.']]]);
        }
        $opts = array_map(fn ($o) => ['text' => trim($o['text']), 'correct' => (bool) ($o['correct'] ?? false)], $d['options']);
        DB::update('update questions set options = ?::jsonb, updated_by = ? where id = ?', [json_encode($opts, JSON_UNESCAPED_UNICODE), (string) auth()->id(), $id]);

        return response()->json(['data' => ['options' => $opts]]);
    }

    private function me(): array
    {
        $ctx = app(CompanyContext::class);

        return [DB::selectOne('select worker_id from memberships where id = ?', [$ctx->membershipId])?->worker_id, (string) auth()->id()];
    }

    private function enrollment(string $id): object
    {
        $e = Str::isUuid($id) ? DB::selectOne('select * from course_enrollments where id = ? and deleted_at is null', [$id]) : null;
        if (! $e) {
            throw ApiProblem::notFound('La matrícula no existe o es de otra empresa.');
        }
        [$worker, $user] = $this->me();
        $mine = ($worker && $e->worker_id === $worker) || $e->user_id === $user;
        if (! $mine && ! in_array('aul.course_enrollments.update', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Este curso es de otra persona.');
        }
        if ($e->status === 'cancelled' || ($e->access_until && strtotime($e->access_until) < time())) {
            throw new ApiProblem(409, 'conflict', 'Sin acceso', 'La matrícula está anulada o venció su acceso.');
        }

        return $e;
    }

    private function examLesson(object $e, string $lesson): object
    {
        $l = $this->lesson($e, $lesson);
        if ($l->lesson_type !== 'exam' || ! ExamEngine::banks($l)) {
            throw new ApiProblem(409, 'conflict', 'No es un examen', 'Esta lección no tiene examen.');
        }

        return $l;
    }

    private function attempt(object $e, string $attempt): object
    {
        $a = Str::isUuid($attempt) ? DB::selectOne('select * from quiz_attempts where id = ? and enrollment_id = ? and deleted_at is null', [$attempt, $e->id]) : null;

        return $a ?? throw ApiProblem::notFound('El intento no existe o es de otra persona.');
    }

    private function lesson(object $e, string $lesson): object
    {
        $l = Str::isUuid($lesson) ? DB::selectOne('select * from course_lessons where id = ? and course_id = ? and deleted_at is null', [$lesson, $e->course_id]) : null;

        return $l ?? throw ApiProblem::notFound('La lección no existe en este curso.');
    }
}
