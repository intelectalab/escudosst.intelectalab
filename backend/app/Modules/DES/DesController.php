<?php

namespace App\Modules\DES;

use App\Core\Http\ApiProblem;
use App\Modules\EMO\MedicalCipher;
use App\Modules\VUL\MedicalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Descansos médicos y ausentismo (M-DES SCR-DES-01/03/04).
 *
 *  GET  /des/indicators?month=AAAA-MM       KPIs del mes, tasa de ausentismo por área y mes (6 meses), días perdidos y por accidente de trabajo
 *  GET  /des/absences                       ausencias recientes (solo fechas, días y tipo; nunca diagnóstico)
 *  POST /des/reauth                         {password} clave médica (personal de salud y médico)
 *  GET  /des/absences/{id}/medical          detalle médico: CIE-10, CITT, emisor (base médica, clave médica)
 *  PUT  /des/absences/{id}/medical          registra o corrige el detalle médico (RN-DES-03: el diagnóstico solo en la base médica)
 *  POST /des/absences/{id}/certificate      certificado (PDF o imagen), cifrado en el disco médico
 *  GET  /des/absences/{id}/certificate      descarga del certificado
 *
 * Tasa de ausentismo = días perdidos del mes ÷ (trabajadores activos × días del mes) × 100. Los días de una ausencia
 * se cuentan solo dentro del mes (RN-DES-01, días calendario).
 */
class DesController extends Controller
{
    private const TYPES = ['medical_leave' => 'Descanso médico', 'occupational_accident' => 'Accidente de trabajo', 'occupational_disease' => 'Enfermedad ocupacional',
        'maternity' => 'Maternidad', 'paternity' => 'Paternidad', 'permission' => 'Permiso', 'unjustified' => 'Injustificada', 'other' => 'Otro'];

    private const FILE_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

    private const NO_MEDICAL = 'Solo el personal de salud ve el diagnóstico y el certificado; tu rol ve fechas y días.';

