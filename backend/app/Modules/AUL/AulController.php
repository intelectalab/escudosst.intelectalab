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

    public function show(string $id): JsonResponse
    {
        $e = $this->enrollment($id);

        return response()->json(['data' => self::player($e->course_id, $e)]);
    }

    /**
     * El curso como lo ve quien aprende. Con $e = null es la vista previa del administrador (sin matrícula ni avance).
     * Las evaluaciones no traen preguntas: se sortean al iniciar el intento (RN-AUL-13).
     */
    public static function player(string $courseId, ?object $e): array
    {
        $course = DB::selectOne('select id, title, summary, description, hours, passing_score, sequential, min_active_time_pct, completion_commitment from courses where id = ?', [$courseId]);
        $progress = $e ? (json_decode($e->lesson_progress ?? '{}', true) ?: []) : [];
        $sections = DB::select('select id, section_number, title, description from course_sections where course_id = ? and deleted_at is null and is_visible order by sort_order, section_number', [$courseId]);
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

        return ['enrollment' => $e ? ['id' => $e->id, 'status' => $e->status, 'progress_pct' => (float) $e->progress_pct, 'final_score' => $e->final_score, 'final_score_label' => $e->final_score_label,
            'passed' => $e->passed, 'certificate_id' => $e->certificate_id, 'active_seconds_total' => (int) $e->active_seconds_total] : null,
            'course' => $course, 'sections' => $sections, 'lessons' => $lessons, 'heartbeat_seconds' => Heartbeats::beatSeconds()];
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

        return response()->json(['data' => Progress::mark($e, $l->id, ['done' => true, 'at' => now()->toIso8601String()])]);
    }

    /** Inicia (o retoma) un intento con las preguntas sorteadas. */
    public function startAttempt(string $id, string $lesson): JsonResponse
    {
        $e = $this->enrollment($id);
        $l = $this->examLesson($e, $lesson);
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
