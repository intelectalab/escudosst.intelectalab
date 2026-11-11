<?php

namespace App\Modules\AUL;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Herramientas del administrador y del capacitador del aula (M-AUL):
 *  PUT    /aul/admin/lessons/{id}/exam                    configuración de una evaluación: bancos, número al azar, mezcla por dificultad,
 *                                                         mezclar opciones, tiempo, intentos, nota, peso, retroalimentación, práctica
 *  GET    /aul/admin/courses/{id}/exam-stats              estadística por pregunta de cada evaluación (SCR-AUL-07)
 *  GET    /aul/admin/courses/{id}/time                    tiempo de permanencia por participante vs. mínimo del curso (SCR-AUL-14); ?format=csv
 *  GET    /aul/admin/courses/{id}/preview                 «Ver como participante»: el curso como lo ve quien aprende, sin matrícula
 *  GET    /aul/admin/courses/{id}/preview/lessons/{l}     preguntas de muestra sorteadas de una evaluación (no se guarda nada)
 *  POST   /aul/admin/enrollments/{id}/extend              {access_until, reason} extender acceso (SCR-AUL-05, en la bitácora)
 *  POST   /aul/admin/enrollments/{id}/reset-attempts      {lesson_id?, reason} reiniciar intentos (RN-AUL-15, en la bitácora)
 *  GET    /aul/admin/grading                              «Por calificar»: intentos enviados de preguntas abiertas o evaluaciones prácticas
 *  GET    /aul/admin/attempts/{id}                        intento con preguntas y respuestas para calificar
 *  POST   /aul/admin/attempts/{id}/grade                  {question_points? | score_pct?, feedback?} calificación manual
 *  GET    /aul/admin/scales                               escalas de calificación (del sistema y de la empresa) (SCR-AUL-10)
 *  POST   /aul/admin/scales                               nueva escala
 *  PUT    /aul/admin/scales/{id}                          editar escala (no si un curso con calificaciones la usa)
 *  DELETE /aul/admin/scales/{id}                          archivar escala (no si un curso la usa)
 *  POST   /aul/admin/rules                                nueva regla de recomendación con sus cursos y parámetros (SCR-AUL-15)
 *  PUT    /aul/admin/rules/{id}                           editar la regla (cursos, parámetros, canales, límite de envíos)
 *  POST   /aul/admin/rules/{id}/run                       {dry_run?} ejecutar ahora una regla de recomendación (o vista previa)
 */
class AulToolsController extends Controller
{
    // ─────────────── Configuración de la evaluación (SCR-AUL-07) ───────────────

    public function examConfig(Request $request, string $id): JsonResponse
    {
        $l = Str::isUuid($id) ? DB::selectOne("select * from course_lessons where id = ? and deleted_at is null and lesson_type = 'exam'", [$id]) : null;
        if (! $l) {
            throw ApiProblem::notFound('La evaluación no existe.');
        }
        $this->course($l->course_id);
        TrainerAccess::require($l->course_id, 'can_edit_assessments', 'No tienes permiso para configurar las evaluaciones de este curso.');
        $d = $request->validate([
            'question_bank_id' => ['required', 'uuid'], 'bank_ids' => ['nullable', 'array', 'max:10'], 'bank_ids.*' => ['uuid'],
            'num_questions' => ['required', 'integer', 'min:1', 'max:200'], 'shuffle_questions' => ['boolean'], 'shuffle_options' => ['boolean'],
            'show_feedback' => ['nullable', 'in:on_finish,per_question,never'], 'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'difficulty_mix' => ['nullable', 'array'], 'difficulty_mix.easy' => ['nullable', 'integer', 'min:0', 'max:200'], 'difficulty_mix.medium' => ['nullable', 'integer', 'min:0', 'max:200'],
            'difficulty_mix.hard' => ['nullable', 'integer', 'min:0', 'max:200'], 'manual_grading' => ['boolean'],
            'passing_score' => ['nullable', 'numeric', 'min:0', 'max:100'], 'max_attempts' => ['nullable', 'integer', 'min:1', 'max:20'], 'time_limit_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
        ], ['question_bank_id.required' => 'Elige el banco de preguntas.', 'num_questions.required' => 'Indica cuántas preguntas se sortean.']);
        $banks = array_values(array_unique(array_merge([$d['question_bank_id']], $d['bank_ids'] ?? [])));
        $ok = DB::select('select id from question_banks where deleted_at is null and (course_id is null or course_id = ?) and id in ('.implode(',', array_fill(0, count($banks), '?')).')', [$l->course_id, ...$banks]);
        if (count($ok) !== count($banks)) {
            $this->fail('question_bank_id', 'Elige bancos generales o de este curso.');
        }
        $mix = array_filter(array_map('intval', (array) ($d['difficulty_mix'] ?? [])), fn ($v) => $v > 0);
        if (array_sum($mix) > (int) $d['num_questions']) {
            $this->fail('difficulty_mix', 'La mezcla por dificultad suma más preguntas que las que se sortean.');
        }
        $cfg = ['num_questions' => (int) $d['num_questions'], 'shuffle_questions' => (bool) ($d['shuffle_questions'] ?? true), 'shuffle_options' => (bool) ($d['shuffle_options'] ?? true),
            'show_feedback' => $d['show_feedback'] ?? 'on_finish', 'weight' => (float) ($d['weight'] ?? 1), 'bank_ids' => array_values(array_diff($banks, [$d['question_bank_id']])),
            'difficulty_mix' => (object) $mix, 'manual_grading' => (bool) ($d['manual_grading'] ?? false)];
        DB::update('update course_lessons set question_bank_id = ?, exam_config = ?::jsonb, passing_score = ?, max_attempts = ?, time_limit_minutes = ?, updated_by = ?, updated_at = now() where id = ?',
            [$d['question_bank_id'], json_encode($cfg), $d['passing_score'] ?? null, $d['max_attempts'] ?? null, $d['time_limit_minutes'] ?? null, (string) auth()->id(), $id]);
        $available = (int) DB::selectOne("select count(*) as n from questions where deleted_at is null and status = 'active' and question_bank_id in (".implode(',', array_fill(0, count($banks), '?')).')', $banks)->n;

        return response()->json(['data' => ['exam_config' => $cfg, 'available_questions' => $available]]);
    }

