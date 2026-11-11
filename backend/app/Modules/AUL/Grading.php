<?php

namespace App\Modules\AUL;

use Illuminate\Support\Facades\DB;

/**
 * Escala de calificación del curso (M-AUL RN-AUL-11/12, SCR-AUL-10): la nota se calcula siempre en porcentaje y se
 * convierte a la escala del curso (numérica 0-20, 0-10, 0-100… o por letras con rangos `bands`).
 */
final class Grading
{
    /** Escala del curso (o null: porcentaje). */
    public static function scaleOf(?string $courseId): ?object
    {
        if (! $courseId) {
            return null;
        }

        return DB::selectOne('select g.* from courses c join grading_scales g on g.id = c.grading_scale_id where c.id = ? and g.deleted_at is null', [$courseId]);
    }

    /** Bandas de una escala por letras, de mayor a menor. */
    public static function bands(object $scale): array
    {
        $b = is_string($scale->bands ?? null) ? (json_decode($scale->bands, true) ?: []) : (array) ($scale->bands ?? []);
        usort($b, fn ($x, $y) => (float) ($y['from'] ?? 0) <=> (float) ($x['from'] ?? 0));

        return $b;
    }

    /** Porcentaje → etiqueta en la escala («14», «14,5», «A»). Sin escala: «85 %». */
    public static function label(float $pct, ?object $scale): string
    {
        if (! $scale) {
            return rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.').' %';
        }
        if ($scale->scale_type === 'letter') {
            foreach (self::bands($scale) as $b) {
                if ($pct >= (float) ($b['from'] ?? 0)) {
                    return (string) ($b['label'] ?? '');
                }
            }

            return (string) (end($b)['label'] ?? '');
        }
        $min = (float) $scale->min_value;
        $max = (float) $scale->max_value;
        $v = round($min + ($max - $min) * $pct / 100, (int) $scale->decimals);

        return rtrim(rtrim(number_format($v, (int) $scale->decimals, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * Porcentaje mínimo para aprobar: el de la evaluación; si no, en escala por letras el inicio del rango de
     * `passing_label`; si no, el del curso; por defecto 70.
     */
    public static function passingPct(?float $lessonPassing, ?float $coursePassing, ?object $scale): float
    {
        if ($lessonPassing !== null) {
            return $lessonPassing;
        }
        if ($scale && $scale->scale_type === 'letter' && $scale->passing_label) {
            foreach (self::bands($scale) as $b) {
                if (($b['label'] ?? null) === $scale->passing_label) {
                    return (float) $b['from'];
                }
            }
        }

        return $coursePassing ?? 70.0;
    }

    /**
     * Aviso «Evaluación práctica por calificar» (M-AUL §10): al enviar el participante un intento que queda por calificar
     * (evaluación práctica o con preguntas abiertas), avisa en la app a los capacitadores del curso que pueden calificar
     * (trainer_permissions del curso, vigentes, con membresía activa). Sin capacitador asignado, avisa a quien publicó o
     * creó el curso. Uno por intento y destinatario.
     *
     * @return int avisos creados
     */
    public static function notifyPending(object $enrollment, object $lesson, object $attempt, bool $practical): int
    {
        $course = DB::selectOne('select id, company_id, code, title, published_by, created_by from courses where id = ?', [$enrollment->course_id]);
        if (! $course) {
            return 0;
        }
        $to = array_column(DB::select(
            "select distinct tp.trainer_user_id as user_id from trainer_permissions tp
               join memberships m on m.user_id = tp.trainer_user_id and m.company_id = tp.company_id and m.status = 'active' and m.deleted_at is null
              where tp.course_id = ? and tp.deleted_at is null and tp.can_grade
                and (tp.valid_from is null or tp.valid_from <= current_date) and (tp.valid_until is null or tp.valid_until >= current_date)",
            [$course->id]), 'user_id');
        if ($to === []) {
            $owner = $course->published_by ?? $course->created_by;
            if ($owner && DB::selectOne("select 1 as ok from memberships where user_id = ? and company_id = ? and status = 'active' and deleted_at is null", [$owner, $course->company_id])) {
                $to = [$owner];
            }
        }
        $who = DB::selectOne(
            "select coalesce(w.full_name, cw.first_name || ' ' || cw.paternal_last_name, xl.first_name || ' ' || xl.last_name, u.full_name) as name
               from course_enrollments e left join workers w on w.id = e.worker_id left join contractor_workers cw on cw.id = e.contractor_worker_id
               left join external_learners xl on xl.id = e.external_learner_id left join users u on u.id = e.user_id where e.id = ?", [$enrollment->id])?->name ?: 'Un participante';
        $title = $practical ? 'Evaluación práctica por calificar' : 'Evaluación con preguntas abiertas por calificar';
        $body = "{$who} envió «{$lesson->title}» (intento {$attempt->attempt_number}) del curso {$course->code} · {$course->title}.";
        $n = 0;
        foreach ($to as $uid) {
            $key = "aul.grading.{$attempt->id}.{$uid}";
            if (DB::selectOne('select 1 as ok from notifications where dedupe_key = ? and deleted_at is null', [$key])) {
                continue;
            }
            DB::insert(
                "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, dedupe_key)
                 values (?, 'aul.attempt_pending_grading', 'quiz_attempts', ?, ?, ?, ?, '/m/aul/calificar', 'normal', ?)",
                [$course->company_id, $attempt->id, $uid, $title, mb_substr($body, 0, 500), $key]);
            $n++;
        }

        return $n;
    }
}
