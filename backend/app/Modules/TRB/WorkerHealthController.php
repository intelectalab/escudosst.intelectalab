<?php

namespace App\Modules\TRB;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\EMO\MedicalCipher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mis exámenes (M-EMO SCR-EMO-07) en la app del trabajador; docs/05 §4.3 y docs/10 §4.
 *
 *  GET  /me/worker/health                 sin re-autenticación: aptitud (estado, vigencia, restricción laboral) y próxima cita
 *  POST /me/worker/health/reauth          confirma con su PIN (o su contraseña si tiene) → clave médica por unos minutos
 *  GET  /me/worker/health/results         sus resultados clínicos ya revisados por el médico (cabecera X-Medical-Reauth)
 *  GET  /me/worker/health/files/{id}      descarga de su informe (cabecera X-Medical-Reauth)
 *
 * Solo los del propio trabajador; un id de otro responde 404. Cada lectura queda en sensitive_access_logs y en el registro
 * de accesos de la base médica.
 */
class WorkerHealthController extends Controller
{
    private const PREFIX = 'worker-med-reauth:';

    public function summary(): JsonResponse
    {
        $this->module();
        $w = WorkerSelf::row();
        $fit = DB::selectOne('select status, valid_from, valid_until, work_restrictions from fitness_status where worker_id = ? and is_current and deleted_at is null limit 1', [$w->id]);
        $next = DB::selectOne("select exam_type, scheduled_at, clinic_name, status from medical_exam_orders where worker_id = ? and deleted_at is null and status = 'scheduled'
                                order by scheduled_at nulls last limit 1", [$w->id]);
        $last = DB::selectOne("select exam_type, coalesce(attended_at, scheduled_at) as at from medical_exam_orders where worker_id = ? and deleted_at is null
                                and status in ('attended', 'results_received', 'fitness_issued') order by coalesce(attended_at, scheduled_at) desc nulls last limit 1", [$w->id]);

        return response()->json(['data' => ['fitness' => $fit, 'next_exam' => $next, 'last_exam' => $last, 'reauth_minutes' => $this->ttl()]]);
    }

    public function reauth(Request $request): JsonResponse
    {
        $this->module();
        $w = WorkerSelf::row();
        $password = (string) $request->input('password');
        if ($password !== '') {
            $hash = (string) $request->user()->getAuthPassword();
            if ($hash === '' || ! Hash::check($password, $hash)) {
                throw new ApiProblem(422, 'validation_failed', 'Contraseña incorrecta', 'La contraseña no es correcta.', ['errors' => ['password' => ['La contraseña no es correcta.']]]);
            }
        } else {
            WorkerAppAccess::requirePin($w->id, $request->input('pin'));
        }
        $id = (string) Str::uuid();
        $expires = now()->addMinutes($this->ttl());
        Cache::put(self::PREFIX.$id, ['user' => (string) auth()->id(), 'company' => $this->company(), 'worker' => $w->id], $expires);
        DB::insert("insert into sessions_mfa (id, user_id, token_hash, ip, user_agent, mfa_method, reauth_at, reauth_expires_at, reauth_purpose, expires_at, created_by)
                    values (?, ?, decode(?, 'hex'), ?::inet, ?, ?, now(), ?, 'medical', ?, ?)",
            [$id, (string) auth()->id(), hash('sha256', $id), $request->ip(), mb_substr((string) $request->userAgent(), 0, 300), $password !== '' ? 'none' : 'pin', $expires, $expires, (string) auth()->id()]);

        return response()->json(['data' => ['reauth_id' => $id, 'expires_at' => $expires->toIso8601String()]]);
    }

    public function results(Request $request): JsonResponse
    {
        $this->module();
        $w = WorkerSelf::row();
        $reauth = $this->reauthId($request, $w->id);
        $this->audit($w->id, 'medical_exam', null, 'read', $reauth);
        $exams = $this->med($reauth, function ($db) use ($w) {
            $this->log($db, 'medical_exam', null, $w->id, 'read');
            $rows = $db->select("select * from medical_exams where worker_id = ? and deleted_at is null and status in ('validated', 'fitness_issued') order by exam_on desc, created_at desc", [$w->id]);
            $files = $rows ? $db->select("select id, medical_exam_id, file_kind, original_name, mime_type, size_bytes from medical_exam_files
                                           where medical_exam_id = any(?::uuid[]) and deleted_at is null and status = 'available' order by created_at",
                ['{'.implode(',', array_column($rows, 'id')).'}']) : [];

            return array_map(function ($e) use ($files) {
                $summary = json_decode((string) MedicalCipher::decrypt($e->results_summary), true) ?: [];
                $dxRaw = $e->diagnoses ? json_decode((string) $e->diagnoses, true) : null;

                return [
                    'id' => $e->id, 'exam_type' => $e->exam_type, 'exam_on' => $e->exam_on, 'clinic_name' => $e->clinic_name, 'examining_doctor_name' => $e->examining_doctor_name,
                    'results' => $summary['results'] ?? [], 'observations' => $summary['observations'] ?? null,
                    'diagnoses' => is_string($dxRaw) ? (json_decode((string) MedicalCipher::decrypt($dxRaw), true) ?: []) : [],
                    'recommendations' => MedicalCipher::decrypt($e->recommendations),
                    'fitness_result' => $e->fitness_result, 'work_restrictions' => $e->work_restrictions, 'fitness_valid_until' => $e->fitness_valid_until,
                    'files' => array_values(array_map(fn ($f) => ['id' => $f->id, 'file_kind' => $f->file_kind, 'name' => MedicalCipher::decrypt($f->original_name) ?? 'archivo',
                        'mime_type' => $f->mime_type, 'size_bytes' => (int) $f->size_bytes], array_filter($files, fn ($f) => $f->medical_exam_id === $e->id))),
                ];
            }, $rows);
        });

        return response()->json(['data' => ['exams' => $exams]]);
    }

    public function file(Request $request, string $file): Response
    {
        $this->module();
        $w = WorkerSelf::row();
        $reauth = $this->reauthId($request, $w->id);
        $f = $this->med($reauth, fn ($db) => Str::isUuid($file) ? $db->selectOne(
            "select f.* from medical_exam_files f join medical_exams e on e.id = f.medical_exam_id
              where f.id = ? and f.deleted_at is null and f.status = 'available' and e.worker_id = ? and e.status in ('validated', 'fitness_issued')", [$file, $w->id]) : null);
        if (! $f) {
            throw ApiProblem::notFound('El archivo no existe.');
        }
        $this->audit($w->id, 'medical_file', $f->id, 'download', $reauth);
        $this->med($reauth, fn ($db) => $this->log($db, 'medical_file', $f->id, $w->id, 'download'));
        $bytes = MedicalCipher::encrypter()->decryptString((string) Storage::disk($f->bucket)->get($f->object_key));
        $name = str_replace(['"', "\r", "\n"], '', MedicalCipher::decrypt($f->original_name) ?? 'mi-informe');

        return response($bytes, 200, ['Content-Type' => $f->mime_type, 'Content-Disposition' => "inline; filename=\"{$name}\"",
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private']);
    }

    // ── Apoyo ──

    private function reauthId(Request $request, string $workerId): string
    {
        $id = (string) $request->header('X-Medical-Reauth');
        $r = Str::isUuid($id) ? Cache::get(self::PREFIX.$id) : null;
        if (! $r || $r['user'] !== (string) auth()->id() || $r['company'] !== $this->company() || $r['worker'] !== $workerId) {
            throw new ApiProblem(403, 'reauth_required', 'Confirma que eres tú', 'Para ver tus resultados médicos escribe tu PIN (dura '.$this->ttl().' minutos).');
        }

        return $id;
    }

    private function med(string $reauth, callable $fn): mixed
    {
        $db = DB::connection('medical');

        return $db->transaction(function () use ($db, $fn, $reauth) {
            $db->select("select set_config('app.company_id', ?, true), set_config('app.user_id', ?, true), set_config('app.reauth_session_id', ?, true)",
                [$this->company(), (string) auth()->id(), $reauth]);

            return $fn($db);
        });
    }

    private function log($db, string $resource, ?string $resourceId, string $workerId, string $action): void
    {
        $db->select('select med_log_access(?, ?::uuid, ?, ?::uuid, ?, ?, ?::inet)', [$resource, $resourceId, $action, $workerId, 'worker', 'Consulta propia (app del trabajador)', request()->ip()]);
    }

    private function audit(string $workerId, string $resource, ?string $resourceId, string $action, string $reauth): void
    {
        DB::insert("insert into sensitive_access_logs (company_id, user_id, role_code, worker_id, resource, resource_id, action, purpose, reauth_session_id, result, ip, user_agent, created_by)
                    values (?, ?, 'worker', ?, ?, ?, ?, 'Consulta propia (app del trabajador)', ?, 'granted', ?::inet, ?, ?)",
            [$this->company(), (string) auth()->id(), $workerId, $resource, $resourceId, $action, $reauth, request()->ip(), mb_substr((string) request()->userAgent(), 0, 300), (string) auth()->id()]);
    }

    private function module(): void
    {
        if (! WorkerSelf::usable('EMO')) {
            throw new ApiProblem(403, 'module_not_in_plan', 'No disponible', 'Esta sección no está activa en tu empresa.', ['module' => 'EMO']);
        }
    }

    private function ttl(): int
    {
        return max(1, (int) config('escudo.medical.reauth_minutes', 15));
    }

    private function company(): string
    {
        return app(CompanyContext::class)->companyId;
    }
}
