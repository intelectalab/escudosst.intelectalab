<?php

namespace App\Modules\SUN;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * RN-SUN-07 (M-SUN §4, médico ocupacional con sun.dossier_items.approve): la constancia de aptitud de cada trabajador
 * inspeccionado (ítem W3) entra al expediente solo si la aprueba el médico ocupacional. Nunca se muestran informes clínicos
 * ni diagnósticos: solo la aptitud y su vigencia.
 *  GET  /sun/medical-approvals               constancias por aprobar
 *  POST /sun/dossier-items/{id}/approve      la incluye en el expediente
 *  POST /sun/dossier-items/{id}/reject {reason}  no la incluye (queda excluida con el motivo, visible en el índice)
 */
class MedicalApprovalController extends Controller
{
    private const FITNESS = ['fit' => 'Apto', 'fit_with_restrictions' => 'Apto con restricciones'];

    public function index(): JsonResponse
    {
        $rows = DB::select(
            "select i.id, i.dossier_id, d.title as dossier_title, d.inspection_order_number, d.appearance_at, i.worker_id, w.full_name as worker_name,
                    f.status as fitness, f.valid_until, i.created_at
               from dossier_items i join inspection_dossiers d on d.id = i.dossier_id and d.deleted_at is null
               join workers w on w.id = i.worker_id
               left join fitness_status f on f.id = i.source_id
              where i.deleted_at is null and i.is_medical_certificate and i.status = 'pending_approval'
              order by d.appearance_at nulls last, i.created_at");

        return response()->json(['data' => array_map(fn ($r) => [
            'id' => $r->id, 'dossier_id' => $r->dossier_id, 'dossier_title' => $r->dossier_title, 'inspection_order_number' => $r->inspection_order_number,
            'appearance_at' => $r->appearance_at, 'worker_id' => $r->worker_id, 'worker_name' => $r->worker_name,
            'fitness' => $r->fitness, 'fitness_label' => self::FITNESS[$r->fitness] ?? null, 'valid_until' => $r->valid_until,
        ], $rows)]);
    }

    public function approve(string $id): JsonResponse
    {
        $it = $this->item($id);
        $fit = DB::selectOne("select id from fitness_status where id = ? and is_current and deleted_at is null and status in ('fit', 'fit_with_restrictions')
                                and (valid_until is null or valid_until >= current_date)", [$it->source_id]);
        if (! $fit) {
            throw new ApiProblem(409, 'conflict', 'La aptitud cambió', 'Esta aptitud ya no está vigente: vuelve a armar el expediente para revisar la actual.');
        }
        $me = (string) auth()->id();
        DB::update("update dossier_items set status = 'included', gap_description = null, approved_by = ?, approved_at = now(), updated_by = ? where id = ?", [$me, $me, $id]);
        DossierBuilder::refreshGaps($it->dossier_id);
        $this->audit($it, ['medical_certificate_approved' => true]);

        return response()->json(['data' => DB::selectOne('select id, status, approved_by, approved_at from dossier_items where id = ?', [$id])]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $it = $this->item($id);
        $v = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:400']], ['reason.required' => 'Escribe por qué no se incluye.', 'reason.min' => 'Escribe por qué no se incluye.']);
        $me = (string) auth()->id();
        DB::update("update dossier_items set status = 'excluded', exclusion_reason = ?, approved_by = null, approved_at = null, updated_by = ? where id = ?",
            ['No aprobada por el médico ocupacional: '.trim($v['reason']), $me, $id]);
        DossierBuilder::refreshGaps($it->dossier_id);
        $this->audit($it, ['medical_certificate_approved' => false, 'reason' => trim($v['reason'])]);

        return response()->json(['data' => DB::selectOne('select id, status, exclusion_reason from dossier_items where id = ?', [$id])]);
    }

    private function item(string $id): object
    {
        $it = Str::isUuid($id) ? DB::selectOne('select * from dossier_items where id = ? and deleted_at is null for update', [$id]) : null;
        if (! $it || ! $it->is_medical_certificate) {
            throw ApiProblem::notFound('La constancia no existe en ningún expediente de la empresa.');
        }
        if ($it->status !== 'pending_approval') {
            throw new ApiProblem(409, 'conflict', 'Ya revisada', 'Esta constancia ya fue revisada.');
        }

        return $it;
    }

    private function audit(object $it, array $changes): void
    {
        $ctx = app(CompanyContext::class);
        DB::insert("insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes) values (?, ?, ?, 'user', 'approve', 'SUN', 'dossier_items', ?, ?::jsonb)",
            [$ctx->companyId, (string) auth()->id(), $ctx->roleCode, $it->id, json_encode($changes + ['dossier_id' => $it->dossier_id, 'worker_id' => $it->worker_id], JSON_UNESCAPED_UNICODE)]);
    }
}
