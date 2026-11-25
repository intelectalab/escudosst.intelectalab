<?php

namespace App\Modules\REC\Rules;

use Illuminate\Support\Facades\DB;

/**
 * Aula virtual (M-AUL RN-AUL-12/17): un curso publicado con matriculados no cambia escala, nota mínima ni intentos, y sus evaluaciones
 * no cambian banco, configuración ni nota mínima: se crea una nueva versión (las matrículas en curso terminan en la suya).
 */
class AulRules extends RecordRules
{
    private const COURSE = ['grading_scale_id', 'passing_score', 'max_attempts'];

    private const EXAM = ['question_bank_id', 'exam_config', 'passing_score', 'max_attempts'];

    public function prepare(string $op, array $res, array &$values, ?object $current, array &$extra): void
    {
        if (! $current) {
            return;
        }
        if ($res['table'] === 'courses' && $this->locked($current->id, $current->status) && $this->changes($values, $current, self::COURSE)) {
            $this->invalidTransition('El curso está publicado y tiene matriculados: su escala, nota mínima e intentos no cambian. Crea una nueva versión del curso.');
        }
        if ($res['table'] === 'course_lessons' && $current->lesson_type === 'exam') {
            $st = DB::selectOne('select status from courses where id = ?', [$current->course_id])?->status;
            if ($this->locked($current->course_id, $st) && $this->changes($values, $current, self::EXAM)) {
                $this->invalidTransition('El curso está publicado y tiene matriculados: esta evaluación no cambia. Crea una nueva versión del curso.');
            }
        }
    }

    private function locked(string $courseId, ?string $status): bool
    {
        return $status === 'published' && (bool) DB::selectOne("select 1 as ok from course_enrollments where course_id = ? and deleted_at is null and status <> 'cancelled' limit 1", [$courseId]);
    }

    private function changes(array $values, object $current, array $fields): bool
    {
        foreach ($fields as $f) {
            if (! array_key_exists($f, $values)) {
                continue;
            }
            $new = $values[$f];
            $old = $current->{$f};
            $same = match (true) {
                is_numeric($new) && is_numeric($old) => abs((float) $new - (float) $old) < 0.0001,
                is_array($new) || is_array(json_decode((string) $old, true)) => (is_string($new) ? json_decode($new, true) : $new) == json_decode((string) $old, true),
                default => (string) $new === (string) $old,
            };
            if (! $same) {
                return true;
            }
        }

        return false;
    }
}