    // ─────────────── Estadística por pregunta (SCR-AUL-07) ───────────────

    public function examStats(string $id): JsonResponse
    {
        $this->course($id);
        $exams = DB::select("select id, title from course_lessons where course_id = ? and deleted_at is null and lesson_type = 'exam' order by sort_order, lesson_number", [$id]);
        $out = [];
        foreach ($exams as $ex) {
            $attempts = DB::select("select question_set, answers from quiz_attempts where lesson_id = ? and deleted_at is null and status in ('graded', 'submitted') order by submitted_at desc limit 5000", [$ex->id]);
            $stats = [];
            foreach ($attempts as $a) {
                $ans = json_decode($a->answers ?? '{}', true) ?: [];
                $detail = $ans['detail'] ?? array_filter($ans, fn ($v) => is_array($v) && array_key_exists('ok', $v));   // intentos antiguos: {pregunta: {given, ok}}
                $ids = array_column(json_decode($a->question_set ?? '[]', true) ?: [], 'id') ?: array_keys($detail);
                foreach ($ids as $qid) {
                    $d = $detail[$qid] ?? ['given' => null, 'ok' => false];
                    $s = &$stats[$qid];
                    $s['attempts'] = ($s['attempts'] ?? 0) + 1;
                    $s['correct'] = ($s['correct'] ?? 0) + (! empty($d['ok']) ? 1 : 0);
                    $s['blank'] = ($s['blank'] ?? 0) + (($d['given'] ?? null) === null || $d['given'] === '' || $d['given'] === [] ? 1 : 0);
                    $s['given'][] = $d['given'] ?? null;
                    unset($s);
                }
            }
            $qs = $stats ? collect(DB::select('select id, code, question_type, statement, options, difficulty from questions where id in ('.implode(',', array_fill(0, count($stats), '?')).')', array_keys($stats)))->keyBy('id') : collect();
            $rows = [];
            foreach ($stats as $qid => $s) {
                $q = $qs[$qid] ?? null;
                if (! $q) {
                    continue;
                }
                $opts = json_decode($q->options ?? '[]', true) ?: [];
                $wrong = [];
                foreach ($s['given'] as $g) {
                    if ($q->question_type === 'short_answer' || $g === null) {
                        continue;
                    }
                    foreach ((array) $g as $k) {
                        if (isset($opts[(int) $k]) && empty($opts[(int) $k]['correct'])) {
                            $wrong[(int) $k] = ($wrong[(int) $k] ?? 0) + 1;
                        }
                    }
                }
                arsort($wrong);
                $top = array_key_first($wrong);
                $rows[] = ['question_id' => $qid, 'code' => $q->code, 'statement' => $q->statement, 'type' => $q->question_type, 'difficulty' => $q->difficulty,
                    'attempts' => $s['attempts'], 'correct' => $s['correct'], 'correct_pct' => round($s['correct'] * 100 / max(1, $s['attempts']), 1), 'blank' => $s['blank'],
                    'top_wrong' => $top === null ? null : ['text' => (string) ($opts[$top]['text'] ?? ''), 'count' => $wrong[$top], 'pct' => round($wrong[$top] * 100 / max(1, $s['attempts']), 1)]];
            }
            usort($rows, fn ($a, $b) => $a['correct_pct'] <=> $b['correct_pct']);   // las más difíciles primero
            $out[] = ['lesson_id' => $ex->id, 'title' => $ex->title, 'attempts' => count($attempts), 'questions' => $rows];
        }

        return response()->json(['data' => $out]);
    }

    // ─────────────── Tiempo de permanencia (SCR-AUL-14) ───────────────

