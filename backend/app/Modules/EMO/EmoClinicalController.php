<?php

namespace App\Modules\EMO;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Modules\VUL\MedicalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resultado clínico de EMO en la base médica (M-EMO SCR-EMO-03 y SCR-EMO-05; RN-EMO-01..04, 09).
 *
 *  POST /emo/reauth                          contraseña + código de la app (o de recuperación) + motivo → clave médica por unos minutos (la misma que usa VMD)
 *  GET  /emo/orders/{order}/clinical         orden (base principal) + resultado clínico y archivos (base médica)
 *  PUT  /emo/orders/{order}/clinical         registra o corrige el resultado; con aptitud la publica en fitness_status
 *  POST /emo/exams/{exam}/files              adjunta un archivo (PDF o imagen) al resultado, cifrado en el disco médico
 *  GET  /emo/files/{file}                    descarga del archivo clínico
 *  GET  /emo/workers/{worker}/history        historia ocupacional del trabajador (todos sus EMO con archivos)
 *
 * Todo exige la clave médica (cabecera X-Medical-Reauth). Cada acceso se registra antes en sensitive_access_logs
 * (base principal; si el registro falla se deniega) y en medical_access_logs (base médica). Hallazgos, diagnósticos
 * CIE-10, recomendaciones y archivos se guardan cifrados con la llave médica. A la base principal solo pasan
 * estado de aptitud, vigencia y restricciones laborales.
 */
class EmoClinicalController extends Controller
{
    private const FILE_TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    private const FILE_KINDS = 'full_report,lab_result,imaging,audiometry,spirometry,certificate,consent,other';

    public function reauth(Request $request): JsonResponse
    {
        // Contraseña + segundo factor + motivo (M-EMO §7), con la misma clave médica que VMD, VUL y DES.
        return response()->json(['data' => MedicalAccess::reauth($request, ['emo.medical_exams.read_sensitive', 'emo.medical_exams.create'],
            'Solo el médico ocupacional ve y registra resultados clínicos; tu rol ve solo la aptitud.')]);
    }

    public function show(Request $request, string $order): JsonResponse
    {
        $o = $this->order($order);
        $this->can('emo.medical_exams.read_sensitive');
        $reauth = $this->reauthId($request);
        $this->audit('medical_exam', $o->medical_exam_ref, $o->worker_id, 'read', $reauth, $request->input('purpose'));
        [$exam, $files] = $this->med($reauth, function ($db) use ($o, $request) {
            $e = $db->selectOne('select * from medical_exams where medical_exam_order_ref = ? and deleted_at is null order by created_at desc limit 1', [$o->id]);
            $this->log($db, 'medical_exam', $e?->id, $o->worker_id, 'read', $request->input('purpose'));

            return [$e, $e ? $this->filesOf($db, [$e->id]) : []];
        });

        return response()->json(['data' => [
            'order' => $this->orderOut($o),
            'exam' => $exam ? $this->examOut($exam, $files) : null,
            'can_edit' => $this->has($exam ? 'emo.medical_exams.update' : 'emo.medical_exams.create'),
            'can_issue_fitness' => $this->canIssueFitness(),
            'can_upload' => $this->has('emo.medical_exam_files.create'),
        ]]);
    }

