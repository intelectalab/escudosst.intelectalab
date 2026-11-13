<?php

namespace App\Modules\CAP;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * POST /cap/trainings/{id}/certificates — emite un certificado a cada trabajador que asistió a una sesión realizada
 * (y aprobó la evaluación si la capacitación exige nota). No duplica: quien ya tiene uno vigente de esta capacitación no recibe otro.
 * - RN-CAP-03: vence a los certificate_validity_months de la emisión (sin vencimiento si no se indica).
 * - RN-CAP-07: código único PREFIJO-AAAA-NNNNNN y código de verificación aleatorio (se guarda solo su hash).
 */
class CertificatesController extends Controller
{
    public function issue(string $training): JsonResponse
    {
        if (! in_array('cap.training_certificates.generate', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no emite certificados.');
        }
        $t = DB::selectOne('select * from trainings where id = ? and deleted_at is null', [$training]);
        if (! $t) {
            throw ApiProblem::notFound('La capacitación no existe o es de otra empresa.');
        }
        // RN-CAP-01: en una capacitación presencial la asistencia cuenta con la firma del participante (firma, QR o lista firmada)
        // y con al menos min_attendance_pct de las horas de la sesión.
        $pct = (float) \App\Core\Support\LegalParams::number('min_attendance_pct', 80);
        $rows = DB::select(
            "select w.id as worker_id, w.full_name, w.document_last4, a.hours, a.signature_id, a.check_in_method,
                    extract(epoch from (s.ends_at - s.starts_at)) / 3600 as session_hours
               from training_attendances a
               join training_sessions s on s.id = a.session_id and s.status in ('held') and s.deleted_at is null
               join workers w on w.id = a.worker_id
              where s.training_id = ? and a.attended and a.deleted_at is null
              order by w.full_name",
            [$training]
        );
        if ($rows === []) {
            throw new ApiProblem(409, 'conflict', 'Sin asistentes', 'Marca una sesión como realizada con sus asistencias antes de emitir certificados.');
        }
        $people = [];
        $skipped = [];
        foreach ($rows as $r) {
            $p = $people[$r->worker_id] ??= (object) ['worker_id' => $r->worker_id, 'full_name' => $r->full_name, 'document_last4' => $r->document_last4, 'hours' => 0.0, 'valid' => 0, 'why' => null];
            $sessionHours = max(0.0, (float) $r->session_hours);
            $signed = $t->modality !== 'in_person' || $r->signature_id || in_array($r->check_in_method, ['qr', 'list', 'virtual'], true);
            $hours = $r->hours !== null ? (float) $r->hours : $sessionHours;
            $enough = $sessionHours <= 0 || $hours + 0.001 >= $sessionHours * $pct / 100;
            if ($signed && $enough) {
                $p->valid++;
                $p->hours += $hours;
            } else {
                $p->why = ! $signed ? 'asistencia sin firma' : 'asistió menos del '.rtrim(rtrim(number_format($pct, 1, '.', ''), '0'), '.').' % de la sesión';
            }
        }
        foreach ($people as $id => $p) {
            if ($p->valid === 0) {
                $skipped[] = "{$p->full_name} ({$p->why})";
                unset($people[$id]);

                continue;
            }
            $e = DB::selectOne("select max(score) as score, bool_or(passed) as passed from training_evaluations
                                 where training_id = ? and worker_id = ? and evaluation_type = 'post' and deleted_at is null", [$training, $id]);
            $p->score = $e?->score;
            $p->passed = $e?->passed;
        }
        $today = DB::selectOne("select (now() at time zone 'America/Lima')::date as d")->d;
        $year = substr($today, 0, 4);
        $issued = 0;
        foreach ($people as $p) {
            // Aprobado: si hay nota, se compara con la nota mínima de la capacitación (misma escala); si no, vale «aprobó» de la evaluación.
            $passed = $p->score !== null && $t->passing_score !== null ? (float) $p->score >= (float) $t->passing_score : (bool) $p->passed;
            if ($t->passing_score !== null && ! $passed) {
                $skipped[] = "{$p->full_name} (".($p->score !== null ? "nota {$p->score}, mínimo {$t->passing_score}" : 'sin evaluación aprobada').')';

                continue;
            }
            // Solo se omite a quien ya tiene un certificado vigente: si venció, la nueva sesión lo vuelve a certificar.
            if (DB::selectOne("select 1 as ok from training_certificates where training_id = ? and worker_id = ? and deleted_at is null
                                 and status in ('validated', 'pending_validation') and (expires_on is null or expires_on >= ?)", [$training, $p->worker_id, $today])) {
                $skipped[] = "{$p->full_name} (ya tiene un certificado vigente)";

                continue;
            }
            $n = (int) DB::selectOne("select count(*) + 1 as n from training_certificates where certificate_code like ?", ["CERT-{$year}-%"])->n;
            do {
                $code = sprintf('CERT-%s-%06d', $year, $n++);
            } while (DB::selectOne('select 1 as ok from training_certificates where certificate_code = ?', [$code]));
            $hours = (float) $p->hours > 0 ? (float) $p->hours : round(((int) $t->duration_minutes) / 60, 2);
            DB::selectOne(
                "insert into training_certificates (company_id, training_id, worker_id, certificate_code, verification_code, holder_name, holder_document_masked,
                    course_title, issued_on, expires_on, hours, score, public_verification_enabled, status, validated_by, validated_at, created_by, updated_by)
                 values (?, ?, ?, ?, ?, ?, ?, ?, ?, case when ?::int is null then null else (?::date + make_interval(months => ?::int))::date end, ?, ?, false, 'validated', ?, now(), ?, ?) returning id",
                [$t->company_id, $training, $p->worker_id, $code, strtoupper(substr(hash('sha256', Str::random(40)), 0, 32)), $p->full_name, $p->document_last4 ? '****'.$p->document_last4 : null,
                    $t->title, $today, $t->certificate_validity_months, $today, $t->certificate_validity_months, $hours, $p->score,
                    (string) auth()->id(), (string) auth()->id(), (string) auth()->id()]
            );
            \App\Modules\NOT\FichaEvents::certificate(DB::selectOne('select id from training_certificates where certificate_code = ?', [$code])->id, 'validated', null);
            $issued++;
        }

        return response()->json(['data' => ['issued' => $issued, 'skipped' => $skipped]]);
    }

    /**
     * POST /cap/certificates/{id}/reissue {reason, holder_name?, hours?, score?} — RN-CAP-11: por un error de datos no se
     * corrige el certificado validado; se emite uno nuevo que lo reemplaza (replaces_certificate_id) y el anterior queda anulado.
     */
    public function reissue(Request $request, string $id): JsonResponse
    {
        if (! in_array('cap.training_certificates.generate', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no emite certificados.');
        }
        $d = $request->validate(['reason' => ['required', 'string', 'max:500'], 'holder_name' => ['nullable', 'string', 'max:200'],
            'hours' => ['nullable', 'numeric', 'min:0'], 'score' => ['nullable', 'numeric', 'min:0']]);
        $old = DB::selectOne('select * from training_certificates where id = ? and deleted_at is null', [$id]);
        if (! $old) {
            throw ApiProblem::notFound('El certificado no existe o es de otra empresa.');
        }
        if ($old->status !== 'validated') {
            throw new ApiProblem(409, 'conflict', 'No se puede reemitir', 'Solo se reemite un certificado validado.');
        }
        $year = date('Y');
        $prefix = explode('-', $old->certificate_code)[0];
        $n = (int) DB::selectOne('select count(*) + 1 as n from training_certificates where certificate_code like ?', ["{$prefix}-{$year}-%"])->n;
        do {
            $code = sprintf('%s-%s-%06d', $prefix, $year, $n++);
        } while (DB::selectOne('select 1 as ok from training_certificates where certificate_code = ?', [$code]));
        $me = (string) auth()->id();
        $new = DB::transaction(function () use ($old, $d, $code, $me) {
            DB::update("update training_certificates set status = 'voided', voided_by = ?, voided_at = now(), void_reason = ?, updated_by = ? where id = ?",
                [$me, 'Reemitido: '.$d['reason'], $me, $old->id]);

            return DB::selectOne(
                "insert into training_certificates (company_id, training_id, course_id, course_enrollment_id, worker_id, contractor_worker_id, external_learner_id, certificate_template_id,
                    certificate_code, verification_code, holder_name, holder_document_masked, course_title, issued_on, expires_on, hours, score, score_label, grading_scale_id,
                    public_verification_enabled, status, validated_by, validated_at, replaces_certificate_id, created_by, updated_by)
                 values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'validated', ?, now(), ?, ?, ?) returning id",
                [$old->company_id, $old->training_id, $old->course_id, $old->course_enrollment_id, $old->worker_id, $old->contractor_worker_id, $old->external_learner_id,
                    $old->certificate_template_id, $code, strtoupper(substr(hash('sha256', Str::random(40)), 0, 32)), $d['holder_name'] ?? $old->holder_name,
                    $old->holder_document_masked, $old->course_title, $old->issued_on, $old->expires_on, $d['hours'] ?? $old->hours, $d['score'] ?? $old->score, $old->score_label,
                    $old->grading_scale_id, $old->public_verification_enabled ? 'true' : 'false', $me, $old->id, $me, $me]
            )->id;
        });
        if ($old->course_enrollment_id) {
            DB::update('update course_enrollments set certificate_id = ? where id = ?', [$new, $old->course_enrollment_id]);
        }
        \App\Modules\NOT\FichaEvents::certificate($old->id, 'voided', 'validated');
        \App\Modules\NOT\FichaEvents::certificate($new, 'validated', null);

        return response()->json(['data' => ['id' => $new, 'certificate_code' => $code, 'replaces_certificate_id' => $old->id]], 201);
    }
}
