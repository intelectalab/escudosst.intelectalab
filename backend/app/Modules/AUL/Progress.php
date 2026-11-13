<?php

namespace App\Modules\AUL;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Avance de una matrícula (M-AUL RN-AUL-10/11/18): guarda el estado de cada lección, recalcula el avance, la nota
 * final ponderada (peso de cada evaluación) convertida a la escala del curso y, al completar, emite el certificado.
 * La matrícula se completa con todas las lecciones obligatorias hechas y, si el curso pide permanencia mínima,
 * con el tiempo activo suficiente.
 */
final class Progress
{
    public static function mark(object $e, ?string $lessonId, array $state = []): array
    {
        $e = DB::selectOne('select * from course_enrollments where id = ?', [$e->id]) ?? $e;   // lo último guardado
        $progress = json_decode($e->lesson_progress ?? '{}', true) ?: [];
        if ($lessonId !== null) {
            $progress[$lessonId] = array_merge($progress[$lessonId] ?? [], $state);
        }
        $required = DB::select('select id from course_lessons where course_id = ? and deleted_at is null and is_required', [$e->course_id]);
        $done = count(array_filter($required, fn ($r) => ! empty($progress[$r->id]['done'])));
        $pct = $required ? round($done * 100 / count($required), 2) : 100;
        $exams = DB::select("select id, coalesce((exam_config->>'weight')::numeric, 1) as weight from course_lessons where course_id = ? and deleted_at is null and lesson_type = 'exam'", [$e->course_id]);
        $sum = $w = 0.0;
        foreach ($exams as $x) {
            if (isset($progress[$x->id]['score'])) {
                $sum += (float) $progress[$x->id]['score'] * (float) $x->weight;
                $w += (float) $x->weight;
            }
        }
        $final = $w > 0 ? round($sum / $w, 2) : null;
        $c = DB::selectOne('select hours, min_active_time_pct, passing_score from courses where id = ?', [$e->course_id]);
        $needSeconds = ($c && $c->min_active_time_pct !== null && $c->hours !== null) ? (int) ceil((float) $c->hours * 3600 * (float) $c->min_active_time_pct / 100) : 0;
        $timeOk = (int) $e->active_seconds_total >= $needSeconds;
        $scale = Grading::scaleOf($e->course_id);
        // RN-AUL-10: con evaluaciones, la nota final debe alcanzar la mínima del curso.
        $needScore = $exams ? Grading::passingPct(null, $c?->passing_score !== null ? (float) $c->passing_score : null, $scale) : null;
        $scoreOk = $needScore === null || ($final !== null && $final >= $needScore);
        $complete = $required && $done === count($required) && $timeOk && $scoreOk;
        $label = $final !== null ? Grading::label($final, $scale) : null;
        $status = $complete ? 'completed' : ($e->status === 'failed' ? 'failed' : 'in_progress');
        DB::update("update course_enrollments set lesson_progress = ?::jsonb, progress_pct = ?, last_activity_at = now(), started_at = coalesce(started_at, now()),
                      status = ?, completed_at = case when ? then coalesce(completed_at, now()) else completed_at end, final_score = coalesce(?, final_score),
                      final_score_label = coalesce(?, final_score_label), passed = case when ? then true else passed end
                    where id = ?",
            [json_encode($progress), $pct, $status, $complete ? 'true' : 'false', $final, $label, $complete ? 'true' : 'false', $e->id]);

        $certificate = $complete ? self::certificate($e, $final) : null;
        if ($complete && $e->status !== 'completed') {
            AulAlerts::completed($e->id);   // M-AUL §10 «Curso completado» (nunca interrumpe el avance)
        }

        return ['progress_pct' => $pct, 'status' => $status, 'final_score' => $final, 'final_score_label' => $label, 'certificate' => $certificate,
            'missing_active_seconds' => max(0, $needSeconds - (int) $e->active_seconds_total), 'score_below_passing' => ! $scoreOk && $final !== null];
    }

    /**
     * Certificado al completar el curso (si el curso emite certificado): con código verificable por QR, como los de
     * capacitaciones presenciales. Lo valida quien publicó el curso (o quien lo creó). No se duplica (RN-AUL-18).
     */
    public static function certificate(object $e, ?float $score): ?array
    {
        $c = DB::selectOne('select id, title, hours, certificate_enabled, certificate_validity_months, public_verification_enabled, published_by, created_by from courses where id = ?', [$e->course_id]);
        if (! $c || ! $c->certificate_enabled) {
            return null;
        }
        $existing = DB::selectOne("select certificate_code from training_certificates where course_enrollment_id = ? and deleted_at is null and status <> 'voided'", [$e->id]);
        if ($existing) {
            return ['code' => $existing->certificate_code];
        }
        $holder = $e->worker_id
            ? DB::selectOne('select full_name as name, document_last4 as last4 from workers where id = ?', [$e->worker_id])
            : ($e->external_learner_id ? DB::selectOne("select first_name || ' ' || last_name as name, document_last4 as last4 from external_learners where id = ?", [$e->external_learner_id])
                : DB::selectOne('select first_name || \' \' || paternal_last_name as name, document_last4 as last4 from contractor_workers where id = ?', [$e->contractor_worker_id]));
        $year = date('Y');
        $n = (int) DB::selectOne('select count(*) + 1 as n from training_certificates where certificate_code like ?', ["CERT-{$year}-%"])->n;
        do {
            $code = sprintf('CERT-%s-%06d', $year, $n++);
        } while (DB::selectOne('select 1 as ok from training_certificates where certificate_code = ?', [$code]));
        $validator = $c->published_by ?? $c->created_by ?? (string) auth()->id();
        // RN-AUL-18 / RN-CAP-04: el certificado cuenta para la capacitación de CAP ligada al curso (matriz, fotocheck, permisos).
        $training = DB::selectOne('select id from trainings where course_id = ? and deleted_at is null order by created_at limit 1', [$c->id])?->id;
        DB::insert(
            "insert into training_certificates (company_id, course_id, training_id, course_enrollment_id, worker_id, contractor_worker_id, external_learner_id, certificate_code, verification_code,
                holder_name, holder_document_masked, course_title, issued_on, expires_on, hours, score, public_verification_enabled, status, validated_by, validated_at, created_by, updated_by)
             values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, current_date, case when ?::int is null then null else (current_date + make_interval(months => ?::int))::date end, ?, ?, ?, 'validated', ?, now(), ?, ?)",
            [$e->company_id, $c->id, $e->external_learner_id ? null : $training, $e->id, $e->worker_id, $e->contractor_worker_id, $e->external_learner_id, $code, strtoupper(substr(hash('sha256', Str::random(40)), 0, 32)),
                $holder->name ?? 'Participante', ($holder->last4 ?? null) ? '****'.$holder->last4 : null, $c->title, $c->certificate_validity_months, $c->certificate_validity_months,
                $c->hours, $score, $c->public_verification_enabled ? 'true' : 'false', $validator, (string) auth()->id(), (string) auth()->id()]
        );
        $id = DB::selectOne('select id from training_certificates where certificate_code = ?', [$code])->id;
        DB::update('update course_enrollments set certificate_id = ? where id = ?', [$id, $e->id]);

        return ['code' => $code, 'id' => $id];
    }
}
