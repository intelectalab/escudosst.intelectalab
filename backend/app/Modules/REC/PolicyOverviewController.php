<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Política SST (M-POL):
 *  GET /pol/overview            SCR-POL-01: versión vigente con firma y fechas, % de trabajadores que firmaron el cargo, «revisión
 *                               pendiente» (RN-POL-08), versiones en trámite con sus principios marcados y la lista de principios
 *                               del art. 23 (parámetro pol_policy_principles, RN-POL-01)
 *  GET /public/verify/policy/{company}/{id}  verificación pública del afiche (QR): empresa, título, versión, estado y fechas; sin el texto
 */
class PolicyOverviewController extends Controller
{
    /**
     * GET/PUT /pol/settings {objective_amber_pct} — RN-POL-07: umbral ámbar de las metas de la empresa (% de la meta desde el
     * que la meta va «en camino»); el cambio queda con vigencia e historial en los parámetros de la empresa.
     */
    public function settings(\Illuminate\Http\Request $request): JsonResponse
    {
        if ($request->isMethod('put')) {
            if (! in_array('pol.sst_objectives.update', Permissions::effective(), true)) {
                throw ApiProblem::forbidden('Tu rol no cambia el umbral de las metas.');
            }
            $d = $request->validate(['objective_amber_pct' => ['required', 'integer', 'min:1', 'max:99']]);
            \App\Core\Support\LegalParams::setForCompany('objective_amber_pct', (string) $d['objective_amber_pct']);
            DB::transaction(fn () => \App\Modules\REC\Rules\DocsFieldRules::refreshKpiTargets());
        } elseif (! in_array('pol.sst_policies.read', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no ve la política SST.');
        }

        return response()->json(['data' => ['objective_amber_pct' => (int) \App\Core\Support\LegalParams::number('objective_amber_pct', 70)]]);
    }

    public function overview(): JsonResponse
    {
        if (! in_array('pol.sst_policies.read', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no ve la política SST.');
        }
        $rows = DB::select(
            "select p.id, p.version_number, p.title, p.status, p.effective_on, p.review_due_on, p.approved_at, p.principles_checklist, p.document_version_id, u.full_name as approved_by
               from sst_policies p left join users u on u.id = p.approved_by where p.deleted_at is null order by p.version_number desc");
        foreach ($rows as $r) {
            $v = $r->principles_checklist;
            for ($i = 0; $i < 2 && is_string($v); $i++) {
                $v = json_decode($v, true);
            }
            $r->principles_checklist = is_array($v) ? $v : [];
        }
        $cur = collect($rows)->first(fn ($r) => in_array($r->status, ['current', 'review_required'], true));
        $ack = null;
        if ($cur) {
            $active = (int) DB::selectOne("select count(*) as n from workers where deleted_at is null and status = 'active'")->n;
            $signed = $cur->document_version_id ? (int) DB::selectOne(
                "select count(distinct k.worker_id) as n from document_acknowledgements k join workers w on w.id = k.worker_id and w.status = 'active' and w.deleted_at is null
                  where k.document_version_id = ?", [$cur->document_version_id])->n : 0;
            $ack = ['active' => $active, 'signed' => $signed, 'pct' => $active ? (int) round($signed * 100 / $active) : null];
            $cur->review_pending = $cur->status === 'review_required' || ($cur->review_due_on && $cur->review_due_on < now('America/Lima')->format('Y-m-d'));
        }

        return response()->json(['data' => [
            'current' => $cur,
            'acknowledgements' => $ack,
            'drafts' => array_values(array_filter($rows, fn ($r) => in_array($r->status, ['draft', 'in_review', 'approved'], true))),
            'principles' => LegalParams::list('pol_policy_principles'),
            'amber_pct' => (int) LegalParams::number('objective_amber_pct', 70),
            'can_approve' => in_array('pol.sst_policies.approve', Permissions::effective(), true),
        ]]);
    }

    public function public(string $company, string $id): JsonResponse
    {
        if (! Str::isUuid($company) || ! Str::isUuid($id)) {
            return response()->json(['data' => ['result' => 'invalid']]);
        }

        return DB::transaction(function () use ($company, $id) {
            \App\Core\Tenancy\DbContext::setCompany($company);
            $p = DB::selectOne(
                "select p.title, p.version_number, p.status, p.effective_on, p.approved_at, c.legal_name, c.ruc
                   from sst_policies p join companies c on c.id = p.company_id where p.id = ? and p.deleted_at is null", [$id]);
            if (! $p) {
                return response()->json(['data' => ['result' => 'invalid']]);
            }

            return response()->json(['data' => ['company' => $p->legal_name, 'ruc' => trim((string) $p->ruc), 'title' => $p->title, 'version_number' => (int) $p->version_number,
                'status' => $p->status, 'effective_on' => $p->effective_on, 'approved_at' => $p->approved_at,
                'result' => in_array($p->status, ['current', 'review_required'], true) ? 'valid' : 'not_current']]);
        });
    }
}