    public function indicators(Request $request): JsonResponse
    {
        MedicalAccess::can('des.absences.read', 'Tu rol no ve el ausentismo.');
        $month = (string) $request->query('month', date('Y-m'));
        if (! preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month)) {
            $month = date('Y-m');
        }
        $start = $month.'-01';
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $months[] = date('Y-m', strtotime("{$start} -{$i} months"));
        }
        $from = $months[0].'-01';
        $to = date('Y-m-t', strtotime($start));

        // Días perdidos por mes, área y tipo (solo la parte de cada ausencia que cae en el mes).
        $rows = DB::select(
            "with m as (select generate_series(?::date, ?::date, interval '1 month')::date as ms)
             select to_char(m.ms, 'YYYY-MM') as month, w.current_area_id as area_id, a.absence_type,
                    count(*) as n,
                    sum(greatest(0, least(a.ends_on, (m.ms + interval '1 month - 1 day')::date) - greatest(a.starts_on, m.ms) + 1)) as days
               from m join absences a on a.starts_on <= (m.ms + interval '1 month - 1 day')::date and a.ends_on >= m.ms
               join workers w on w.id = a.worker_id
              where a.deleted_at is null and a.status <> 'cancelled'
              group by 1, 2, 3", [$from, $start]);
        $heads = DB::select("select current_area_id as area_id, count(*) as n from workers where deleted_at is null and status in ('active', 'on_leave') group by 1");
        $headByArea = [];
        foreach ($heads as $h) {
            $headByArea[$h->area_id ?? ''] = (int) $h->n;
        }
        $headTotal = array_sum($headByArea);
        $areaNames = collect(DB::select('select id, name from areas where deleted_at is null'))->pluck('name', 'id');

        $dim = fn (string $ym) => (int) date('t', strtotime($ym.'-01'));
        $rate = fn (int $days, int $head, string $ym) => $head > 0 ? round($days / ($head * $dim($ym)) * 100, 2) : null;

        $byMonth = array_fill_keys($months, 0);
        $accByMonth = array_fill_keys($months, 0);
        $byArea = [];
        foreach ($rows as $r) {
            $d = (int) $r->days;
            $byMonth[$r->month] += $d;
            if ($r->absence_type === 'occupational_accident') {
                $accByMonth[$r->month] += $d;
            }
            $byArea[$r->area_id ?? ''][$r->month] = ($byArea[$r->area_id ?? ''][$r->month] ?? 0) + $d;
        }
        $areas = [];
        foreach (array_unique(array_merge(array_keys($headByArea), array_keys($byArea))) as $areaId) {
            $head = $headByArea[$areaId] ?? 0;
            $areas[] = [
                'area_id' => $areaId ?: null,
                'area' => $areaId ? ($areaNames[$areaId] ?? 'Área') : 'Sin área',
                'workers' => $head,
                'months' => array_map(fn ($ym) => ['month' => $ym, 'days' => $byArea[$areaId][$ym] ?? 0, 'rate' => $rate($byArea[$areaId][$ym] ?? 0, $head, $ym)], $months),
            ];
        }
        usort($areas, fn ($a, $b) => ($b['months'][5]['days'] <=> $a['months'][5]['days']) ?: strcmp($a['area'], $b['area']));

        $accidentCount = (int) DB::selectOne("select count(*) as n from absences where deleted_at is null and status <> 'cancelled' and absence_type = 'occupational_accident'
                                               and starts_on <= ?::date and ends_on >= ?::date", [$to, $start])->n;
        $active = (int) DB::selectOne("select count(*) as n from absences where deleted_at is null and status <> 'cancelled' and current_date between starts_on and ends_on")->n;
        $byType = [];
        foreach ($rows as $r) {
            if ($r->month === $month) {
                $byType[$r->absence_type] = ($byType[$r->absence_type] ?? 0) + (int) $r->days;
            }
        }
        arsort($byType);

        return response()->json(['data' => [
            'month' => $month,
            'months' => $months,
            'kpis' => [
                'lost_days' => $byMonth[$month],
                'absenteeism_rate' => $rate($byMonth[$month], $headTotal, $month),
                'active_leaves' => $active,
                'accident_absences' => $accidentCount,
                'accident_days' => $accByMonth[$month],
                'workers' => $headTotal,
            ],
            'trend' => array_map(fn ($ym) => ['month' => $ym, 'days' => $byMonth[$ym], 'accident_days' => $accByMonth[$ym], 'rate' => $rate($byMonth[$ym], $headTotal, $ym)], $months),
            'by_area' => $areas,
            'by_type' => array_map(fn ($t, $d) => ['type' => $t, 'label' => self::TYPES[$t] ?? $t, 'days' => $d], array_keys($byType), array_values($byType)),
            'is_medical' => MedicalAccess::has('des.medical_leave_details.read_sensitive'),
        ]]);
    }

    public function absences(): JsonResponse
    {
        MedicalAccess::can('des.absences.read', 'Tu rol no ve el ausentismo.');
        $rows = DB::select(
            "select a.id, a.worker_id, w.full_name as worker, ar.name as area, a.absence_type, a.starts_on, a.ends_on, a.days, a.status, a.is_work_related,
                    a.sst_event_id, a.certificate_received, (a.medical_leave_ref is not null) as has_medical
               from absences a join workers w on w.id = a.worker_id left join areas ar on ar.id = w.current_area_id
              where a.deleted_at is null order by a.starts_on desc limit 100");

        return response()->json(['data' => array_map(fn ($a) => (array) $a + ['type_label' => self::TYPES[$a->absence_type] ?? $a->absence_type,
            'active' => $a->status !== 'cancelled' && $a->starts_on <= date('Y-m-d') && $a->ends_on >= date('Y-m-d')], $rows)]);
    }

    public function reauth(Request $request): JsonResponse
    {
        return response()->json(['data' => MedicalAccess::reauth($request, ['des.medical_leave_details.read_sensitive'])]);
    }

    public function showMedical(Request $request, string $id): JsonResponse
    {
        $a = $this->absence($id);
        MedicalAccess::can('des.medical_leave_details.read_sensitive', self::NO_MEDICAL);
        $reauth = MedicalAccess::reauthId($request);
        MedicalAccess::audit('leave_detail', $a->medical_leave_ref, $a->worker_id, 'read', $reauth, $request->input('purpose'));
        $detail = MedicalAccess::med($reauth, function ($db) use ($a, $request) {
            $d = $db->selectOne('select * from medical_leave_details where absence_ref = ? and deleted_at is null', [$a->id]);
            MedicalAccess::log($db, 'leave_detail', $d?->id, $a->worker_id, 'read', $request->input('purpose'));

            return $d;
        });

        return response()->json(['data' => [
            'absence' => $this->absenceOut($a),
            'detail' => $detail ? [
                'id' => $detail->id,
                'diagnosis_code' => MedicalCipher::decrypt($detail->diagnosis_code),
                'diagnosis_description' => MedicalCipher::decrypt($detail->diagnosis_description),
                'citt_number' => MedicalCipher::decrypt($detail->citt_number),
                'issuing_entity' => $detail->issuing_entity,
                'issuing_doctor_name' => MedicalCipher::decrypt($detail->issuing_doctor_name),
                'issuing_doctor_cmp' => $detail->issuing_doctor_cmp,
                'is_work_related' => (bool) $detail->is_work_related,
                'validated_at' => $detail->validated_at,
                'has_certificate' => Storage::disk($this->disk())->exists($this->certKey($detail->id)),
            ] : null,
            'can_edit' => MedicalAccess::has($detail ? 'des.medical_leave_details.update' : 'des.medical_leave_details.create'),
        ]]);
    }

    public function saveMedical(Request $request, string $id): JsonResponse
    {
        $a = $this->absence($id);
        if (! array_intersect(['des.medical_leave_details.create', 'des.medical_leave_details.update'], \App\Core\Auth\Permissions::effective())) {
            throw ApiProblem::forbidden(self::NO_MEDICAL);
        }
        $d = $request->validate([
            'diagnosis_code' => ['required', 'string', 'regex:/^[A-Z][0-9]{2}(\.[0-9A-Z]{1,4})?$/'],
            'diagnosis_description' => ['nullable', 'string', 'max:300'],
            'citt_number' => ['nullable', 'string', 'max:40'],
            'issuing_entity' => ['nullable', 'in:essalud,eps,minsa,private_clinic,other'],
            'issuing_doctor_name' => ['nullable', 'string', 'max:200'],
            'issuing_doctor_cmp' => ['nullable', 'string', 'max:20'],
            'is_work_related' => ['nullable', 'boolean'],
        ], ['diagnosis_code.regex' => 'El código CIE-10 no es válido (ej.: S61.4).'], ['diagnosis_code' => 'código CIE-10', 'issuing_entity' => 'entidad emisora']);
        $reauth = MedicalAccess::reauthId($request);
        $workRelated = (bool) ($d['is_work_related'] ?? in_array($a->absence_type, ['occupational_accident', 'occupational_disease'], true));
        $validate = app(\App\Core\Tenancy\CompanyContext::class)->roleCode === 'occupational_doctor';   // el médico valida el detalle

        [$detailId, $created] = MedicalAccess::med($reauth, function ($db) use ($a, $d, $workRelated, $validate) {
            $cur = $db->selectOne('select id from medical_leave_details where absence_ref = ? and deleted_at is null for update', [$a->id]);
            MedicalAccess::can($cur ? 'des.medical_leave_details.update' : 'des.medical_leave_details.create', self::NO_MEDICAL);
            $vals = [MedicalCipher::encrypt(strtoupper($d['diagnosis_code'])), MedicalCipher::encrypt($d['diagnosis_description'] ?? null), MedicalCipher::encrypt($d['citt_number'] ?? null),
                $d['issuing_entity'] ?? null, MedicalCipher::encrypt($d['issuing_doctor_name'] ?? null), $d['issuing_doctor_cmp'] ?? null, $workRelated, $a->starts_on, $a->ends_on];
            if ($cur) {
                $db->update('update medical_leave_details set diagnosis_code = ?, diagnosis_description = ?, citt_number = ?, issuing_entity = ?, issuing_doctor_name = ?,
                                    issuing_doctor_cmp = ?, is_work_related = ?, starts_on = ?, ends_on = ?,
                                    validated_by_user_id = case when ? then ?::uuid else validated_by_user_id end, validated_at = case when ? then now() else validated_at end,
                                    updated_by = ? where id = ?',
                    [...$vals, $validate, MedicalAccess::uid(), $validate, MedicalAccess::uid(), $cur->id]);
                MedicalAccess::log($db, 'leave_detail', $cur->id, $a->worker_id, 'update');

                return [$cur->id, false];
            }
            $id = $db->selectOne('insert into medical_leave_details (diagnosis_code, diagnosis_description, citt_number, issuing_entity, issuing_doctor_name, issuing_doctor_cmp,
                                         is_work_related, starts_on, ends_on, company_id, absence_ref, worker_id, validated_by_user_id, validated_at, created_by, updated_by)
                                  values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::uuid, case when ? then now() end, ?, ?) returning id',
                [...$vals, MedicalAccess::company(), $a->id, $a->worker_id, $validate ? MedicalAccess::uid() : null, $validate, MedicalAccess::uid(), MedicalAccess::uid()])->id;
            MedicalAccess::log($db, 'leave_detail', $id, $a->worker_id, 'create');

            return [$id, true];
        });
        MedicalAccess::audit('leave_detail', $detailId, $a->worker_id, $created ? 'create' : 'update', $reauth);
        // Base principal: solo la referencia (nunca el diagnóstico).
        DB::update('update absences set medical_leave_ref = ?, updated_by = ? where id = ?', [$detailId, MedicalAccess::uid(), $a->id]);

        return response()->json(['data' => ['id' => $detailId]], $created ? 201 : 200);
    }

    public function uploadCertificate(Request $request, string $id): JsonResponse
    {
        $a = $this->absence($id);
        MedicalAccess::can('des.medical_leave_details.create', self::NO_MEDICAL);
        $reauth = MedicalAccess::reauthId($request);
        $file = $request->file('file');
        if (! $file || ! $file->isValid()) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa el archivo', 'Elige un archivo.', ['errors' => ['file' => ['Elige un archivo.']]]);
        }
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, self::FILE_TYPES, true)) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa el archivo', 'Solo se aceptan PDF o imágenes (JPG, PNG).', ['errors' => ['file' => ['Tipo de archivo no permitido.']]]);
        }
        if ($file->getSize() > 15 * 1024 * 1024) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa el archivo', 'El archivo pesa más de 15 MB.', ['errors' => ['file' => ['Máximo 15 MB.']]]);
        }
        $detail = MedicalAccess::med($reauth, fn ($db) => $db->selectOne('select id from medical_leave_details where absence_ref = ? and deleted_at is null', [$a->id]));
        if (! $detail) {
            throw new ApiProblem(409, 'conflict', 'Falta el detalle médico', 'Guarda primero el diagnóstico del descanso y luego adjunta el certificado.');
        }
        MedicalAccess::audit('medical_file', $detail->id, $a->worker_id, 'create', $reauth, 'Certificado de descanso');
        $payload = json_encode(['name' => mb_substr($file->getClientOriginalName(), 0, 200), 'mime' => $mime, 'data' => base64_encode((string) file_get_contents($file->getRealPath()))]);
        Storage::disk($this->disk())->put($this->certKey($detail->id), MedicalCipher::encrypter()->encryptString($payload));
        MedicalAccess::med($reauth, fn ($db) => MedicalAccess::log($db, 'medical_file', $detail->id, $a->worker_id, 'create', 'Certificado de descanso'));
        DB::update('update absences set certificate_received = true, updated_by = ? where id = ?', [MedicalAccess::uid(), $a->id]);

        return response()->json(['data' => ['id' => $detail->id]], 201);
    }

    public function downloadCertificate(Request $request, string $id): Response
    {
        $a = $this->absence($id);
        MedicalAccess::can('des.medical_leave_details.read_sensitive', self::NO_MEDICAL);
        $reauth = MedicalAccess::reauthId($request);
        $detail = MedicalAccess::med($reauth, fn ($db) => $db->selectOne('select id from medical_leave_details where absence_ref = ? and deleted_at is null', [$a->id]));
        $disk = Storage::disk($this->disk());
        if (! $detail || ! $disk->exists($this->certKey($detail->id))) {
            throw ApiProblem::notFound('El descanso no tiene certificado.');
        }
        MedicalAccess::audit('medical_file', $detail->id, $a->worker_id, 'download', $reauth, 'Certificado de descanso');
        MedicalAccess::med($reauth, fn ($db) => MedicalAccess::log($db, 'medical_file', $detail->id, $a->worker_id, 'download', 'Certificado de descanso'));
        $f = json_decode(MedicalCipher::encrypter()->decryptString((string) $disk->get($this->certKey($detail->id))), true);
        $name = str_replace(['"', "\r", "\n"], '', (string) ($f['name'] ?? 'certificado'));

        return response(base64_decode((string) $f['data']), 200, [
            'Content-Type' => $f['mime'] ?? 'application/octet-stream',
            'Content-Disposition' => "inline; filename=\"{$name}\"",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    // ── apoyo ──

    private function absence(string $id): object
    {
        $a = Str::isUuid($id) ? DB::selectOne('select a.*, w.full_name as worker, w.document_last4 from absences a join workers w on w.id = a.worker_id where a.id = ? and a.deleted_at is null', [$id]) : null;
        if (! $a) {
            throw ApiProblem::notFound('La ausencia no existe.');
        }

        return $a;
    }

    private function absenceOut(object $a): array
    {
        return ['id' => $a->id, 'worker_id' => $a->worker_id, 'worker' => $a->worker, 'document_masked' => $a->document_last4 ? '****'.$a->document_last4 : null,
            'absence_type' => $a->absence_type, 'type_label' => self::TYPES[$a->absence_type] ?? $a->absence_type, 'starts_on' => $a->starts_on, 'ends_on' => $a->ends_on,
            'days' => (int) $a->days, 'status' => $a->status, 'certificate_received' => (bool) $a->certificate_received];
    }

    private function disk(): string
    {
        return (string) config('escudo.medical.files_disk', 'local');
    }

    /** Certificado cifrado en el disco médico (nombre y tipo van dentro del contenido cifrado). */
    private function certKey(string $detailId): string
    {
        return 'medical/'.MedicalAccess::company().'/leave/'.$detailId.'.bin';
    }
}
