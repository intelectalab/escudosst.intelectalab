<?php

namespace App\Modules\AUL;

use App\Core\Http\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * Evaluaciones del aula (M-AUL RN-AUL-13/15, SCR-AUL-23):
 *  - Sorteo por intento: `exam_config.num_questions` preguntas activas de los bancos de la evaluación
 *    (`question_bank_id` y `exam_config.bank_ids`), al azar si `shuffle_questions`, respetando la mezcla por
 *    dificultad `exam_config.difficulty_mix` ({easy, medium, hard}) si está configurada; con «mezclar opciones» se
 *    guarda el orden de las alternativas de cada pregunta. El sorteo queda en `quiz_attempts.question_set` y la nota
 *    se calcula solo con esas preguntas.
 *  - Límite de intentos (`max_attempts` de la evaluación o del curso) y tiempo límite: al vencer, el intento se envía
 *    con las respuestas guardadas.
 *  - Calificación: opción única, múltiple y verdadero/falso automáticas; respuesta corta automática si tiene
 *    respuestas aceptadas; sin respuestas aceptadas (pregunta abierta) o con `exam_config.manual_grading`
 *    (evaluación práctica) el intento queda «enviado» y pasa a «Por calificar».
 */
final class ExamEngine
{
    /** Segundos de gracia al enviar después del tiempo límite (latencia de red). */
    private const GRACE = 30;

    public static function banks(object $lesson): array
    {
        $cfg = self::config($lesson);
        $ids = array_values(array_unique(array_filter(array_merge([$lesson->question_bank_id], (array) ($cfg['bank_ids'] ?? [])), fn ($v) => is_string($v) && $v !== '')));

        return $ids;
    }

    public static function config(object $lesson): array
    {
        return is_string($lesson->exam_config ?? null) ? (json_decode($lesson->exam_config, true) ?: []) : (array) ($lesson->exam_config ?? []);
    }

    public static function maxAttempts(object $lesson): ?int
    {
        $max = $lesson->max_attempts ?? DB::selectOne('select max_attempts from courses where id = ?', [$lesson->course_id])?->max_attempts;

        return $max ? (int) $max : null;
    }

