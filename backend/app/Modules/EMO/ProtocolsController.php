<?php

namespace App\Modules\EMO;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * M-EMO SCR-EMO-06 «Protocolos por puesto» (sin datos clínicos):
 *  GET   /emo/protocols              protocolos (código, nombre, exámenes, exposición) y puestos con su protocolo, periodicidad
 *                                    (parámetro emo_periodic_months o emo_periodic_months_high_risk, RN-EMO-05), exposiciones del
 *                                    IPERC vigente del puesto y estado de aptitud de sus trabajadores (vigente, por vencer, vencida, sin EMO)
 *  POST  /emo/protocols              crear o editar un protocolo {id?, code, name, exams[], exposures?, notes?}
 *  PATCH /emo/positions/{id}         asignar al puesto {requires_emo, emo_protocol_code, emo_periodicity_param}
 * Las órdenes periódicas se generan solas cada día para los puestos que exigen EMO (SstAlerts) con el protocolo del puesto.
 */
class ProtocolsController extends Controller
{
    private const PERIODICITY = ['emo_periodic_months', 'emo_periodic_months_high_risk'];

    public function index(): JsonResponse
    {
        $this->can('emo.medical_exam_orders.read');
        $notice = (int) LegalParams::number('emo_due_notice_days', 30);
        $protocols = DB::select('select id, code, name, exams, exposures, notes from emo_protocols where deleted_at is null order by code');
        foreach ($protocols as $p) {
            $p->exams = json_decode((string) $p->exams, true) ?: [];
        }
        $positions = DB::select(
            "select p.id, p.code, p.name, p.requires_emo, p.emo_protocol_code, p.emo_periodicity_param, a.name as area,
                    count(w.id) filter (where w.id is not null) as workers,
                    count(w.id) filter (where f.status in ('fit', 'fit_with_restrictions') and f.valid_until > current_date + ?::int) as valid,
                    count(w.id) filter (where f.status in ('fit', 'fit_with_restrictions') and f.valid_until between current_date and current_date + ?::int) as due_soon,
                    count(w.id) filter (where w.id is not null and (f.id is null or f.status in ('expired', 'pending') or f.valid_until < current_date)) as missing,
                    count(w.id) filter (where f.status = 'unfit') as unfit
               from positions p left join areas a on a.id = p.area_id
               left join workers w on w.current_position_id = p.id and w.status = 'active' and w.deleted_at is null
               left join fitness_status f on f.worker_id = w.id and f.is_current and f.deleted_at is null
              where p.deleted_at is null and coalesce(p.status, 'active') = 'active'
              group by p.id, a.name order by p.name", [$notice, $notice]);
        $months = [];
        foreach (self::PERIODICITY as $k) {
            $months[$k] = (int) LegalParams::number($k, $k === 'emo_periodic_months' ? 24 : 12);
        }
        foreach ($positions as $p) {
            foreach (['workers', 'valid', 'due_soon', 'missing', 'unfit'] as $k) {
                $p->{$k} = (int) $p->{$k};
            }
            $p->periodicity_months = $months[$p->emo_periodicity_param ?: 'emo_periodic_months'] ?? null;
            // Exposiciones: peligros de las filas del IPERC vigente que aplican al puesto (insumo para armar el protocolo).
            $p->exposures = array_column(DB::select(
                "select distinct r.hazard from iperc_rows r join iperc_versions v on v.id = r.iperc_version_id and v.status = 'current' and v.deleted_at is null
                   join iperc_matrices m on m.id = v.iperc_matrix_id and m.current_version_id = v.id and m.deleted_at is null
                  where r.deleted_at is null and coalesce(r.position_id, m.position_id) = ? order by r.hazard limit 15", [$p->id]), 'hazard');
        }

        return response()->json(['data' => ['protocols' => $protocols, 'positions' => $positions, 'periodicity' => $months, 'notice_days' => $notice]]);
    }

    public function save(Request $request): JsonResponse
    {
        $this->can('emo.medical_exam_orders.create');
        $d = $request->validate([
            'id' => ['nullable', 'uuid'],
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:200'],
            'exams' => ['required', 'array', 'min:1', 'max:60'],
            'exams.*' => ['nullable', 'string', 'max:200'],
            'exposures' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['code.required' => 'Escribe el código del protocolo.', 'name.required' => 'Escribe el nombre del protocolo.', 'exams.required' => 'Agrega al menos un examen.',
            'exams.min' => 'Agrega al menos un examen.']);
        $exams = array_values(array_filter(array_map(fn ($x) => trim((string) $x), $d['exams'])));
        if (! $exams) {
            $this->fail('exams', 'Agrega al menos un examen.');
        }
        $dup = DB::selectOne('select id from emo_protocols where lower(code) = lower(?) and deleted_at is null'.(! empty($d['id']) ? ' and id <> ?' : ''),
            ! empty($d['id']) ? [$d['code'], $d['id']] : [$d['code']]);
        if ($dup) {
            $this->fail('code', 'Ya existe un protocolo con ese código.');
        }
        $uid = (string) auth()->id();
        if (! empty($d['id'])) {
            $old = DB::selectOne('select code from emo_protocols where id = ? and deleted_at is null', [$d['id']]) ?? throw ApiProblem::notFound('El protocolo no existe.');
            DB::update('update emo_protocols set code = ?, name = ?, exams = ?::jsonb, exposures = ?, notes = ?, updated_by = ?, updated_at = now() where id = ?',
                [$d['code'], $d['name'], json_encode($exams, JSON_UNESCAPED_UNICODE), $d['exposures'] ?? null, $d['notes'] ?? null, $uid, $d['id']]);
            if (strcasecmp($old->code, $d['code']) !== 0) {
                DB::update('update positions set emo_protocol_code = ?, updated_at = now() where lower(emo_protocol_code) = lower(?)', [$d['code'], $old->code]);
            }
            $id = $d['id'];
        } else {
            $id = DB::selectOne('insert into emo_protocols (company_id, code, name, exams, exposures, notes, created_by, updated_by) values (?, ?, ?, ?::jsonb, ?, ?, ?, ?) returning id',
                [app(CompanyContext::class)->companyId, $d['code'], $d['name'], json_encode($exams, JSON_UNESCAPED_UNICODE), $d['exposures'] ?? null, $d['notes'] ?? null, $uid, $uid])->id;
        }
        DB::insert("insert into audit_logs (company_id, actor_user_id, action, module_code, entity_type, entity_id, changes) values (?, ?, ?, 'EMO', 'emo_protocols', ?, ?::jsonb)",
            [app(CompanyContext::class)->companyId, $uid, empty($d['id']) ? 'create' : 'update', $id, json_encode(['code' => $d['code'], 'exams' => count($exams)])]);

        return response()->json(['data' => ['id' => $id]], empty($d['id']) ? 201 : 200);
    }

    public function position(Request $request, string $id): JsonResponse
    {
        $this->can('emo.medical_exam_orders.create');
        if (! Str::isUuid($id) || ! DB::selectOne('select 1 as ok from positions where id = ? and deleted_at is null', [$id])) {
            throw ApiProblem::notFound('El puesto no existe.');
        }
        $d = $request->validate([
            'requires_emo' => ['required', 'boolean'],
            'emo_protocol_code' => ['nullable', 'string', 'max:40'],
            'emo_periodicity_param' => ['nullable', 'in:'.implode(',', self::PERIODICITY)],
        ]);
        if (! empty($d['emo_protocol_code']) && ! DB::selectOne('select 1 as ok from emo_protocols where lower(code) = lower(?) and deleted_at is null', [$d['emo_protocol_code']])) {
            $this->fail('emo_protocol_code', 'Ese protocolo no existe.');
        }
        if ($d['requires_emo'] && empty($d['emo_protocol_code'])) {
            $this->fail('emo_protocol_code', 'Elige el protocolo del puesto.');
        }
        DB::update('update positions set requires_emo = ?, emo_protocol_code = ?, emo_periodicity_param = ?, updated_by = ?, updated_at = now() where id = ?',
            [$d['requires_emo'], $d['emo_protocol_code'] ?: null, $d['emo_periodicity_param'] ?? 'emo_periodic_months', (string) auth()->id(), $id]);

        return response()->json(['data' => ['id' => $id]]);
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no gestiona los protocolos de exámenes médicos.');
        }
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}
