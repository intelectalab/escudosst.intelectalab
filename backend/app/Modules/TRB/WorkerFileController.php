<?php

namespace App\Modules\TRB;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GET /trb/workers/{id}/legajo — M-TRB §11 «Legajo SST por trabajador en PDF con índice»: datos y asignaciones, capacitaciones,
 * EPP, aptitud médica (solo estado y restricción laboral, RN-EMO-02), fotocheck, SCTR, RISST y documentos firmados, charlas y
 * documentos del legajo. El trabajador solo ve el suyo.
 */
class WorkerFileController extends Controller
{
    public function legajo(string $id): JsonResponse
    {
        if (! in_array('trb.workers.read', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no ve legajos.');
        }
        $w = Str::isUuid($id) ? DB::selectOne(
            "select w.*, p.name as position, a.name as area, s.name as site from workers w left join positions p on p.id = w.current_position_id
               left join areas a on a.id = w.current_area_id left join sites s on s.id = w.current_site_id where w.id = ? and w.deleted_at is null", [$id]) : null;
        if (! $w) {
            throw ApiProblem::notFound('El trabajador no existe o es de otra empresa.');
        }
        $ctx = app(CompanyContext::class);
        if ($ctx->roleCode === 'worker' && DB::selectOne('select worker_id from memberships where id = ?', [$ctx->membershipId])?->worker_id !== $id) {
            throw ApiProblem::notFound('El trabajador no existe o es de otra empresa.');
        }
        $co = DB::selectOne('select legal_name, trade_name, ruc from companies where id = ?', [$ctx->companyId]);
        $q = fn (string $sql) => DB::select($sql, [$id]);

        return response()->json(['data' => [
            'company' => ['name' => $co->trade_name ?: $co->legal_name, 'legal_name' => $co->legal_name, 'ruc' => trim((string) $co->ruc)],
            'worker' => [
                'full_name' => $w->full_name, 'document' => $w->document_type.' ****'.$w->document_last4, 'employee_code' => $w->employee_code,
                'position' => $w->position, 'area' => $w->area, 'site' => $w->site, 'hire_on' => $w->hire_on, 'termination_on' => $w->termination_on,
                'employment_type' => $w->employment_type, 'status' => $w->status,
            ],
            'assignments' => $q('select x.starts_on, x.ends_on, x.change_reason, p.name as position, a.name as area, s.name as site from worker_assignments x
                                  left join positions p on p.id = x.position_id left join areas a on a.id = x.area_id left join sites s on s.id = x.site_id
                                 where x.worker_id = ? and x.deleted_at is null order by x.starts_on desc'),
            'trainings' => $q("select c.course_title, c.certificate_code, c.issued_on, c.expires_on, c.hours, c.status from training_certificates c
                                where c.worker_id = ? and c.deleted_at is null and c.status <> 'voided' order by c.issued_on desc"),
            'ppe' => $q('select i.name, d.quantity, d.size, d.delivered_at, d.expires_on, d.status, d.signature_id is not null as signed from ppe_deliveries d
                          join ppe_items i on i.id = d.ppe_item_id where d.worker_id = ? and d.deleted_at is null order by d.delivered_at desc'),
            'fitness' => DB::selectOne('select status, valid_from, valid_until, work_restrictions from fitness_status where worker_id = ? and is_current and deleted_at is null', [$id]),
            'credentials' => $q('select c.credential_number, t.name as type, c.status, c.valid_from, c.expires_on from credentials c left join credential_types t on t.id = c.credential_type_id
                                  where c.worker_id = ? and c.deleted_at is null order by c.valid_from desc'),
            'sctr' => $q("select v.covered_from, v.covered_until, v.constancy_number, p.insurer_name, p.coverage_kind from sctr_coverages v
                           join sctr_policies p on p.id = v.sctr_policy_id where v.worker_id = ? and v.deleted_at is null order by v.covered_until desc"),
            'risst' => $q("select v.title, v.version_number, r.delivered_on, r.method, r.status from risst_deliveries r join risst_versions v on v.id = r.risst_version_id
                            where r.worker_id = ? and r.deleted_at is null and r.status <> 'voided' order by v.version_number desc"),
            'acknowledgements' => $q('select d.code, d.title, v.version_number, a.acknowledged_at, a.method from document_acknowledgements a
                                       join document_versions v on v.id = a.document_version_id join documents d on d.id = v.document_id
                                      where a.worker_id = ? order by a.acknowledged_at desc'),
            'talks' => $q("select t.topic, t.talk_on, a.signature_id is not null as signed from talk_attendances a join safety_talks t on t.id = a.safety_talk_id
                            where a.worker_id = ? and a.deleted_at is null and t.deleted_at is null order by t.talk_on desc limit 60"),
            'documents' => $q('select document_kind, title, issued_on, expires_on, status from worker_documents where worker_id = ? and deleted_at is null order by issued_on desc nulls last'),
            'generated_at' => now('America/Lima')->toIso8601String(),
        ]]);
    }
}
