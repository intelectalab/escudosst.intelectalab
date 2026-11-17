<?php

namespace App\Modules\SUN;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Armado del expediente (M-SUN SCR-SUN-04):
 *  PATCH /sun/dossiers/{dossier}/items/{id} {excluded: bool, reason?}   excluir con motivo (o volver a incluir); se mantiene al rearmar
 *  PUT   /sun/dossiers/{dossier}/order {item_ids: []}                   reordenar los ítems (el orden manual se mantiene al rearmar)
 */
class DossierItemsController extends Controller
{
    /** GET /sun/dossiers/{dossier}/items — ítems del expediente armado, en su orden, con su estado y motivo de exclusión. */
    public function index(string $dossier): JsonResponse
    {
        $rows = DB::select(
            "select id, section_code as code, title, status, coalesce(exclusion_reason, gap_description) as gap, exclusion_reason, source_module as module, source_type as \"table\", source_id,
                    snapshot_hash, frozen_at
               from dossier_items where dossier_id = ? and deleted_at is null order by sort_order", [$dossier]);
        // RN-SUN-05: el expediente conserva la copia congelada; se avisa si el origen cambió después de armarlo.
        foreach ($rows as $r) {
            $r->source_changed = $r->snapshot_hash !== null && DossierBuilder::snapshot($r->table, $r->source_id)[1] !== $r->snapshot_hash;
        }

        return response()->json(['data' => $rows]);
    }

    public function update(Request $request, string $dossier, string $id): JsonResponse
    {
        $this->can();
        $it = Str::isUuid($id) ? DB::selectOne('select * from dossier_items where id = ? and dossier_id = ? and deleted_at is null', [$id, $dossier]) : null;
        if (! $it) {
            throw ApiProblem::notFound('El ítem no existe en este expediente.');
        }
        $d = $request->validate(['excluded' => ['required', 'boolean'], 'reason' => ['required_if:excluded,true', 'nullable', 'string', 'min:5', 'max:500']],
            ['reason.required_if' => 'Escribe el motivo para excluir el ítem.', 'reason.min' => 'Escribe el motivo para excluir el ítem.']);
        if ($d['excluded']) {
            DB::update("update dossier_items set status = 'excluded', exclusion_reason = ?, updated_by = ?, updated_at = now() where id = ?", [trim($d['reason']), (string) auth()->id(), $id]);
        } else {
            // Al volver a incluirlo, su estado real se recalcula al rearmar el expediente.
            DB::update("update dossier_items set status = case when source_id is null then 'missing' else 'included' end, exclusion_reason = null, updated_by = ?, updated_at = now() where id = ?",
                [(string) auth()->id(), $id]);
        }
        $this->audit($dossier, ['item' => $it->section_code, 'excluded' => $d['excluded'], 'reason' => $d['reason'] ?? null]);

        return response()->json(['data' => DB::selectOne('select id, status, exclusion_reason from dossier_items where id = ?', [$id])]);
    }

    /** RN-SUN-01 / SCR-SUN-04: evidencia manual (un archivo que la empresa sube) agregada al expediente; se conserva al rearmar. */
    public function evidence(Request $request, string $dossier): JsonResponse
    {
        $this->can();
        $d = Str::isUuid($dossier) ? DB::selectOne('select id, company_id from inspection_dossiers where id = ? and deleted_at is null', [$dossier]) : null;
        if (! $d) {
            throw ApiProblem::notFound('El expediente no existe.');
        }
        $v = $request->validate(['title' => ['required', 'string', 'min:3', 'max:300'], 'file_id' => ['required', 'uuid'], 'note' => ['nullable', 'string', 'max:500']],
            ['title.required' => 'Escribe qué evidencia es.', 'file_id.required' => 'Adjunta el archivo.']);
        $f = DB::selectOne('select id, is_medical from files where id = ? and deleted_at is null', [$v['file_id']]);
        if (! $f) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'El archivo no existe.', ['errors' => ['file_id' => ['El archivo no existe.']]]);
        }
        if ($f->is_medical) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Los archivos médicos no van en el expediente.', ['errors' => ['file_id' => ['Archivo médico.']]]);
        }
        $n = (int) DB::selectOne("select count(*) as n from dossier_items where dossier_id = ? and source_type = 'manual'", [$dossier])->n + 1;
        $uid = (string) auth()->id();
        $id = DB::selectOne("insert into dossier_items (company_id, dossier_id, section_code, title, source_module, source_type, file_id, status, gap_description, sort_order, created_by, updated_by)
                             values (?, ?, ?, ?, 'SUN', 'manual', ?, 'included', ?, (select coalesce(max(sort_order), 0) + 1 from dossier_items where dossier_id = ? and deleted_at is null), ?, ?) returning id",
            [$d->company_id, $dossier, 'M'.$n, trim($v['title']), $v['file_id'], $v['note'] ?? null, $dossier, $uid, $uid])->id;
        $this->audit($dossier, ['manual_evidence' => $id, 'title' => trim($v['title'])]);

        return response()->json(['data' => ['id' => $id, 'code' => 'M'.$n]], 201);
    }

    public function order(Request $request, string $dossier): JsonResponse
    {
        $this->can();
        $d = $request->validate(['item_ids' => ['required', 'array', 'min:1'], 'item_ids.*' => ['uuid']]);
        $ids = array_column(DB::select('select id from dossier_items where dossier_id = ? and deleted_at is null', [$dossier]), 'id');
        if (array_diff($d['item_ids'], $ids) || count(array_unique($d['item_ids'])) !== count($ids)) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Envía todos los ítems del expediente en el nuevo orden.', ['errors' => ['item_ids' => ['Orden incompleto.']]]);
        }
        foreach (array_values($d['item_ids']) as $k => $iid) {
            DB::update('update dossier_items set sort_order = ?, manual_sort = true, updated_at = now() where id = ?', [$k + 1, $iid]);
        }
        $this->audit($dossier, ['reordered' => count($ids)]);

        return response()->json(['data' => ['ordered' => count($ids)]]);
    }

    private function audit(string $dossier, array $changes): void
    {
        $ctx = app(\App\Core\Tenancy\CompanyContext::class);
        DB::insert("insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes) values (?, ?, ?, 'user', 'update', 'SUN', 'inspection_dossiers', ?, ?::jsonb)",
            [$ctx->companyId, (string) auth()->id(), $ctx->roleCode, $dossier, json_encode($changes, JSON_UNESCAPED_UNICODE)]);
    }

    private function can(): void
    {
        if (! in_array('sun.inspection_dossiers.update', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no arma expedientes.');
        }
    }
}
