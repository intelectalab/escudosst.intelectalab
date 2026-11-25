<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/04 «una matrícula vigente por persona y curso»: la unicidad de course_enrollments cuenta solo las matrículas vigentes
 * (matriculado o en curso). Antes contaba también las terminadas (aprobadas o desaprobadas), y la recapacitación del mismo
 * curso cuando el certificado vence (M-AUL RN-AUL-24/25, excepción de recapacitación; E2E-19) fallaba al matricular de nuevo
 * a quien ya lo había aprobado: la compra pagada respondía con error 500.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const COLS = [1 => 'worker_id', 2 => 'contractor_worker_id', 3 => 'external_learner_id'];

    public function up(): void
    {
        foreach (self::COLS as $n => $col) {
            DB::statement("drop index if exists uq_course_enrollments_{$n}");
            DB::statement("create unique index uq_course_enrollments_{$n} on course_enrollments (company_id, course_id, {$col})
                           where ({$col} is not null and status in ('enrolled', 'in_progress')) and deleted_at is null");
        }
    }

    public function down(): void
    {
        foreach (self::COLS as $n => $col) {
            DB::statement("drop index if exists uq_course_enrollments_{$n}");
            DB::statement("create unique index uq_course_enrollments_{$n} on course_enrollments (company_id, course_id, {$col})
                           where ({$col} is not null and status not in ('cancelled', 'expired')) and deleted_at is null");
        }
    }
};