    public function timeReport(Request $request, string $id): JsonResponse|Response
    {
        $c = $this->course($id);
        TrainerAccess::require($id, 'can_view_reports', 'No tienes permiso para ver los reportes de este curso.');
        $need = ($c->min_active_time_pct !== null && $c->hours !== null) ? (int) ceil((float) $c->hours * 3600 * (float) $c->min_active_time_pct / 100) : null;
        $rows = DB::select(
            "select e.id, e.status, e.progress_pct, e.active_seconds_total, e.last_activity_at,
                    coalesce(w.full_name, cw.first_name || ' ' || cw.paternal_last_name, xl.first_name || ' ' || xl.last_name, u.full_name, 'Participante') as name,
                    p.name as position, a.name as area,
                    coalesce(s.sessions, 0) as sessions, coalesce(s.active, 0) as session_seconds, coalesce(s.video, 0) as video_seconds, coalesce(s.idle, 0) as idle_closed,
                    coalesce(s.flagged, 0) as flagged, s.first_at, s.last_at, coalesce(s.days, 0) as days
               from course_enrollments e left join workers w on w.id = e.worker_id left join contractor_workers cw on cw.id = e.contractor_worker_id
               left join external_learners xl on xl.id = e.external_learner_id left join users u on u.id = e.user_id
               left join positions p on p.id = w.current_position_id left join areas a on a.id = w.current_area_id
               left join lateral (select count(*) as sessions, sum(active_seconds) as active, sum(video_seconds) as video, count(*) filter (where end_reason = 'idle_timeout') as idle,
                                         count(*) filter (where is_flagged) as flagged, min(started_at) as first_at, max(last_heartbeat_at) as last_at,
                                         count(distinct (started_at at time zone 'America/Lima')::date) as days
                                    from learning_activity_sessions x where x.enrollment_id = e.id and x.deleted_at is null) s on true
              where e.course_id = ? and e.deleted_at is null and e.status <> 'cancelled' order by name", [$id]);
        $out = array_map(function ($r) use ($need) {
            $active = (int) $r->active_seconds_total;

            return ['enrollment_id' => $r->id, 'name' => $r->name, 'position' => $r->position, 'area' => $r->area, 'status' => $r->status, 'progress_pct' => (float) $r->progress_pct,
                'active_seconds' => $active, 'video_seconds' => (int) $r->video_seconds, 'sessions' => (int) $r->sessions, 'idle_closed' => (int) $r->idle_closed, 'flagged' => (int) $r->flagged,
                'days' => (int) $r->days, 'first_at' => $r->first_at, 'last_at' => $r->last_at ?? $r->last_activity_at,
                'required_seconds' => $need, 'compliance_pct' => $need ? min(100, round($active * 100 / $need, 1)) : null, 'complies' => $need === null ? null : $active >= $need,
                'missing_seconds' => $need === null ? null : max(0, $need - $active)];
        }, $rows);
        if ($request->query('format') === 'csv') {
            $esc = fn ($v) => '"'.str_replace('"', '""', (string) $v).'"';
            $hm = fn (?int $s) => $s === null ? '' : sprintf('%d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60));
            $lines = [implode(';', array_map($esc, ['Participante', 'Puesto', 'Área', 'Estado', 'Avance %', 'Tiempo activo (h:mm)', 'Mínimo (h:mm)', 'Cumplimiento %', 'Cumple', 'Video visto (h:mm)', 'Sesiones', 'Cerradas por inactividad', 'Días con actividad', 'Última actividad']))];
            foreach ($out as $r) {
                $lines[] = implode(';', array_map($esc, [$r['name'], $r['position'], $r['area'], $r['status'], $r['progress_pct'], $hm($r['active_seconds']), $hm($r['required_seconds']),
                    $r['compliance_pct'] ?? '', $r['complies'] === null ? 'Sin mínimo' : ($r['complies'] ? 'Sí' : 'No'), $hm($r['video_seconds']), $r['sessions'], $r['idle_closed'], $r['days'],
                    $r['last_at'] ? date('d/m/Y H:i', strtotime($r['last_at'])) : '']));
            }

            return response("\u{FEFF}".implode("\r\n", $lines), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="permanencia-'.$c->code.'.csv"']);
        }
        $days = DB::select(
            "select (x.started_at at time zone 'America/Lima')::date as day, sum(x.active_seconds) as active_seconds, count(distinct x.enrollment_id) as participants
               from learning_activity_sessions x join course_enrollments e on e.id = x.enrollment_id
              where e.course_id = ? and x.deleted_at is null and x.started_at >= now() - interval '30 days' group by 1 order by 1", [$id]);
        $with = array_filter($out, fn ($r) => $r['complies'] !== null);

        return response()->json(['data' => [
            'course' => ['id' => $c->id, 'code' => $c->code, 'title' => $c->title, 'hours' => $c->hours === null ? null : (float) $c->hours, 'min_active_time_pct' => $c->min_active_time_pct === null ? null : (float) $c->min_active_time_pct],
            'required_seconds' => $need,
            'summary' => ['participants' => count($out), 'avg_active_seconds' => $out ? (int) round(array_sum(array_column($out, 'active_seconds')) / count($out)) : 0,
                'complying' => count(array_filter($with, fn ($r) => $r['complies'])), 'not_complying' => count(array_filter($with, fn ($r) => ! $r['complies']))],
            'params' => ['heartbeat_seconds' => Heartbeats::beatSeconds(), 'idle_minutes' => intdiv(Heartbeats::idleSeconds(), 60)],
            'rows' => $out,
            'days' => array_map(fn ($d) => ['day' => $d->day, 'active_seconds' => (int) $d->active_seconds, 'participants' => (int) $d->participants], $days),
        ]]);
    }

    // ─────────────── Ver como participante (SCR-AUL-04) ───────────────

    public function preview(string $id): JsonResponse
    {
        $this->course($id);

        return response()->json(['data' => AulController::player($id, null) + ['preview' => true]]);
    }

    public function previewQuestions(string $id, string $lesson): JsonResponse
    {
        $this->course($id);
        $l = Str::isUuid($lesson) ? DB::selectOne("select * from course_lessons where id = ? and course_id = ? and deleted_at is null and lesson_type = 'exam'", [$lesson, $id]) : null;
        if (! $l) {
            throw ApiProblem::notFound('La evaluación no existe en este curso.');
        }

        return response()->json(['data' => ['questions' => ExamEngine::publicQuestions(ExamEngine::draw($l)), 'time_limit_minutes' => $l->time_limit_minutes]]);
    }

    // ─────────────── Participantes: extender acceso y reiniciar intentos (SCR-AUL-05) ───────────────

    public function extend(Request $request, string $id): JsonResponse
    {
        $e = $this->enrollment($id);
        TrainerAccess::require($e->course_id, 'can_enroll', 'Extender el acceso es del administrador del aula o del capacitador con permiso para matricular.');
        $d = $request->validate(['access_until' => ['required', 'date', 'after:today'], 'reason' => ['required', 'string', 'min:5', 'max:500']],
            ['access_until.required' => 'Indica hasta cuándo tendrá acceso.', 'access_until.after' => 'La nueva fecha debe ser futura.', 'reason.required' => 'Escribe el motivo.', 'reason.min' => 'Escribe el motivo.']);
        if (in_array($e->status, ['completed', 'cancelled'], true)) {
            throw new ApiProblem(409, 'conflict', 'Matrícula cerrada', 'La matrícula está completada o de baja: no se extiende.');
        }
        $until = date('Y-m-d 23:59:59', strtotime($d['access_until']));
        DB::update("update course_enrollments set access_until = (?::timestamp at time zone 'America/Lima'), due_on = case when due_on is not null and due_on < ?::date then ?::date else due_on end,
                      status = case when status = 'expired' then (case when started_at is null then 'enrolled' else 'in_progress' end) else status end, updated_by = ?, updated_at = now() where id = ?",
            [$until, $d['access_until'], $d['access_until'], (string) auth()->id(), $e->id]);
        $this->audit('course_enrollments.extend_access', 'course_enrollments', $e->id, ['from' => $e->access_until, 'to' => $until, 'reason' => trim($d['reason'])]);

        return response()->json(['data' => DB::selectOne('select id, status, access_until, due_on from course_enrollments where id = ?', [$e->id])]);
    }

    public function resetAttempts(Request $request, string $id): JsonResponse
    {
        $e = $this->enrollment($id);
        TrainerAccess::require($e->course_id, 'can_grade', 'Reiniciar intentos es del administrador del aula o del capacitador con permiso para calificar.');
        $d = $request->validate(['lesson_id' => ['nullable', 'uuid'], 'reason' => ['required', 'string', 'min:5', 'max:500']], ['reason.required' => 'Escribe el motivo.', 'reason.min' => 'Escribe el motivo.']);
        if ($e->status === 'completed') {
            throw new ApiProblem(409, 'conflict', 'Curso aprobado', 'La matrícula ya está completada: no se reinician sus intentos.');
        }
        $lessons = array_column(DB::select("select id from course_lessons where course_id = ? and deleted_at is null and lesson_type = 'exam'".(! empty($d['lesson_id']) ? ' and id = ?' : ''),
            array_filter([$e->course_id, $d['lesson_id'] ?? null])), 'id');
        if (! $lessons) {
            throw ApiProblem::notFound('La evaluación no existe en este curso.');
        }
        $in = implode(',', array_fill(0, count($lessons), '?'));
        $n = DB::update("update quiz_attempts set deleted_at = now(), updated_by = ?, updated_at = now() where enrollment_id = ? and lesson_id in ({$in}) and deleted_at is null and status <> 'submitted'",
            [(string) auth()->id(), $e->id, ...$lessons]);
        DB::update("update course_enrollments set status = case when status = 'failed' then 'in_progress' else status end, updated_by = ?, updated_at = now() where id = ?", [(string) auth()->id(), $e->id]);
        $this->audit('quiz_attempts.reset', 'course_enrollments', $e->id, ['lessons' => $lessons, 'attempts_reset' => $n, 'reason' => trim($d['reason'])]);

        return response()->json(['data' => ['reset' => $n, 'status' => DB::selectOne('select status from course_enrollments where id = ?', [$e->id])->status]]);
    }

    // ─────────────── Por calificar (SCR-AUL-06 y 13) ───────────────

    public function gradingQueue(): JsonResponse
    {
        $this->staff();
        [$f, $b] = $this->trainerFilter('c.id');
        $rows = DB::select(
            "select q.id, q.attempt_number, q.submitted_at, q.score, q.max_score, l.id as lesson_id, l.title as lesson, c.id as course_id, c.code as course_code, c.title as course_title,
                    coalesce(w.full_name, cw.first_name || ' ' || cw.paternal_last_name, xl.first_name || ' ' || xl.last_name, u.full_name, 'Participante') as name
               from quiz_attempts q join course_enrollments e on e.id = q.enrollment_id join courses c on c.id = e.course_id join course_lessons l on l.id = q.lesson_id
               left join workers w on w.id = e.worker_id left join contractor_workers cw on cw.id = e.contractor_worker_id
               left join external_learners xl on xl.id = e.external_learner_id left join users u on u.id = e.user_id
              where q.deleted_at is null and q.status = 'submitted' and e.deleted_at is null {$f} order by q.submitted_at limit 300", $b);
        $rows = array_values(array_filter($rows, fn ($r) => TrainerAccess::can($r->course_id, 'can_grade')));

        return response()->json(['data' => ['items' => $rows, 'count' => count($rows)]]);
    }

    public function attemptDetail(string $id): JsonResponse
    {
        [$a, $l] = $this->gradable($id);
        $ans = json_decode($a->answers ?? '{}', true) ?: [];
        $detail = $ans['detail'] ?? [];
        $set = json_decode($a->question_set ?? '[]', true) ?: [];
        $ids = array_column($set, 'id');
        $qs = $ids ? collect(DB::select('select id, question_type, statement, options, accepted_answers, points from questions where id in ('.implode(',', array_fill(0, count($ids), '?')).')', $ids))->keyBy('id') : collect();
        $questions = [];
        foreach ($ids as $qid) {
            $q = $qs[$qid] ?? null;
            if (! $q) {
                continue;
            }
            $opts = json_decode($q->options ?? '[]', true) ?: [];
            $g = $detail[$qid]['given'] ?? null;
            $questions[] = ['id' => $qid, 'type' => $q->question_type, 'statement' => $q->statement, 'max' => (float) $q->points,
                'manual' => (bool) ($detail[$qid]['manual'] ?? false), 'auto_points' => (float) ($detail[$qid]['points'] ?? 0), 'ok' => $detail[$qid]['ok'] ?? null,
                'answer' => $q->question_type === 'short_answer' ? (string) ($g ?? '') : implode(', ', array_map(fn ($k) => (string) ($opts[(int) $k]['text'] ?? '?'), (array) ($g ?? []))),
                'correct' => $q->question_type === 'short_answer' ? implode(' | ', json_decode($q->accepted_answers ?? '[]', true) ?: [])
                    : implode(', ', array_map(fn ($o) => $o['text'] ?? '', array_filter($opts, fn ($o) => ! empty($o['correct']))))];
        }
        $cfg = ExamEngine::config($l);

        return response()->json(['data' => ['id' => $a->id, 'attempt_number' => (int) $a->attempt_number, 'submitted_at' => $a->submitted_at, 'status' => $a->status,
            'lesson' => $l->title, 'practical' => ! empty($cfg['manual_grading']), 'score' => (float) $a->score, 'max_score' => (float) $a->max_score,
            'passing' => Grading::passingPct($l->passing_score !== null ? (float) $l->passing_score : null, ($c = DB::selectOne('select passing_score from courses where id = ?', [$l->course_id]))?->passing_score !== null ? (float) $c->passing_score : null, Grading::scaleOf($l->course_id)),
            'questions' => $questions]]);
    }

    public function grade(Request $request, string $id): JsonResponse
    {
        [$a, $l, $e] = $this->gradable($id);
        $d = $request->validate(['question_points' => ['nullable', 'array'], 'question_points.*' => ['numeric', 'min:0'], 'score_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'feedback' => ['nullable', 'string', 'max:2000']]);
        $ans = json_decode($a->answers ?? '{}', true) ?: [];
        $detail = $ans['detail'] ?? [];
        $max = (float) $a->max_score;
        if (isset($d['score_pct'])) {
            $pct = round((float) $d['score_pct'], 2);
            $score = round($max * $pct / 100, 2);
        } else {
            $score = 0.0;
            $given = (array) ($d['question_points'] ?? []);
            foreach ($detail as $qid => $x) {
                if (! empty($x['manual'])) {
                    if (! isset($given[$qid])) {
                        throw new ApiProblem(422, 'validation_failed', 'Faltan puntos', 'Califica todas las preguntas abiertas o indica la nota del intento.', ['errors' => ['question_points' => ['Califica todas las preguntas abiertas.']]]);
                    }
                    $pts = min((float) $x['max'], max(0, (float) $given[$qid]));
                    $detail[$qid]['points'] = $pts;
                    $detail[$qid]['ok'] = $pts >= (float) $x['max'];
                    $score += $pts;
                } else {
                    $score += (float) ($x['points'] ?? 0);
                }
            }
            $pct = $max > 0 ? round($score * 100 / $max, 2) : 0.0;
        }
        $scale = Grading::scaleOf($e->course_id);
        $coursePassing = DB::selectOne('select passing_score from courses where id = ?', [$e->course_id])?->passing_score;
        $passing = Grading::passingPct($l->passing_score !== null ? (float) $l->passing_score : null, $coursePassing !== null ? (float) $coursePassing : null, $scale);
        $passed = $pct >= $passing;
        $label = Grading::label($pct, $scale);
        $res = DB::transaction(function () use ($a, $l, $e, $detail, $ans, $score, $passed, $label, $d, $pct) {
            DB::update("update quiz_attempts set answers = ?::jsonb, score = ?, passed = ?, grade_label = ?, graded_by = ?, graded_at = now(), feedback = ?, status = 'graded', updated_by = ?, updated_at = now() where id = ?",
                [json_encode(['given' => $ans['given'] ?? [], 'detail' => $detail]), $score, $passed ? 'true' : 'false', $label, (string) auth()->id(), $d['feedback'] ?? null, (string) auth()->id(), $a->id]);
            $this->audit('quiz_attempts.grade', 'quiz_attempts', $a->id, ['score_pct' => $pct, 'passed' => $passed, 'label' => $label]);

            return ExamEngine::afterGrade($e, $l, $pct, $passed);
        });

        return response()->json(['data' => ['score_pct' => $pct, 'passing' => $passing, 'passed' => $passed, 'label' => $label, 'enrollment' => $res]]);
    }

    // ─────────────── Escalas de calificación (SCR-AUL-10) ───────────────

    public function scales(): JsonResponse
    {
        $this->staff();
        $rows = DB::select(
            "select g.id, g.company_id, g.code, g.name, g.scale_type, g.min_value, g.max_value, g.passing_value, g.passing_label, g.bands, g.decimals, g.is_default, g.status,
                    (select count(*) from courses c where c.grading_scale_id = g.id and c.deleted_at is null and c.company_id is not null) as courses
               from grading_scales g where g.deleted_at is null order by g.company_id nulls first, g.status, g.code");
        foreach ($rows as $r) {
            $r->bands = json_decode($r->bands ?? '[]', true) ?: [];
            $r->system = $r->company_id === null;
            foreach (['min_value', 'max_value', 'passing_value'] as $k) {
                $r->{$k} = $r->{$k} === null ? null : (float) $r->{$k};
            }
            unset($r->company_id);
        }

        return response()->json(['data' => ['items' => $rows, 'can_edit' => in_array('aul.grading_scales.update', \App\Core\Auth\Permissions::effective(), true) && ! TrainerAccess::isTrainer()]]);
    }

    public function createScale(Request $request): JsonResponse
    {
        $this->scaleEditor();
        $v = $this->scaleRules($request, null);
        $ctx = app(CompanyContext::class);
        $me = (string) auth()->id();
        $row = DB::transaction(function () use ($v, $ctx, $me) {
            if ($v['is_default']) {
                DB::update('update grading_scales set is_default = false where company_id = ? and is_default', [$ctx->companyId]);
            }

            return DB::selectOne(
                "insert into grading_scales (company_id, code, name, scale_type, min_value, max_value, passing_value, passing_label, bands, decimals, is_default, status, created_by, updated_by)
                 values (?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, 'active', ?, ?) returning id",
                [$ctx->companyId, $v['code'], $v['name'], $v['scale_type'], $v['min_value'], $v['max_value'], $v['passing_value'], $v['passing_label'], json_encode($v['bands'], JSON_UNESCAPED_UNICODE),
                    $v['decimals'], $v['is_default'] ? 'true' : 'false', $me, $me]);
        });
        $this->audit('grading_scales.create', 'grading_scales', $row->id, ['code' => $v['code']]);

        return response()->json(['data' => ['id' => $row->id]], 201);
    }

    public function updateScale(Request $request, string $id): JsonResponse
    {
        $this->scaleEditor();
        $s = $this->companyScale($id);
        $v = $this->scaleRules($request, $s);
        $graded = DB::selectOne("select 1 as ok from courses c where c.grading_scale_id = ? and c.deleted_at is null
                                   and exists (select 1 from quiz_attempts q join course_enrollments e on e.id = q.enrollment_id where e.course_id = c.id and q.status = 'graded' and q.deleted_at is null)", [$id]);
        $changesGrading = $v['scale_type'] !== $s->scale_type || (float) $v['min_value'] !== (float) $s->min_value || (float) $v['max_value'] !== (float) $s->max_value
            || (float) $v['passing_value'] !== (float) $s->passing_value || $v['passing_label'] !== $s->passing_label || json_encode($v['bands']) !== json_encode(json_decode($s->bands ?? '[]', true) ?: []);
        if ($graded && $changesGrading) {   // RN-AUL-12/17
            throw new ApiProblem(409, 'conflict', 'Escala en uso', 'Un curso con calificaciones usa esta escala: solo cambia el nombre. Crea una escala nueva para los cursos nuevos.');
        }
        DB::transaction(function () use ($v, $id) {
            if ($v['is_default']) {
                DB::update('update grading_scales set is_default = false where company_id = ? and is_default and id <> ?', [app(CompanyContext::class)->companyId, $id]);
            }
            DB::update('update grading_scales set code = ?, name = ?, scale_type = ?, min_value = ?, max_value = ?, passing_value = ?, passing_label = ?, bands = ?::jsonb, decimals = ?, is_default = ?,
                          updated_by = ?, updated_at = now() where id = ?',
                [$v['code'], $v['name'], $v['scale_type'], $v['min_value'], $v['max_value'], $v['passing_value'], $v['passing_label'], json_encode($v['bands'], JSON_UNESCAPED_UNICODE), $v['decimals'],
                    $v['is_default'] ? 'true' : 'false', (string) auth()->id(), $id]);
        });
        $this->audit('grading_scales.update', 'grading_scales', $id, ['code' => $v['code']]);

        return response()->json(['data' => ['id' => $id]]);
    }

    public function deleteScale(string $id): JsonResponse
    {
        $this->scaleEditor();
        $this->companyScale($id);
        if (DB::selectOne('select 1 as ok from courses where grading_scale_id = ? and deleted_at is null', [$id])) {
            throw new ApiProblem(409, 'conflict', 'Escala en uso', 'Hay cursos que usan esta escala: cámbiales la escala antes de archivarla.');
        }
        DB::update("update grading_scales set status = 'archived', is_default = false, updated_by = ?, updated_at = now() where id = ?", [(string) auth()->id(), $id]);
        $this->audit('grading_scales.archive', 'grading_scales', $id, []);

        return response()->json(['data' => ['id' => $id, 'status' => 'archived']]);
    }

    // ─────────────── Reglas de recomendación: ejecutar ahora (SCR-AUL-15) ───────────────

    public function createRule(Request $request): JsonResponse
    {
        $this->staff();
        $v = $this->ruleRules($request, null);
        $me = (string) auth()->id();
        $row = DB::selectOne(
            "insert into recommendation_rules (company_id, code, name, description, rule_type, parameters, audience, course_ids, channels, max_sends_per_person, send_period_days,
                requires_consent, priority, is_active, created_by, updated_by)
             values (?, ?, ?, ?, ?, ?::jsonb, ?, ?::uuid[], ?::text[], ?, ?, ?, ?, ?, ?, ?) returning id",
            [app(CompanyContext::class)->companyId, $v['code'], $v['name'], $v['description'], $v['rule_type'], json_encode($v['parameters']), $v['audience'], $this->pgArray($v['course_ids']),
                $this->pgArray($v['channels']), $v['max_sends_per_person'], $v['send_period_days'], $v['requires_consent'] ? 'true' : 'false', $v['priority'], $v['is_active'] ? 'true' : 'false', $me, $me]);
        $this->audit('recommendation_rules.create', 'recommendation_rules', $row->id, ['code' => $v['code'], 'rule_type' => $v['rule_type']]);

        return response()->json(['data' => ['id' => $row->id]], 201);
    }

    public function updateRule(Request $request, string $id): JsonResponse
    {
        $this->staff();
        $r = Str::isUuid($id) ? DB::selectOne('select * from recommendation_rules where id = ? and deleted_at is null', [$id]) : null;
        if (! $r) {
            throw ApiProblem::notFound('La regla no existe o es de otra empresa.');
        }
        $v = $this->ruleRules($request, $r);
        DB::update('update recommendation_rules set code = ?, name = ?, description = ?, rule_type = ?, parameters = ?::jsonb, audience = ?, course_ids = ?::uuid[], channels = ?::text[],
                      max_sends_per_person = ?, send_period_days = ?, requires_consent = ?, priority = ?, is_active = ?, updated_by = ?, updated_at = now() where id = ?',
            [$v['code'], $v['name'], $v['description'], $v['rule_type'], json_encode($v['parameters']), $v['audience'], $this->pgArray($v['course_ids']), $this->pgArray($v['channels']),
                $v['max_sends_per_person'], $v['send_period_days'], $v['requires_consent'] ? 'true' : 'false', $v['priority'], $v['is_active'] ? 'true' : 'false', (string) auth()->id(), $id]);
        $this->audit('recommendation_rules.update', 'recommendation_rules', $id, ['code' => $v['code'], 'is_active' => $v['is_active']]);

        return response()->json(['data' => ['id' => $id]]);
    }

    private function ruleRules(Request $request, ?object $current): array
    {
        $d = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-_]+$/'], 'name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:1000'],
            'rule_type' => ['required', 'in:'.implode(',', RecommendationEngine::SUPPORTED).',inspection_finding,incident_related,similar_buyers'],
            'audience' => ['required', 'in:workers,external_learners,buyer_companies'], 'course_ids' => ['nullable', 'array', 'max:20'], 'course_ids.*' => ['uuid'],
            'channels' => ['required', 'array', 'min:1'], 'channels.*' => ['in:in_app,email,whatsapp'], 'max_sends_per_person' => ['nullable', 'integer', 'min:1', 'max:50'],
            'send_period_days' => ['nullable', 'integer', 'min:1', 'max:365'], 'requires_consent' => ['boolean'], 'priority' => ['nullable', 'integer', 'min:1', 'max:999'], 'is_active' => ['boolean'],
            'parameters' => ['nullable', 'array'], 'parameters.lead_days' => ['nullable', 'integer', 'min:1', 'max:365'], 'parameters.requires_approval' => ['nullable', 'boolean'],
            'parameters.position_ids' => ['nullable', 'array'], 'parameters.position_ids.*' => ['uuid'], 'parameters.after_course_ids' => ['nullable', 'array'], 'parameters.after_course_ids.*' => ['uuid'],
        ], ['code.required' => 'Escribe el código.', 'name.required' => 'Escribe el nombre.', 'channels.required' => 'Elige al menos un canal.']);
        $code = strtoupper($d['code']);
        if (DB::selectOne('select 1 as ok from recommendation_rules where code = ? and deleted_at is null'.($current ? ' and id <> ?' : ''), array_filter([$code, $current?->id]))) {
            $this->fail('code', 'Ya hay una regla con ese código.');
        }
        if ($d['rule_type'] === 'similar_buyers' && $d['audience'] === 'workers') {
            $this->fail('audience', 'La regla de perfil de compra no aplica a trabajadores.');
        }
        $courses = array_values(array_unique($d['course_ids'] ?? []));
        if ($courses && count(DB::select('select id from courses where company_id is not null and deleted_at is null and id in ('.implode(',', array_fill(0, count($courses), '?')).')', $courses)) !== count($courses)) {
            $this->fail('course_ids', 'Elige cursos de la empresa.');
        }
        $p = (array) ($d['parameters'] ?? []);
        if (in_array($d['rule_type'], ['complementary', 'learning_path_next'], true) && (empty($p['after_course_ids']) || ! $courses)) {
            $this->fail('course_ids', 'Elige el curso aprobado y los cursos que se recomiendan después.');
        }
        $params = array_filter(['lead_days' => isset($p['lead_days']) ? (int) $p['lead_days'] : ($d['rule_type'] === 'certificate_expiring' ? 30 : null),
            'requires_approval' => (bool) ($p['requires_approval'] ?? false), 'position_ids' => array_values($p['position_ids'] ?? []) ?: null,
            'after_course_ids' => array_values($p['after_course_ids'] ?? []) ?: null], fn ($x) => $x !== null);

        return ['code' => $code, 'name' => trim($d['name']), 'description' => $d['description'] ?? null, 'rule_type' => $d['rule_type'], 'audience' => $d['audience'], 'course_ids' => $courses,
            'channels' => array_values(array_unique($d['channels'])), 'max_sends_per_person' => (int) ($d['max_sends_per_person'] ?? 1), 'send_period_days' => (int) ($d['send_period_days'] ?? 30),
            'requires_consent' => (bool) ($d['requires_consent'] ?? true), 'priority' => (int) ($d['priority'] ?? 100), 'is_active' => (bool) ($d['is_active'] ?? false), 'parameters' => (object) $params];
    }

    private function pgArray(array $v): string
    {
        return '{'.implode(',', array_map(fn ($x) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $x).'"', $v)).'}';
    }

    public function runRule(Request $request, string $id): JsonResponse
    {
        $this->staff();
        $r = Str::isUuid($id) ? DB::selectOne('select * from recommendation_rules where id = ? and deleted_at is null', [$id]) : null;
        if (! $r) {
            throw ApiProblem::notFound('La regla no existe o es de otra empresa.');
        }
        $engine = app(RecommendationEngine::class);
        $dry = $request->boolean('dry_run');
        $res = DB::transaction(fn () => $engine->runRule(DB::connection(), app(CompanyContext::class)->companyId, $r, $dry));

        return response()->json(['data' => $res]);
    }

    // ── apoyo ───────────────────────────────────────────────────────────────

    private function scaleRules(Request $request, ?object $current): array
    {
        $d = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-_]+$/'], 'name' => ['required', 'string', 'max:120'], 'scale_type' => ['required', 'in:numeric,letter'],
            'min_value' => ['required_if:scale_type,numeric', 'nullable', 'numeric', 'min:0', 'max:1000'], 'max_value' => ['required_if:scale_type,numeric', 'nullable', 'numeric', 'min:1', 'max:1000'],
            'passing_value' => ['required_if:scale_type,numeric', 'nullable', 'numeric'], 'decimals' => ['nullable', 'integer', 'between:0,2'],
            'bands' => ['required_if:scale_type,letter', 'nullable', 'array', 'max:10'], 'bands.*.label' => ['required', 'string', 'max:5'], 'bands.*.from' => ['required', 'numeric', 'min:0', 'max:100'],
            'bands.*.description' => ['nullable', 'string', 'max:80'], 'passing_label' => ['required_if:scale_type,letter', 'nullable', 'string', 'max:5'], 'is_default' => ['boolean'],
        ], [
            'code.required' => 'Escribe el código.', 'code.regex' => 'El código usa solo letras, números y guiones.', 'name.required' => 'Escribe el nombre.',
            'min_value.required_if' => 'Indica el valor mínimo.', 'max_value.required_if' => 'Indica el valor máximo.', 'passing_value.required_if' => 'Indica la nota aprobatoria.',
            'bands.required_if' => 'Agrega los rangos de letras.', 'passing_label.required_if' => 'Elige la letra mínima para aprobar.',
        ]);
        $code = strtoupper($d['code']);
        $dup = DB::selectOne('select id from grading_scales where company_id = ? and code = ? and deleted_at is null'.($current ? ' and id <> ?' : ''),
            array_filter([app(CompanyContext::class)->companyId, $code, $current?->id]));
        if ($dup) {
            $this->fail('code', 'Ya hay una escala con ese código.');
        }
        $v = ['code' => $code, 'name' => trim($d['name']), 'scale_type' => $d['scale_type'], 'decimals' => (int) ($d['decimals'] ?? 0), 'is_default' => (bool) ($d['is_default'] ?? false),
            'min_value' => null, 'max_value' => null, 'passing_value' => null, 'passing_label' => null, 'bands' => []];
        if ($d['scale_type'] === 'numeric') {
            if ((float) $d['max_value'] <= (float) $d['min_value']) {
                $this->fail('max_value', 'El valor máximo debe ser mayor que el mínimo.');
            }
            if ((float) $d['passing_value'] < (float) $d['min_value'] || (float) $d['passing_value'] > (float) $d['max_value']) {
                $this->fail('passing_value', 'La nota aprobatoria debe estar entre el mínimo y el máximo.');
            }
            $v['min_value'] = (float) $d['min_value'];
            $v['max_value'] = (float) $d['max_value'];
            $v['passing_value'] = (float) $d['passing_value'];
        } else {
            $bands = array_map(fn ($b) => ['label' => strtoupper(trim($b['label'])), 'from' => (float) $b['from'], 'description' => trim((string) ($b['description'] ?? '')) ?: null], $d['bands']);
            usort($bands, fn ($a, $b) => $b['from'] <=> $a['from']);
            $labels = array_column($bands, 'label');
            if (count($labels) !== count(array_unique($labels))) {
                $this->fail('bands', 'Cada letra va una sola vez.');
            }
            if (count(array_unique(array_column($bands, 'from'))) !== count($bands)) {
                $this->fail('bands', 'Dos letras no pueden empezar en el mismo porcentaje.');
            }
            if (end($bands)['from'] > 0) {
                $this->fail('bands', 'La letra más baja debe empezar en 0 %.');
            }
            foreach ($bands as $i => &$b) {   // «hasta»: el inicio de la siguiente letra menos 0,01
                $b['to'] = $i === 0 ? 100 : round($bands[$i - 1]['from'] - 0.01, 2);
            }
            unset($b);
            $pl = strtoupper(trim($d['passing_label']));
            if (! in_array($pl, $labels, true)) {
                $this->fail('passing_label', 'La letra para aprobar debe ser una de los rangos.');
            }
            $v['bands'] = $bands;
            $v['passing_label'] = $pl;
        }

        return $v;
    }

    private function companyScale(string $id): object
    {
        $s = Str::isUuid($id) ? DB::selectOne('select * from grading_scales where id = ? and deleted_at is null', [$id]) : null;
        if (! $s) {
            throw ApiProblem::notFound('La escala no existe.');
        }
        if ($s->company_id === null) {
            throw ApiProblem::forbidden('Las escalas del sistema no se editan: crea una propia a partir de ella.');
        }

        return $s;
    }

    private function scaleEditor(): void
    {
        $this->staff();
        if (TrainerAccess::isTrainer()) {
            throw ApiProblem::forbidden('Las escalas las define el administrador del aula.');
        }
    }

    /** @return array{0: object, 1: object, 2: object} intento, evaluación y matrícula */
    private function gradable(string $id): array
    {
        $this->staff();
        $a = Str::isUuid($id) ? DB::selectOne('select * from quiz_attempts where id = ? and deleted_at is null', [$id]) : null;
        if (! $a) {
            throw ApiProblem::notFound('El intento no existe o es de otra empresa.');
        }
        $e = $this->enrollment($a->enrollment_id);
        TrainerAccess::require($e->course_id, 'can_grade', 'No tienes permiso para calificar en este curso.');
        if ($a->status !== 'submitted') {
            throw new ApiProblem(409, 'conflict', 'Ya calificado', 'Este intento no está pendiente de calificación.');
        }

        return [$a, DB::selectOne('select * from course_lessons where id = ?', [$a->lesson_id]), $e];
    }

    private function enrollment(string $id): object
    {
        $e = Str::isUuid($id) ? DB::selectOne('select * from course_enrollments where id = ? and deleted_at is null', [$id]) : null;
        if (! $e) {
            throw ApiProblem::notFound('La matrícula no existe o es de otra empresa.');
        }
        $this->course($e->course_id);   // el capacitador solo ve sus cursos (RN-AUL-03)

        return $e;
    }

    private function course(string $id): object
    {
        $this->staff();
        [$f, $b] = $this->trainerFilter('c.id');
        $c = Str::isUuid($id) ? DB::selectOne("select c.* from courses c where c.id = ? and c.deleted_at is null and c.company_id is not null {$f}", [$id, ...$b]) : null;

        return $c ?? throw ApiProblem::notFound('El curso no existe, es de otra empresa o no lo tienes asignado.');
    }

    private function trainerFilter(string $col): array
    {
        if (! TrainerAccess::isTrainer()) {
            return ['', []];
        }

        return ["and exists (select 1 from trainer_permissions tp where tp.course_id = {$col} and tp.trainer_user_id = ? and tp.deleted_at is null)", [(string) auth()->id()]];
    }

    private function staff(): void
    {
        if (in_array(app(CompanyContext::class)->roleCode, ['worker', 'external_learner'], true)) {
            throw ApiProblem::forbidden('Tus cursos están en «Mis cursos».');
        }
    }

    private function audit(string $action, string $entity, string $id, array $changes): void
    {
        $ctx = app(CompanyContext::class);
        DB::insert(
            "insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes, ip, user_agent)
             values (?, ?, ?, 'user', ?, 'AUL', ?, ?, ?::jsonb, ?::inet, ?)",
            [$ctx->companyId, (string) auth()->id(), $ctx->roleCode, $action, $entity, $id, json_encode($changes, JSON_UNESCAPED_UNICODE), request()->ip(), mb_substr((string) request()->userAgent(), 0, 300)]
        );
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}