    /** Sorteo de preguntas: [{id, order: [índices originales de las alternativas]}]. */
    public static function draw(object $lesson, ?object $course = null): array
    {
        $cfg = self::config($lesson);
        $banks = self::banks($lesson);
        if (! $banks) {
            return [];
        }
        $pool = DB::select("select id, question_type, options, difficulty, shuffle_options from questions
                             where question_bank_id in (".implode(',', array_fill(0, count($banks), '?')).") and deleted_at is null and status = 'active'
                             order by code nulls last, created_at", $banks);
        $n = min(count($pool), max(1, (int) ($cfg['num_questions'] ?? count($pool))));
        $random = (bool) ($cfg['shuffle_questions'] ?? true);
        $mix = array_filter((array) ($cfg['difficulty_mix'] ?? []), fn ($v) => (int) $v > 0);
        if ($random) {
            shuffle($pool);
        }
        $picked = [];
        if ($mix) {
            foreach (['easy', 'medium', 'hard'] as $d) {
                $want = (int) ($mix[$d] ?? 0);
                foreach ($pool as $i => $q) {
                    if ($want <= 0 || count($picked) >= $n) {
                        break;
                    }
                    if ($q->difficulty === $d) {
                        $picked[] = $q;
                        unset($pool[$i]);
                        $want--;
                    }
                }
            }
        }
        foreach ($pool as $q) {   // completa hasta el número pedido
            if (count($picked) >= $n) {
                break;
            }
            $picked[] = $q;
        }
        if ($random) {
            shuffle($picked);
        }
        $course ??= DB::selectOne('select randomize_options from courses where id = ?', [$lesson->course_id]);
        $shuffleOpts = (bool) ($cfg['shuffle_options'] ?? ($course->randomize_options ?? false));

        return array_map(function ($q) use ($shuffleOpts) {
            $count = count(json_decode($q->options ?? '[]', true) ?: []);
            $order = range(0, max(0, $count - 1));
            if ($count === 0) {
                $order = [];
            } elseif ($shuffleOpts && $q->shuffle_options && $q->question_type !== 'true_false') {
                shuffle($order);
            }

            return ['id' => $q->id, 'order' => $order];
        }, $picked);
    }

    /** Preguntas del intento para quien rinde (sin la respuesta correcta), en el orden sorteado. */
    public static function publicQuestions(array $set): array
    {
        if (! $set) {
            return [];
        }
        $ids = array_column($set, 'id');
        $rows = collect(DB::select('select id, question_type, statement, options, points from questions where id in ('.implode(',', array_fill(0, count($ids), '?')).')', $ids))->keyBy('id');

        return array_values(array_filter(array_map(function ($s) use ($rows) {
            $q = $rows[$s['id']] ?? null;
            if (! $q) {
                return null;
            }
            $opts = json_decode($q->options ?? '[]', true) ?: [];
            $order = $s['order'] ?: array_keys($opts);

            return ['id' => $q->id, 'type' => $q->question_type, 'statement' => $q->statement, 'points' => (float) $q->points,
                'options' => array_values(array_filter(array_map(fn ($i) => isset($opts[$i]) ? ['key' => (string) $i, 'text' => is_array($opts[$i]) ? ($opts[$i]['text'] ?? '') : (string) $opts[$i]] : null, $order)))];
        }, $set)));
    }

    /** Intentos válidos (no reiniciados) de una matrícula en una evaluación. */
    public static function attemptsUsed(string $enrollmentId, string $lessonId): int
    {
        return (int) DB::selectOne('select count(*) as n from quiz_attempts where enrollment_id = ? and lesson_id = ? and deleted_at is null', [$enrollmentId, $lessonId])->n;
    }

    public static function openAttempt(string $enrollmentId, string $lessonId): ?object
    {
        return DB::selectOne("select * from quiz_attempts where enrollment_id = ? and lesson_id = ? and deleted_at is null and status = 'in_progress' order by attempt_number desc limit 1", [$enrollmentId, $lessonId]);
    }

    /** Inicia un intento (o devuelve el que está abierto). Respeta el límite de intentos (RN-AUL-15). */
    public static function start(object $e, object $lesson): object
    {
        $open = self::openAttempt($e->id, $lesson->id);
        if ($open && self::expired($open)) {
            self::submit($e, $lesson, $open, null);   // venció: se envía con lo guardado
            $open = null;
        }
        if ($open) {
            return $open;
        }
        $used = self::attemptsUsed($e->id, $lesson->id);
        $max = self::maxAttempts($lesson);
        if ($max && $used >= $max) {
            throw new ApiProblem(409, 'conflict', 'Sin intentos', "Ya usaste los {$max} intentos de esta evaluación. Comunícate con tu capacitador.");
        }
        $progress = json_decode($e->lesson_progress ?? '{}', true) ?: [];
        if (! empty($progress[$lesson->id]['done'])) {
            throw new ApiProblem(409, 'conflict', 'Ya aprobada', 'Ya aprobaste esta evaluación.');
        }
        $set = self::draw($lesson);
        if (! $set) {
            throw new ApiProblem(409, 'conflict', 'Evaluación sin preguntas', 'La evaluación todavía no tiene preguntas activas.');
        }
        $limit = $lesson->time_limit_minutes ? (int) $lesson->time_limit_minutes : null;
        $me = (string) auth()->id();

        return DB::selectOne(
            "insert into quiz_attempts (company_id, enrollment_id, lesson_id, source, attempt_number, question_set, started_at, expires_at, answers, status, created_by, updated_by)
             values (?, ?, ?, 'internal', ?, ?::jsonb, now(), case when ?::int is null then null else now() + make_interval(mins => ?::int) end, '{}'::jsonb, 'in_progress', ?, ?)
             returning *",
            [$e->company_id, $e->id, $lesson->id, $used + 1, json_encode($set), $limit, $limit, $me, $me]
        );
    }

    public static function expired(object $attempt): bool
    {
        return $attempt->expires_at !== null && strtotime($attempt->expires_at) + self::GRACE < time();
    }

    /** Guarda respuestas parciales (autoguardado). Un intento enviado o vencido ya no cambia. */
    public static function save(object $attempt, array $answers): void
    {
        if ($attempt->status !== 'in_progress' || self::expired($attempt)) {
            throw new ApiProblem(409, 'conflict', 'Intento cerrado', 'El intento ya se envió o se agotó su tiempo.');
        }
        DB::update('update quiz_attempts set answers = ?::jsonb, updated_at = now() where id = ?', [json_encode(self::clean($attempt, $answers)), $attempt->id]);
    }

    /**
     * Envía y califica el intento. Con $answers = null (o si venció el tiempo) usa las respuestas guardadas.
     *
     * @return array{score_pct: ?float, passing: float, passed: ?bool, pending: bool, attempt: int, label: ?string, enrollment: ?array, detail: array}
     */
    public static function submit(object $e, object $lesson, object $attempt, ?array $answers): array
    {
        if ($attempt->status !== 'in_progress') {
            throw new ApiProblem(409, 'conflict', 'Intento enviado', 'Este intento ya se envió: no se modifica (RN-AUL-15).');
        }
        $saved = json_decode($attempt->answers ?? '{}', true) ?: [];
        $given = ($answers === null || self::expired($attempt)) ? $saved : self::clean($attempt, $answers);
        $cfg = self::config($lesson);
        $set = json_decode($attempt->question_set ?? '[]', true) ?: [];
        [$score, $max, $detail, $pendingManual] = self::grade($set, $given);
        $manual = ! empty($cfg['manual_grading']) || $pendingManual;
        if ($max <= 0) {
            throw new ApiProblem(409, 'conflict', 'Examen sin preguntas', 'El intento no tiene preguntas.');
        }
        $course = DB::selectOne('select passing_score from courses where id = ?', [$e->course_id]);
        $scale = Grading::scaleOf($e->course_id);
        $passing = Grading::passingPct($lesson->passing_score !== null ? (float) $lesson->passing_score : null, $course?->passing_score !== null ? (float) $course->passing_score : null, $scale);
        $pct = round($score * 100 / $max, 2);
        $spent = max(0, time() - strtotime($attempt->started_at));
        if ($attempt->expires_at) {
            $spent = min($spent, max(0, strtotime($attempt->expires_at) - strtotime($attempt->started_at)));
        }
        if ($manual) {
            DB::update("update quiz_attempts set answers = ?::jsonb, submitted_at = now(), time_spent_seconds = ?, score = ?, max_score = ?, status = 'submitted', updated_at = now() where id = ?",
                [json_encode(['given' => $given, 'detail' => $detail]), $spent, round($score, 2), round($max, 2), $attempt->id]);
            Grading::notifyPending($e, $lesson, $attempt, ! empty($cfg['manual_grading']));   // aviso al capacitador (M-AUL §10)

            return ['score_pct' => null, 'passing' => $passing, 'passed' => null, 'pending' => true, 'attempt' => (int) $attempt->attempt_number, 'label' => null, 'enrollment' => null, 'detail' => []];
        }
        $passed = $pct >= $passing;
        $label = Grading::label($pct, $scale);
        DB::update("update quiz_attempts set answers = ?::jsonb, submitted_at = now(), time_spent_seconds = ?, score = ?, max_score = ?, passed = ?, grade_label = ?, graded_at = now(),
                      status = 'graded', updated_at = now() where id = ?",
            [json_encode(['given' => $given, 'detail' => $detail]), $spent, round($score, 2), round($max, 2), $passed ? 'true' : 'false', $label, $attempt->id]);

        return ['score_pct' => $pct, 'passing' => $passing, 'passed' => $passed, 'pending' => false, 'attempt' => (int) $attempt->attempt_number, 'label' => $label,
            'enrollment' => self::afterGrade($e, $lesson, $pct, $passed), 'detail' => self::feedback($cfg, $set, $detail)];
    }

    /** Tras calificar: aprueba la lección o, sin intentos restantes, deja la matrícula desaprobada. */
    public static function afterGrade(object $e, object $lesson, float $pct, bool $passed): ?array
    {
        if ($passed) {
            return Progress::mark($e, $lesson->id, ['done' => true, 'passed' => true, 'score' => $pct, 'at' => now()->toIso8601String()]);
        }
        $max = self::maxAttempts($lesson);
        $state = Progress::mark($e, $lesson->id, ['last_score' => $pct, 'at' => now()->toIso8601String()]);
        if ($max && self::attemptsUsed($e->id, $lesson->id) >= $max && $state['status'] !== 'completed') {
            DB::update("update course_enrollments set status = 'failed' where id = ? and status in ('enrolled', 'in_progress')", [$e->id]);
            $state['status'] = 'failed';
        }

        return $state;
    }

    /** @return array{0: float, 1: float, 2: array, 3: bool} puntaje, máximo, detalle por pregunta, ¿hay preguntas abiertas? */
    public static function grade(array $set, array $given): array
    {
        $ids = array_column($set, 'id');
        if (! $ids) {
            return [0.0, 0.0, [], false];
        }
        $rows = DB::select('select id, question_type, options, accepted_answers, points from questions where id in ('.implode(',', array_fill(0, count($ids), '?')).')', $ids);
        $score = $max = 0.0;
        $detail = [];
        $open = false;
        foreach ($rows as $q) {
            $max += (float) $q->points;
            $g = $given[$q->id] ?? null;
            $ok = false;
            $manual = false;
            if ($q->question_type === 'short_answer') {
                $acc = array_map(fn ($a) => mb_strtolower(trim((string) $a)), json_decode($q->accepted_answers ?? '[]', true) ?: []);
                if ($acc === []) {
                    $manual = $open = true;   // pregunta abierta: la califica el capacitador
                } else {
                    $ok = $g !== null && in_array(mb_strtolower(trim((string) $g)), $acc, true);
                }
            } else {
                $opts = json_decode($q->options ?? '[]', true) ?: [];
                $correct = array_map('strval', array_keys(array_filter($opts, fn ($o) => is_array($o) && ! empty($o['correct']))));
                $chosen = array_map('strval', (array) $g);
                sort($correct);
                sort($chosen);
                $ok = $correct !== [] && $correct === $chosen;
            }
            if ($ok) {
                $score += (float) $q->points;
            }
            $detail[$q->id] = ['given' => $g, 'ok' => $manual ? null : $ok, 'points' => $ok ? (float) $q->points : 0.0, 'max' => (float) $q->points, 'manual' => $manual];
        }

        return [$score, $max, $detail, $open];
    }

    /** Solo respuestas de preguntas del intento. */
    private static function clean(object $attempt, array $answers): array
    {
        $ids = array_column(json_decode($attempt->question_set ?? '[]', true) ?: [], 'id');

        return array_intersect_key($answers, array_flip($ids));
    }

    /** Retroalimentación según la configuración (`show_feedback`: on_finish, per_question o never). */
    private static function feedback(array $cfg, array $set, array $detail): array
    {
        if (($cfg['show_feedback'] ?? 'on_finish') === 'never') {
            return [];
        }
        $ids = array_column($set, 'id');
        $fb = collect(DB::select('select id, feedback_correct, feedback_incorrect from questions where id in ('.implode(',', array_fill(0, count($ids), '?')).')', $ids))->keyBy('id');

        return array_values(array_map(fn ($id) => ['id' => $id, 'ok' => $detail[$id]['ok'] ?? null,
            'feedback' => ($detail[$id]['ok'] ?? false) ? ($fb[$id]->feedback_correct ?? null) : ($fb[$id]->feedback_incorrect ?? null)], $ids));
    }
}