    public function save(Request $request, string $order): JsonResponse
    {
        $this->canAny(['emo.medical_exams.create', 'emo.medical_exams.update']);
        $o = $this->order($order);
        if (in_array($o->status, ['cancelled', 'no_show'], true)) {
            throw new ApiProblem(409, 'conflict', 'Orden sin atención', 'La orden está anulada o el trabajador no asistió: no se registran resultados.');
        }
        $d = $request->validate([
            'exam_on' => ['required', 'date', 'before_or_equal:today'],
            'clinic_name' => ['nullable', 'string', 'max:200'], 'clinic_ruc' => ['nullable', 'digits:11'],
            'examining_doctor_name' => ['nullable', 'string', 'max:200'], 'examining_doctor_cmp' => ['nullable', 'string', 'max:20'],
            'results' => ['nullable', 'array', 'max:30'], 'results.*.exam' => ['required', 'string', 'max:80'], 'results.*.result' => ['nullable', 'string', 'max:2000'],
            'observations' => ['nullable', 'string', 'max:5000'],
            'diagnoses' => ['nullable', 'array', 'max:20'],
            'diagnoses.*.code' => ['required', 'string', 'regex:/^[A-Z][0-9]{2}(\.[0-9A-Z]{1,4})?$/'], 'diagnoses.*.description' => ['nullable', 'string', 'max:300'],
            'recommendations' => ['nullable', 'string', 'max:5000'],
            'fitness_result' => ['required', 'in:pending,fit,fit_with_restrictions,unfit'],
            'work_restrictions' => ['nullable', 'string', 'max:1000'],
            'fitness_valid_until' => ['nullable', 'date', 'after_or_equal:exam_on'],
        ], ['diagnoses.*.code.regex' => 'El código CIE-10 no es válido (ej.: H83.3).'], [
            'exam_on' => 'fecha del examen', 'clinic_ruc' => 'RUC de la clínica', 'fitness_result' => 'aptitud', 'work_restrictions' => 'restricciones',
            'fitness_valid_until' => 'vigencia', 'diagnoses.*.code' => 'código CIE-10',
        ]);
        $fitness = $d['fitness_result'];
        if ($fitness !== 'pending' && ! $this->canIssueFitness()) {
            throw ApiProblem::forbidden('Solo el médico ocupacional registra la aptitud.');
        }
        if ($fitness === 'fit_with_restrictions' && empty($d['work_restrictions'])) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Describe las restricciones de trabajo.', ['errors' => ['work_restrictions' => ['Describe las restricciones de trabajo (en términos laborales, sin diagnóstico).']]]);
        }
        // RN-EMO-05: la vigencia es la fecha del examen más la periodicidad del protocolo del puesto (emo_periodicity_param del
        // puesto o emo_periodic_months); el médico puede fijar una menor, nunca una mayor.
        if (in_array($fitness, ['fit', 'fit_with_restrictions'], true)) {
            $paramKey = $o->position_id ? (DB::selectOne('select emo_periodicity_param from positions where id = ?', [$o->position_id])?->emo_periodicity_param ?: null) : null;
            $months = max(1, (int) \App\Core\Support\LegalParams::number($paramKey ?: 'emo_periodic_months', (float) \App\Core\Support\LegalParams::number('emo_periodic_months', 24)));
            $max = date('Y-m-d', strtotime($d['exam_on'].' +'.$months.' months'));
            if (empty($d['fitness_valid_until'])) {
                $d['fitness_valid_until'] = $max;
            } elseif ($d['fitness_valid_until'] > $max) {
                throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', "La vigencia no puede pasar de {$months} meses desde el examen (hasta el ".date('d/m/Y', strtotime($max)).').',
                    ['errors' => ['fitness_valid_until' => ["Como máximo hasta el ".date('d/m/Y', strtotime($max)).'.']]]);
            }
        }
        $reauth = $this->reauthId($request);

        $results = array_values(array_map(fn ($r) => ['exam' => trim($r['exam']), 'result' => $r['result'] ?? null], $d['results'] ?? []));
        $summary = MedicalCipher::encrypt(json_encode(['results' => $results, 'observations' => $d['observations'] ?? null], JSON_UNESCAPED_UNICODE));
        $dx = array_values(array_map(fn ($x) => ['code' => $x['code'], 'description' => $x['description'] ?? null], $d['diagnoses'] ?? []));
        $diagnoses = json_encode(MedicalCipher::encrypt(json_encode($dx, JSON_UNESCAPED_UNICODE)));
        $performed = '{'.implode(',', array_map(fn ($r) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $r['exam']).'"', $results)).'}';
        $restrictions = $fitness === 'pending' ? null : ($d['work_restrictions'] ?? null);
        $validUntil = in_array($fitness, ['fit', 'fit_with_restrictions'], true) ? $d['fitness_valid_until'] : null;

        [$examId, $publish, $created] = $this->med($reauth, function ($db) use ($o, $d, $summary, $diagnoses, $performed, $fitness, $restrictions, $validUntil) {
            $e = $db->selectOne('select * from medical_exams where medical_exam_order_ref = ? and deleted_at is null order by created_at desc limit 1 for update', [$o->id]);
            if ($e && $e->status === 'annulled') {
                throw new ApiProblem(409, 'conflict', 'Resultado anulado', 'El resultado de esta orden fue anulado.');
            }
            $this->can($e ? 'emo.medical_exams.update' : 'emo.medical_exams.create');
            $issued = $fitness !== 'pending';
            $publish = $issued && (! $e || $e->fitness_status_synced_at === null || $e->fitness_result !== $fitness
                || (string) $e->work_restrictions !== (string) $restrictions || (string) $e->fitness_valid_until !== (string) $validUntil);
            $status = $issued ? 'fitness_issued' : ($this->canIssueFitness() ? 'under_review' : 'received');
            $vals = [$d['exam_on'], $d['clinic_name'] ?? $o->clinic_name, $d['clinic_ruc'] ?? $o->clinic_ruc, $d['examining_doctor_name'] ?? null, $d['examining_doctor_cmp'] ?? null,
                $performed, $summary, $diagnoses, MedicalCipher::encrypt($d['recommendations'] ?? null), $fitness, $restrictions, $validUntil];
            if ($e) {
                $db->update('update medical_exams set exam_on = ?, clinic_name = ?, clinic_ruc = ?, examining_doctor_name = ?, examining_doctor_cmp = ?, exams_performed = ?::text[],
                                    results_summary = ?, diagnoses = ?::jsonb, recommendations = ?, fitness_result = ?, work_restrictions = ?, fitness_valid_until = ?::date,
                                    reviewed_by_user_id = case when ? then ?::uuid else reviewed_by_user_id end, reviewed_at = case when ? then now() else reviewed_at end,
                                    status = ?, updated_by = ? where id = ?',
                    [...$vals, $issued, $this->uid(), $issued, $status, $this->uid(), $e->id]);
                $this->log($db, 'medical_exam', $e->id, $o->worker_id, 'update', null);

                return [$e->id, $publish, false];
            }
            $id = $db->selectOne('insert into medical_exams (exam_on, clinic_name, clinic_ruc, examining_doctor_name, examining_doctor_cmp, exams_performed, results_summary, diagnoses,
                                         recommendations, fitness_result, work_restrictions, fitness_valid_until, company_id, worker_id, medical_exam_order_ref, exam_type, protocol_code,
                                         reviewed_by_user_id, reviewed_at, status, created_by, updated_by)
                                  values (?, ?, ?, ?, ?, ?::text[], ?, ?::jsonb, ?, ?, ?, ?::date, ?, ?, ?, ?, ?, ?::uuid, case when ? then now() end, ?, ?, ?) returning id',
                [...$vals, $this->company(), $o->worker_id, $o->id, $o->exam_type, $o->protocol_code, $issued ? $this->uid() : null, $issued, $status, $this->uid(), $this->uid()])->id;
            $this->log($db, 'medical_exam', $id, $o->worker_id, 'create', null);

            return [$id, $publish, true];
        });
        $this->audit('medical_exam', $examId, $o->worker_id, $created ? 'create' : 'update', $reauth, null);

        // Base principal: la orden apunta al resultado y, con aptitud, se publica solo estado + vigencia + restricción laboral (RN-EMO-02).
        DB::update("update medical_exam_orders set medical_exam_ref = ?, status = case when status in ('scheduled', 'attended') then 'results_received' else status end,
                           attended_at = coalesce(attended_at, ?::date), updated_by = ? where id = ?", [$examId, $d['exam_on'], $this->uid(), $o->id]);
        if ($publish) {
            DB::update('update fitness_status set is_current = false, updated_by = ? where worker_id = ? and is_current and deleted_at is null', [$this->uid(), $o->worker_id]);
            DB::insert('insert into fitness_status (company_id, worker_id, medical_exam_order_id, status, valid_from, valid_until, work_restrictions, issued_by, issued_at, is_current, created_by, updated_by)
                        values (?, ?, ?, ?, ?, ?, ?, ?, now(), true, ?, ?)',
                [$this->company(), $o->worker_id, $o->id, $fitness, $d['exam_on'], $validUntil, $restrictions, $this->uid(), $this->uid(), $this->uid()]);
            DB::update("update medical_exam_orders set status = 'fitness_issued' where id = ?", [$o->id]);
        }
        // M-PAS RN-PAS-05: el examen realizado ejecuta la actividad del programa con regla automática de EMO (solo la orden, sin datos clínicos).
        \App\Modules\REC\Rules\DocsFieldRules::emoOrderExecuted((string) $o->id);
        if ($publish) {
            if ($fid = DB::selectOne('select id from fitness_status where worker_id = ? and is_current and deleted_at is null order by created_at desc limit 1', [$o->worker_id])?->id) {
                try {
                    \App\Modules\NOT\FichaEvents::fitness($fid, [], null);
                } catch (\Throwable) {
                }
            }
            $this->med($reauth, fn ($db) => $db->update('update medical_exams set fitness_status_synced_at = now() where id = ?', [$examId]));
        }
        // M-VMD §7 paso 2: un resultado que cumple el criterio (aptitudes del parámetro vmd_auto_case_fitness) abre caso en los
        // programas activos del puesto del trabajador, si no tiene ya uno abierto en ese programa.
        $auto = json_decode(\App\Core\Support\LegalParams::text('vmd_auto_case_fitness', '[]'), true);
        if (is_array($auto) && in_array($fitness, $auto, true)
            && ($pos = DB::selectOne('select current_position_id from workers where id = ?', [$o->worker_id])?->current_position_id)) {
            $this->med($reauth, function ($db) use ($o, $pos, $d) {
                foreach ($db->select("select id from surveillance_programs where deleted_at is null and status = 'active' and ?::uuid = any(target_positions)", [$pos]) as $p) {
                    if ($db->selectOne("select 1 as ok from surveillance_cases where program_id = ? and worker_id = ? and status <> 'closed' and deleted_at is null", [$p->id, $o->worker_id])) {
                        continue;
                    }
                    $case = $db->selectOne("insert into surveillance_cases (company_id, program_id, worker_id, opened_on, reason, status, assigned_doctor_user_id, created_by, updated_by)
                                             values (?, ?, ?, current_date, ?, 'open', ?, ?, ?) returning id",
                        [$this->company(), $p->id, $o->worker_id, 'Abierto desde el EMO del '.date('d/m/Y', strtotime($d['exam_on'])), $this->uid(), $this->uid(), $this->uid()])->id;
                    $this->log($db, 'surveillance_case', $case, $o->worker_id, 'create', null);
                }
            });
        }

        return response()->json(['data' => ['id' => $examId, 'fitness_published' => $publish]], $created ? 201 : 200);
    }

    public function upload(Request $request, string $exam): JsonResponse
    {
        $this->can('emo.medical_exam_files.create');
        $reauth = $this->reauthId($request);
        $request->validate(['file_kind' => ['required', 'in:'.self::FILE_KINDS]], [], ['file_kind' => 'tipo de archivo']);
        $file = $request->file('file');
        if (! $file || ! $file->isValid()) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa el archivo', 'Elige un archivo.', ['errors' => ['file' => ['Elige un archivo.']]]);
        }
        $mime = \App\Core\Support\UploadGuard::realMime($file);
        if (! isset(self::FILE_TYPES[$mime])) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa el archivo', 'Solo se aceptan PDF o imágenes (JPG, PNG).', ['errors' => ['file' => ['Tipo de archivo no permitido.']]]);
        }
        if ($file->getSize() > 15 * 1024 * 1024) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa el archivo', 'El archivo pesa más de 15 MB.', ['errors' => ['file' => ['Máximo 15 MB.']]]);
        }
        $disk = (string) config('escudo.medical.files_disk', 'local');
        $id = (string) Str::uuid7();
        $key = "medical/{$this->company()}/".date('Y/m')."/{$id}.bin";
        $plain = (string) file_get_contents($file->getRealPath());

        $workerId = $this->med($reauth, function ($db) use ($exam, $id, $disk, $key, $plain, $file, $mime, $request) {
            $e = Str::isUuid($exam) ? $db->selectOne("select id, worker_id from medical_exams where id = ? and deleted_at is null and status <> 'annulled'", [$exam]) : null;
            if (! $e) {
                throw ApiProblem::notFound('El resultado no existe: guarda primero el resultado del examen.');
            }
            Storage::disk($disk)->put($key, MedicalCipher::encrypter()->encryptString($plain));
            $db->insert("insert into medical_exam_files (id, company_id, medical_exam_id, worker_id, file_kind, bucket, object_key, original_name, mime_type, size_bytes, sha256, status,
                                uploaded_by_user_id, created_by, updated_by)
                         values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, decode(?, 'hex'), 'available', ?, ?, ?)",
                [$id, $this->company(), $e->id, $e->worker_id, $request->input('file_kind'), $disk, $key, MedicalCipher::encrypt(mb_substr($file->getClientOriginalName(), 0, 200)),
                    $mime, strlen($plain), hash('sha256', $plain), $this->uid(), $this->uid(), $this->uid()]);
            $this->log($db, 'medical_file', $id, $e->worker_id, 'create', null);

            return $e->worker_id;
        });
        $this->audit('medical_file', $id, $workerId, 'create', $reauth, null);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function download(Request $request, string $file): Response
    {
        $this->can('emo.medical_exam_files.read_sensitive');
        $reauth = $this->reauthId($request);
        $f = $this->med($reauth, function ($db) use ($file) {
            $f = Str::isUuid($file) ? $db->selectOne("select * from medical_exam_files where id = ? and deleted_at is null and status = 'available'", [$file]) : null;
            if (! $f) {
                throw ApiProblem::notFound('El archivo no existe.');
            }

            return $f;
        });
        $this->audit('medical_file', $f->id, $f->worker_id, 'download', $reauth, $request->input('purpose'));
        $this->med($reauth, fn ($db) => $this->log($db, 'medical_file', $f->id, $f->worker_id, 'download', $request->input('purpose')));
        $bytes = MedicalCipher::encrypter()->decryptString((string) Storage::disk($f->bucket)->get($f->object_key));
        $name = str_replace(['"', "\r", "\n"], '', MedicalCipher::decrypt($f->original_name) ?? 'archivo-medico');

        return response($bytes, 200, [
            'Content-Type' => $f->mime_type,
            'Content-Disposition' => "inline; filename=\"{$name}\"",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function history(Request $request, string $worker): JsonResponse
    {
        $w = Str::isUuid($worker) ? DB::selectOne('select w.id, w.full_name, w.document_last4, p.name as position from workers w left join positions p on p.id = w.current_position_id where w.id = ? and w.deleted_at is null', [$worker]) : null;
        if (! $w) {
            throw ApiProblem::notFound('El trabajador no existe.');
        }
        $this->can('emo.medical_exams.read_sensitive');
        $reauth = $this->reauthId($request);
        $this->audit('medical_exam', null, $w->id, 'read', $reauth, $request->input('purpose') ?: 'Historia ocupacional');
        $exams = $this->med($reauth, function ($db) use ($w, $request) {
            $this->log($db, 'medical_exam', null, $w->id, 'read', $request->input('purpose') ?: 'Historia ocupacional');
            $rows = $db->select('select * from medical_exams where worker_id = ? and deleted_at is null order by exam_on desc, created_at desc', [$w->id]);
            $files = $rows ? $this->filesOf($db, array_column($rows, 'id')) : [];

            return array_map(fn ($e) => $this->examOut($e, $files), $rows);
        });
        $fit = DB::selectOne('select status, valid_from, valid_until, work_restrictions from fitness_status where worker_id = ? and is_current and deleted_at is null limit 1', [$w->id]);

        return response()->json(['data' => [
            'worker' => ['id' => $w->id, 'full_name' => $w->full_name, 'document_masked' => $w->document_last4 ? '****'.$w->document_last4 : null, 'position' => $w->position],
            'fitness' => $fit,
            'exams' => $exams,
        ]]);
    }

    // ── apoyo ──

    private function order(string $id): object
    {
        $o = Str::isUuid($id) ? DB::selectOne('select o.*, w.full_name as worker, w.document_last4, p.name as position
                                                  from medical_exam_orders o join workers w on w.id = o.worker_id left join positions p on p.id = coalesce(o.position_id, w.current_position_id)
                                                 where o.id = ? and o.deleted_at is null', [$id]) : null;
        if (! $o) {
            throw ApiProblem::notFound('La orden de examen no existe.');
        }

        return $o;
    }

    private function orderOut(object $o): array
    {
        return ['id' => $o->id, 'worker_id' => $o->worker_id, 'worker' => $o->worker, 'document_masked' => $o->document_last4 ? '****'.$o->document_last4 : null,
            'position' => $o->position, 'exam_type' => $o->exam_type, 'protocol_code' => $o->protocol_code, 'clinic_name' => $o->clinic_name, 'clinic_ruc' => $o->clinic_ruc,
            'scheduled_at' => $o->scheduled_at, 'status' => $o->status];
    }

    private function examOut(object $e, array $files): array
    {
        $summary = json_decode((string) MedicalCipher::decrypt($e->results_summary), true) ?: [];
        $dxRaw = $e->diagnoses ? json_decode((string) $e->diagnoses, true) : null;

        return [
            'id' => $e->id, 'order_id' => $e->medical_exam_order_ref, 'exam_type' => $e->exam_type, 'exam_on' => $e->exam_on, 'clinic_name' => $e->clinic_name, 'clinic_ruc' => $e->clinic_ruc,
            'examining_doctor_name' => $e->examining_doctor_name, 'examining_doctor_cmp' => $e->examining_doctor_cmp,
            'results' => $summary['results'] ?? [], 'observations' => $summary['observations'] ?? null,
            'diagnoses' => is_string($dxRaw) ? (json_decode((string) MedicalCipher::decrypt($dxRaw), true) ?: []) : [],
            'recommendations' => MedicalCipher::decrypt($e->recommendations),
            'fitness_result' => $e->fitness_result, 'work_restrictions' => $e->work_restrictions, 'fitness_valid_until' => $e->fitness_valid_until,
            'status' => $e->status, 'reviewed_at' => $e->reviewed_at,
            'files' => array_values(array_filter($files, fn ($f) => $f['exam_id'] === $e->id)),
        ];
    }

    private function filesOf($db, array $examIds): array
    {
        return array_map(fn ($f) => ['id' => $f->id, 'exam_id' => $f->medical_exam_id, 'file_kind' => $f->file_kind, 'name' => MedicalCipher::decrypt($f->original_name) ?? 'archivo',
            'mime_type' => $f->mime_type, 'size_bytes' => (int) $f->size_bytes, 'created_at' => $f->created_at],
            $db->select("select id, medical_exam_id, file_kind, original_name, mime_type, size_bytes, created_at from medical_exam_files
                          where medical_exam_id = any(?::uuid[]) and deleted_at is null and status = 'available' order by created_at", ['{'.implode(',', $examIds).'}']));
    }

    /** Ejecuta en la base médica dentro de una transacción con el contexto RLS de la empresa, el usuario y la re-autenticación. */
    private function med(string $reauth, callable $fn): mixed
    {
        $db = DB::connection('medical');

        return $db->transaction(function () use ($db, $fn, $reauth) {
            $db->select("select set_config('app.company_id', ?, true), set_config('app.user_id', ?, true), set_config('app.reauth_session_id', ?, true)",
                [$this->company(), $this->uid(), $reauth]);

            return $fn($db);
        });
    }

    /** Clave médica vigente (la misma de VMD, VUL y DES) o 403 reauth_required. */
    private function reauthId(Request $request): string
    {
        return MedicalAccess::reauthId($request);
    }

    /** Registro inalterable en la base médica (med_log_access exige la re-autenticación fijada en la sesión). */
    private function log($db, string $resource, ?string $resourceId, ?string $workerId, string $action, ?string $purpose): void
    {
        $db->select('select med_log_access(?, ?::uuid, ?, ?::uuid, ?, ?, ?::inet)',
            [$resource, $resourceId, $action, $workerId, app(CompanyContext::class)->roleCode, MedicalAccess::purpose($purpose), request()->ip()]);
    }

    /** Registro en sensitive_access_logs de la base principal (previo a la lectura: si falla, la petición falla). */
    private function audit(string $resource, ?string $resourceId, ?string $workerId, string $action, ?string $reauth, ?string $purpose): void
    {
        DB::insert("insert into sensitive_access_logs (company_id, user_id, role_code, worker_id, resource, resource_id, action, purpose, reauth_session_id, result, ip, user_agent, created_by)
                    values (?, ?, ?, ?, ?, ?, ?, ?, ?, 'granted', ?::inet, ?, ?)",
            [$this->company(), $this->uid(), app(CompanyContext::class)->roleCode, $workerId, $resource, $resourceId, $action, MedicalAccess::purpose($purpose), $reauth,
                request()->ip(), mb_substr((string) request()->userAgent(), 0, 300), $this->uid()]);
    }

    private function canIssueFitness(): bool
    {
        return $this->has('emo.medical_exams.update') && $this->has('emo.fitness_status.create');
    }

    private function has(string $p): bool
    {
        return in_array($p, Permissions::effective(), true);
    }

    private function can(string $p): void
    {
        if (! $this->has($p)) {
            throw ApiProblem::forbidden('Solo el médico ocupacional ve y registra resultados clínicos; tu rol ve solo la aptitud.');
        }
    }

    private function canAny(array $perms): void
    {
        if (! array_intersect($perms, Permissions::effective())) {
            throw ApiProblem::forbidden('Solo el médico ocupacional ve y registra resultados clínicos; tu rol ve solo la aptitud.');
        }
    }

    private function company(): string
    {
        return app(CompanyContext::class)->companyId;
    }

    private function uid(): string
    {
        return (string) auth()->id();
    }
}
