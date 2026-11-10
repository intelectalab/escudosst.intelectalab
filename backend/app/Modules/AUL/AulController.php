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
 *  POST /aul/enrollments/{id}/lessons/{lesson}/exam     rendir el examen {answers: {pregunta: respuesta}} → nota y aprobado
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
        $course = DB::selectOne('select id, title, summary, description, hours, passing_score, sequential from courses where id = ?', [$e->course_id]);
        $progress = json_decode($e->lesson_progress ?? '{}', true) ?: [];
        $sections = DB::select('select id, section_number, title, description from course_sections where course_id = ? and deleted_at is null and is_visible order by sort_order, section_number', [$e->course_id]);
        $lessons = DB::select('select id, section_id, lesson_number, title, lesson_type, content_html, video_provider, video_external_id, external_url, file_id, duration_minutes, is_required,
                                      question_bank_id, passing_score, max_attempts from course_lessons where course_id = ? and deleted_at is null order by sort_order, lesson_number', [$e->course_id]);
        foreach ($lessons as $l) {
            $l->done = ! empty($progress[$l->id]['done']);
            $l->best_score = $progress[$l->id]['score'] ?? null;
            if ($l->lesson_type === 'exam' && $l->question_bank_id) {
                $l->questions = array_map(function ($q) {
                    $opts = json_decode($q->options ?? '[]', true) ?: [];

                    return ['id' => $q->id, 'type' => $q->question_type, 'statement' => $q->statement, 'points' => (float) $q->points,
                        'options' => array_map(fn ($o, $i) => ['key' => (string) $i, 'text' => is_array($o) ? ($o['text'] ?? '') : (string) $o], $opts, array_keys($opts))];
                }, DB::select("select id, question_type, statement, options, points from questions where question_bank_id = ? and deleted_at is null and status = 'active' order by code nulls last, created_at", [$l->question_bank_id]));
            }
        }

        return response()->json(['data' => ['enrollment' => ['id' => $e->id, 'status' => $e->status, 'progress_pct' => (float) $e->progress_pct, 'final_score' => $e->final_score, 'passed' => $e->passed],
            'course' => $course, 'sections' => $sections, 'lessons' => $lessons]]);
    }

    public function done(string $id, string $lesson): JsonResponse
    {
        $e = $this->enrollment($id);
        $l = $this->lesson($e, $lesson);
        if ($l->lesson_type === 'exam') {
            throw new ApiProblem(409, 'conflict', 'Es un examen', 'Esta lección es un examen: se completa al aprobarlo.');
        }

        return response()->json(['data' => $this->mark($e, $l->id, ['done' => true, 'at' => now()->toIso8601String()])]);
    }

    public function exam(Request $request, string $id, string $lesson): JsonResponse
    {
        $e = $this->enrollment($id);
        $l = $this->lesson($e, $lesson);
        if ($l->lesson_type !== 'exam' || ! $l->question_bank_id) {
            throw new ApiProblem(409, 'conflict', 'No es un examen', 'Esta lección no tiene examen.');
        }
        $attempts = (int) DB::selectOne('select count(*) as n from quiz_attempts where enrollment_id = ? and lesson_id = ? and deleted_at is null', [$e->id, $l->id])->n;
        if ($l->max_attempts && $attempts >= (int) $l->max_attempts) {
            throw new ApiProblem(409, 'conflict', 'Sin intentos', "Ya usaste los {$l->max_attempts} intentos de este examen.");
        }
        $answers = (array) $request->input('answers', []);
        $score = 0.0;
        $max = 0.0;
        $detail = [];
        foreach (DB::select("select * from questions where question_bank_id = ? and deleted_at is null and status = 'active'", [$l->question_bank_id]) as $q) {
            $max += (float) $q->points;
            $given = $answers[$q->id] ?? null;
            $ok = false;
            if ($q->question_type === 'short_answer') {
                $acc = array_map(fn ($a) => mb_strtolower(trim((string) $a)), json_decode($q->accepted_answers ?? '[]', true) ?: []);
                $ok = $given !== null && in_array(mb_strtolower(trim((string) $given)), $acc, true);
            } else {
                $opts = json_decode($q->options ?? '[]', true) ?: [];
                $correct = array_map('strval', array_keys(array_filter($opts, fn ($o) => is_array($o) && ! empty($o['correct']))));
                $chosen = array_map('strval', (array) $given);
                sort($correct);
                sort($chosen);
                $ok = $correct !== [] && $correct === $chosen;
            }
            if ($ok) {
                $score += (float) $q->points;
            }
            $detail[$q->id] = ['given' => $given, 'ok' => $ok];
        }
        if ($max <= 0) {
            throw new ApiProblem(409, 'conflict', 'Examen sin preguntas', 'El examen todavía no tiene preguntas activas.');
        }
        $pct = round($score * 100 / $max, 2);
        $passing = (float) ($l->passing_score ?? DB::selectOne('select passing_score from courses where id = ?', [$e->course_id])?->passing_score ?? 70);
        $passed = $pct >= $passing;
        DB::insert(
            "insert into quiz_attempts (company_id, enrollment_id, lesson_id, source, attempt_number, started_at, submitted_at, score, max_score, passed, answers, graded_at, status, created_by, updated_by)
             values (?, ?, ?, 'internal', ?, now(), now(), ?, ?, ?, ?::jsonb, now(), 'graded', ?, ?)",
            [$e->company_id, $e->id, $l->id, $attempts + 1, round($score, 2), round($max, 2), $passed ? 'true' : 'false', json_encode($detail), (string) auth()->id(), (string) auth()->id()]
        );
        $state = $passed ? $this->mark($e, $l->id, ['done' => true, 'score' => $pct, 'at' => now()->toIso8601String()]) : null;

        return response()->json(['data' => ['score_pct' => $pct, 'passing' => $passing, 'passed' => $passed, 'attempt' => $attempts + 1, 'enrollment' => $state]]);
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

    /** Guarda el avance de una lección y recalcula el avance del curso. */
    private function mark(object $e, string $lessonId, array $state): array
    {
        $progress = json_decode($e->lesson_progress ?? '{}', true) ?: [];
        $progress[$lessonId] = array_merge($progress[$lessonId] ?? [], $state);
        $required = DB::select('select id from course_lessons where course_id = ? and deleted_at is null and is_required', [$e->course_id]);
        $done = count(array_filter($required, fn ($r) => ! empty($progress[$r->id]['done'])));
        $pct = $required ? round($done * 100 / count($required), 2) : 100;
        $complete = $required && $done === count($required);
        $scores = array_filter(array_map(fn ($p) => $p['score'] ?? null, $progress), fn ($v) => $v !== null);
        $final = $scores ? round(array_sum($scores) / count($scores), 2) : null;
        DB::update("update course_enrollments set lesson_progress = ?::jsonb, progress_pct = ?, last_activity_at = now(), started_at = coalesce(started_at, now()),
                      status = ?, completed_at = case when ? then coalesce(completed_at, now()) else completed_at end, final_score = coalesce(?, final_score), passed = case when ? then true else passed end
                    where id = ?",
            [json_encode($progress), $pct, $complete ? 'completed' : 'in_progress', $complete ? 'true' : 'false', $final, $complete ? 'true' : 'false', $e->id]);

        return ['progress_pct' => $pct, 'status' => $complete ? 'completed' : 'in_progress', 'final_score' => $final];
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

    private function lesson(object $e, string $lesson): object
    {
        $l = Str::isUuid($lesson) ? DB::selectOne('select * from course_lessons where id = ? and course_id = ? and deleted_at is null', [$lesson, $e->course_id]) : null;

        return $l ?? throw ApiProblem::notFound('La lección no existe en este curso.');
    }
}
