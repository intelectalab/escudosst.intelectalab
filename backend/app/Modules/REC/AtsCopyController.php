<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * M-ATS SCR-ATS-02 «Nuevo ATS»: copiar desde un PETS o un ATS anterior, y pasos con peligros y controles sugeridos del IPERC
 * vigente (RN-ATS-08).
 *  GET  /ats/forms/{id}/sources   PETS vigentes con pasos, ATS anteriores (del área si la tiene) y filas del IPERC vigente del área
 *  POST /ats/forms/{id}/copy      {source: pets|ats|iperc, source_id?, iperc_row_ids?}: agrega los pasos al ATS en borrador con el
 *                                 servicio de registros (sus reglas: el ATS firmado no cambia sus pasos, RN-ATS-04)
 */
class AtsCopyController extends Controller
{
    public function sources(string $id): JsonResponse
    {
        $this->can('ats.ats_forms.read');
        $f = $this->form($id);
        $pets = DB::select(
            "select p.id, p.code, p.title, v.version_number, jsonb_array_length(coalesce(v.steps, '[]'::jsonb)) as steps
               from procedures p join procedure_versions v on v.id = p.current_version_id and v.deleted_at is null
              where p.deleted_at is null and p.status <> 'obsolete' and jsonb_array_length(coalesce(v.steps, '[]'::jsonb)) > 0
              order by (p.area_id is not distinct from ?::uuid) desc, p.code limit 100", [$f->area_id]);
        $ats = DB::select(
            "select a.id, a.code, a.task, a.performed_on, (select count(*) from ats_steps s where s.ats_form_id = a.id and s.deleted_at is null) as steps
               from ats_forms a where a.deleted_at is null and a.id <> ? and a.status in ('signed', 'in_execution', 'closed')
                and exists (select 1 from ats_steps s where s.ats_form_id = a.id and s.deleted_at is null)
              order by (a.area_id is not distinct from ?::uuid) desc, a.performed_on desc nulls last limit 50", [$f->id, $f->area_id]);
        $rows = DB::select(
            "select r.id, r.task, r.activity, r.hazard, r.risk, r.risk_level, m.code as matrix,
                    (select string_agg(c.description, '; ' order by c.is_existing desc) from iperc_controls c where c.iperc_row_id = r.id and c.deleted_at is null) as controls
               from iperc_rows r join iperc_versions v on v.id = r.iperc_version_id and v.status = 'current' and v.deleted_at is null
               join iperc_matrices m on m.id = v.iperc_matrix_id and m.current_version_id = v.id and m.deleted_at is null
               left join positions ps on ps.id = coalesce(r.position_id, m.position_id)
              where r.deleted_at is null and (?::uuid is null or m.area_id = ?::uuid or ps.area_id = ?::uuid)
              order by m.code, r.row_number limit 200", [$f->area_id, $f->area_id, $f->area_id]);

        return response()->json(['data' => ['form' => ['id' => $f->id, 'code' => $f->code, 'task' => $f->task, 'status' => $f->status], 'pets' => $pets, 'ats' => $ats, 'iperc' => $rows]]);
    }

    public function copy(Request $request, string $id, RecordsService $records): JsonResponse
    {
        $this->can('ats.ats_steps.create');
        $f = $this->form($id);
        if ($f->status !== 'draft') {
            throw new ApiProblem(409, 'conflict', 'No se modifica', 'Solo se copian pasos a un ATS en borrador.');
        }
        $d = $request->validate([
            'source' => ['required', 'in:pets,ats,iperc'],
            'source_id' => ['nullable', 'uuid', 'required_unless:source,iperc'],
            'iperc_row_ids' => ['array', 'required_if:source,iperc', 'max:100'],
            'iperc_row_ids.*' => ['uuid'],
        ], ['source_id.required_unless' => 'Elige de dónde copiar.', 'iperc_row_ids.required_if' => 'Elige al menos una fila del IPERC.']);
        $steps = [];
        $formPatch = [];
        if ($d['source'] === 'pets') {
            $v = DB::selectOne('select p.id as procedure_id, v.steps, v.required_ppe from procedures p join procedure_versions v on v.id = p.current_version_id where p.id = ? and p.deleted_at is null', [$d['source_id']])
                ?? throw ApiProblem::notFound('El PETS no existe o no tiene versión vigente.');
            foreach (json_decode((string) $v->steps, true) ?: [] as $s) {
                $steps[] = ['description' => $s['step'] ?? '', 'hazards' => $s['risk'] ?? null, 'risks' => $s['risk'] ?? null, 'controls' => $s['control'] ?? null];
            }
            $formPatch['procedure_id'] = $v->procedure_id;
            if (empty(json_decode((string) $f->required_ppe, true)) && $v->required_ppe) {
                $formPatch['required_ppe'] = json_decode((string) $v->required_ppe, true);
            }
        } elseif ($d['source'] === 'ats') {
            $src = DB::selectOne('select id, required_ppe, tools_equipment from ats_forms where id = ? and deleted_at is null', [$d['source_id']]) ?? throw ApiProblem::notFound('El ATS no existe.');
            foreach (DB::select('select description, hazards, risks, controls, responsible from ats_steps where ats_form_id = ? and deleted_at is null order by step_number', [$src->id]) as $s) {
                $steps[] = (array) $s;
            }
            if (empty(json_decode((string) $f->required_ppe, true)) && $src->required_ppe) {
                $formPatch['required_ppe'] = json_decode((string) $src->required_ppe, true);
            }
            if (! $f->tools_equipment && $src->tools_equipment) {
                $formPatch['tools_equipment'] = $src->tools_equipment;
            }
        } else {
            foreach (DB::select(
                "select r.task, r.activity, r.hazard, r.risk,
                        (select string_agg(c.description, '; ' order by c.is_existing desc) from iperc_controls c where c.iperc_row_id = r.id and c.deleted_at is null) as controls
                   from iperc_rows r join iperc_versions v on v.id = r.iperc_version_id and v.status = 'current'
                  where r.id = any(?::uuid[]) and r.deleted_at is null order by r.row_number", ['{'.implode(',', $d['iperc_row_ids']).'}']) as $r) {
                $steps[] = ['description' => $r->task ?: $r->activity, 'hazards' => $r->hazard, 'risks' => $r->risk, 'controls' => $r->controls];
            }
        }
        $steps = array_values(array_filter($steps, fn ($s) => trim((string) $s['description']) !== ''));
        if (! $steps) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'El origen elegido no tiene pasos para copiar.', ['errors' => ['source_id' => ['El origen elegido no tiene pasos para copiar.']]]);
        }
        $resStep = Registry::resource('ATS', 'ats_steps');
        $resForm = Registry::resource('ATS', 'ats_forms');
        DB::transaction(function () use ($records, $resStep, $resForm, $f, $steps, $formPatch) {
            $n = (int) DB::selectOne('select coalesce(max(step_number), 0) as n from ats_steps where ats_form_id = ? and deleted_at is null', [$f->id])->n;
            foreach ($steps as $s) {
                $records->create($resStep, array_filter(['ats_form_id' => $f->id, 'step_number' => ++$n, 'description' => mb_substr((string) $s['description'], 0, 1000),
                    'hazards' => $s['hazards'] ?? null, 'risks' => $s['risks'] ?? null, 'controls' => $s['controls'] ?? null, 'responsible' => $s['responsible'] ?? null], fn ($x) => $x !== null && $x !== ''));
            }
            if ($formPatch) {
                $records->update($resForm, $f->id, $formPatch);
            }
        });

        return response()->json(['data' => ['added' => count($steps)]]);
    }

    private function form(string $id): object
    {
        return (Str::isUuid($id) ? DB::selectOne('select * from ats_forms where id = ? and deleted_at is null', [$id]) : null) ?? throw ApiProblem::notFound('El ATS no existe.');
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede preparar ATS.');
        }
    }
}
