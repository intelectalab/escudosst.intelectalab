<?php

namespace App\Modules\AUL;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Permisos del capacitador (RN-AUL-02): permiso del curso → permiso del capacitador → valores por defecto de la
 * empresa → por defecto del producto (sube y edita contenidos, gestiona preguntas y califica; no matricula).
 * Para los demás roles no aplica: manda su permiso del rol.
 */
final class TrainerAccess
{
    private const DEFAULTS = ['can_upload_materials' => true, 'can_edit_content' => true, 'can_edit_assessments' => false, 'can_manage_question_banks' => true,
        'can_grade' => true, 'can_enroll' => false, 'can_view_reports' => true];

    public static function isTrainer(): bool
    {
        return app(CompanyContext::class)->roleCode === 'trainer';
    }

    public static function can(?string $courseId, string $flag): bool
    {
        if (! self::isTrainer()) {
            return true;
        }
        $me = (string) auth()->id();
        $rows = DB::select(
            'select trainer_user_id, course_id, '.implode(', ', array_keys(self::DEFAULTS)).' from trainer_permissions
              where deleted_at is null and (valid_until is null or valid_until >= current_date) and (valid_from is null or valid_from <= current_date)
                and ((trainer_user_id = ? and (course_id = ? or course_id is null)) or (trainer_user_id is null and course_id is null))',
            [$me, $courseId]);
        foreach ([fn ($r) => $r->trainer_user_id === $me && $courseId && $r->course_id === $courseId, fn ($r) => $r->trainer_user_id === $me && $r->course_id === null,
            fn ($r) => $r->trainer_user_id === null] as $match) {
            foreach ($rows as $r) {
                if ($match($r)) {
                    return (bool) $r->{$flag};
                }
            }
        }

        return self::DEFAULTS[$flag] ?? false;
    }

    public static function require(?string $courseId, string $flag, string $message): void
    {
        if (! self::can($courseId, $flag)) {
            throw ApiProblem::forbidden($message);
        }
    }
}
