<?php

namespace App\Modules\CAP;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
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
        $people = DB::select(
            "select w.id as worker_id, w.full_name, w.document_last4, coalesce(sum(a.hours), 0) as hours,
                    max(e.score) as score, bool_or(e.passed) as passed
               from training_attendances a
               join training_sessions s on s.id = a.session_id and s.status in ('held') and s.deleted_at is null
               join workers w on w.id = a.worker_id
               left join training_evaluations e on e.training_id = s.training_id and e.worker_id = w.id and e.evaluation_type = 'post' and e.deleted_at is null
              where s.training_id = ? and a.attended and a.deleted_at is null
              group by w.id, w.full_name, w.document_last4",
            [$training]
        );
        if ($people === []) {
            throw new ApiProblem(409, 'conflict', 'Sin asistentes', 'Marca una sesión como realizada con sus asistencias antes de emitir certificados.');
        }
        $today = DB::selectOne("select (now() at time zone 'America/Lima')::date as d")->d;
        $year = substr($today, 0, 4);
        $issued = 0;
        $skipped = [];
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
            DB::insert(
                "insert into training_certificates (company_id, training_id, worker_id, certificate_code, verification_code, holder_name, holder_document_masked,
                    course_title, issued_on, expires_on, hours, score, public_verification_enabled, status, validated_by, validated_at, created_by, updated_by)
                 values (?, ?, ?, ?, ?, ?, ?, ?, ?, case when ?::int is null then null else (?::date + make_interval(months => ?::int))::date end, ?, ?, false, 'validated', ?, now(), ?, ?)",
                [$t->company_id, $training, $p->worker_id, $code, strtoupper(substr(hash('sha256', Str::random(40)), 0, 32)), $p->full_name, $p->document_last4 ? '****'.$p->document_last4 : null,
                    $t->title, $today, $t->certificate_validity_months, $today, $t->certificate_validity_months, $hours, $p->score,
                    (string) auth()->id(), (string) auth()->id(), (string) auth()->id()]
            );
            $issued++;
        }

        return response()->json(['data' => ['issued' => $issued, 'skipped' => $skipped]]);
    }
}
