<?php

namespace App\Modules\CTR;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\REC\RecordsService;
use App\Modules\REC\Registry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Portal del contratista (M-CTR SCR-CTR-05/06/07, docs/05 §4.4). Todo se acota al contratista de la membresía
 * (membership_scopes 'contractor'); nunca se devuelven trabajadores ni datos de la empresa principal (RN-CTR-04),
 * salvo sus requisitos y los nombres de sus sedes para pedir la acreditación.
 *
 *  GET   /ctr/portal                 su empresa, requisitos con estado, trabajadores con sus requisitos, acreditaciones
 *  PATCH /ctr/portal/company         {trade_name?, contact_name?, contact_phone?, services_description?}
 *  POST  /ctr/portal/documents       {requirement_id, file_id, contractor_worker_id?, issued_on?, expires_on?}
 *  POST  /ctr/portal/workers         {document_type?, document_number, first_name, paternal_last_name, maternal_last_name?, position_text?, phone_e164?}
 *  POST  /ctr/portal/accreditations  {service_description, starts_on, ends_on?, site_id?, contract_reference?}  solicitud de acreditación
 */
class PortalController extends Controller
{
    public function __construct(private RecordsService $records) {}

    public function show(): JsonResponse
    {
        $c = ContractorStatus::requirePortalContractor();
        $docs = ContractorStatus::latestDocuments($c->id);
        $companyReqs = ContractorStatus::requirements($c, 'company');
        $workerReqs = ContractorStatus::requirements($c, 'worker');
        $company = ContractorStatus::checklist($companyReqs, $docs);

        $workers = array_map(function ($w) use ($workerReqs, $docs) {
            $list = ContractorStatus::checklist($workerReqs, $docs, $w->id);

            return [
                'id' => $w->id,
                'name' => trim($w->first_name.' '.$w->paternal_last_name.' '.($w->maternal_last_name ?? '')),
                'document_type' => $w->document_type,
                'document_last4' => $w->document_last4,
                'position_text' => $w->position_text,
                'status' => $w->status,
                'access_enabled' => (bool) $w->access_enabled,
                // RN-CTR-05: de la salud solo si la aptitud está vigente, nunca el diagnóstico ni restricciones.
                'fitness_valid' => in_array($w->emo_fitness, ['fit', 'fit_with_restrictions'], true) && (! $w->emo_valid_until || $w->emo_valid_until >= date('Y-m-d')),
                'requirements' => $list,
                'complete' => ContractorStatus::complete($list),
            ];
        }, DB::select(
            'select id, first_name, paternal_last_name, maternal_last_name, document_type, document_last4, position_text, status, access_enabled, emo_fitness, emo_valid_until
               from contractor_workers where contractor_id = ? and deleted_at is null order by paternal_last_name, first_name', [$c->id]));

        $accreditations = DB::select(
            "select a.id, a.contract_reference, a.service_description, a.starts_on, a.ends_on, a.expires_on, a.status, a.submitted_at, a.approved_at,
                    a.observations, a.rejection_reason, a.suspended_reason, s.name as site_name
               from contractor_accreditations a left join sites s on s.id = a.site_id
              where a.contractor_id = ? and a.deleted_at is null order by a.created_at desc", [$c->id]);
        $principal = DB::selectOne('select coalesce(trade_name, legal_name) as name from companies where id = ?', [app(\App\Core\Tenancy\CompanyContext::class)->companyId])?->name;

        $ok = count(array_filter($company, fn ($i) => in_array($i['state'], ['approved', 'expiring'], true)));

        return response()->json(['data' => [
            'contractor' => $c,
            'principal' => $principal,
            'company_requirements' => $company,
            'worker_requirements' => array_map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'is_mandatory' => (bool) $r->is_mandatory], $workerReqs),
            'workers' => $workers,
            'accreditations' => $accreditations,
            'sites' => DB::select('select id, name from sites where deleted_at is null order by name'),
            'kpis' => [
                'requirements_ok' => $ok,
                'requirements_total' => count($company),
                'observed' => count(array_filter($company, fn ($i) => in_array($i['state'], ['observed', 'rejected', 'expired'], true)))
                    + array_sum(array_map(fn ($w) => count(array_filter($w['requirements'], fn ($i) => in_array($i['state'], ['observed', 'rejected', 'expired'], true))), $workers)),
                'workers' => count($workers),
                'workers_ready' => count(array_filter($workers, fn ($w) => $w['complete'] && $w['status'] === 'active')),
                'accreditation' => $accreditations[0]->status ?? null,
            ],
        ]]);
    }

    public function updateCompany(Request $request): JsonResponse
    {
        $c = ContractorStatus::requirePortalContractor();
        $sets = [];
        $bind = [];
        foreach (['trade_name' => 150, 'contact_name' => 150, 'contact_phone' => 30, 'services_description' => 2000] as $f => $max) {
            if ($request->has($f)) {
                $sets[] = "{$f} = ?";
                $v = trim((string) $request->input($f));
                $bind[] = $v === '' ? null : mb_substr($v, 0, $max);
            }
        }
        if ($sets) {
            DB::update('update contractors set '.implode(', ', $sets).', updated_by = ? where id = ?', [...$bind, (string) auth()->id(), $c->id]);
        }

        return $this->show();
    }

    public function storeDocument(Request $request): JsonResponse
    {
        $c = ContractorStatus::requirePortalContractor();
        $reqId = (string) $request->input('requirement_id');
        $req = Str::isUuid($reqId) ? DB::selectOne('select id, applies_to, name, validity_months, requirement_code, document_kind from contractor_requirements where id = ? and deleted_at is null and is_active', [$reqId]) : null;
        if (! $req) {
            $this->fail('requirement_id', 'Elige el requisito que corresponde al documento.');
        }
        $worker = $request->input('contractor_worker_id');
        if ($req->applies_to === 'worker') {
            if (! Str::isUuid((string) $worker) || ! DB::selectOne('select 1 as ok from contractor_workers where id = ? and contractor_id = ? and deleted_at is null', [$worker, $c->id])) {
                $this->fail('contractor_worker_id', 'Elige al trabajador del documento.');
            }
        } else {
            $worker = null;
        }
        $file = (string) $request->input('file_id');
        if (! Str::isUuid($file) || ! DB::selectOne("select 1 as ok from files where id = ? and uploaded_by = ? and deleted_at is null and status = 'available'", [$file, (string) auth()->id()])) {
            $this->fail('file_id', 'Adjunta el archivo del documento.');
        }
        $expires = $request->input('expires_on') ?: null;
        if ($expires && $expires < date('Y-m-d')) {
            $this->fail('expires_on', 'El documento ya está vencido: carga uno vigente.');
        }
        // RN-CTR-07: un documento en revisión se reemplaza (el anterior queda como historial); uno aprobado vigente también.
        $row = $this->records->create(Registry::resource('CTR', 'contractor_documents'), array_filter([
            'contractor_id' => $c->id,
            'contractor_worker_id' => $worker,
            'requirement_id' => $req->id,
            'document_kind' => $req->document_kind ?: $req->name,
            'file_id' => $file,
            'issued_on' => $request->input('issued_on') ?: null,
            'expires_on' => $expires,
            'status' => 'submitted',
        ], fn ($v) => $v !== null));

        $team = array_column(DB::select(
            "select m.user_id from memberships m join roles r on r.id = m.role_id
              where m.status = 'active' and m.deleted_at is null and r.code in ('sst_manager', 'sst_supervisor')"), 'user_id');
        ContractorStatus::notify($team, 'ctr.document_submitted', $row['id'], 'Documento de contratista por revisar',
            "{$c->legal_name} cargó «{$req->name}».", '/m/ctr');

        return response()->json(['data' => $row], 201);
    }

    public function storeWorker(Request $request): JsonResponse
    {
        $c = ContractorStatus::requirePortalContractor();
        $input = array_intersect_key($request->all(), array_flip(['document_type', 'document_number', 'first_name', 'paternal_last_name', 'maternal_last_name', 'position_text', 'phone_e164']));
        $input['contractor_id'] = $c->id;
        $input['status'] = 'active';

        return response()->json(['data' => $this->records->create(Registry::resource('CTR', 'contractor_workers'), $input)], 201);
    }

    public function storeAccreditation(Request $request): JsonResponse
    {
        $c = ContractorStatus::requirePortalContractor();
        $service = trim((string) $request->input('service_description'));
        if (mb_strlen($service) < 3) {
            $this->fail('service_description', 'Describe el servicio que vas a realizar.');
        }
        if (! $request->filled('starts_on')) {
            $this->fail('starts_on', 'Indica la fecha de inicio.');
        }
        if ($request->filled('ends_on') && $request->input('ends_on') < $request->input('starts_on')) {
            $this->fail('ends_on', 'La fecha de fin debe ser posterior al inicio.');
        }
        $site = $request->input('site_id');
        if ($site && (! Str::isUuid((string) $site) || ! DB::selectOne('select 1 as ok from sites where id = ? and deleted_at is null', [$site]))) {
            $this->fail('site_id', 'Elige una sede válida.');
        }
        if (DB::selectOne("select 1 as ok from contractor_accreditations where contractor_id = ? and deleted_at is null and status in ('submitted', 'under_review')", [$c->id])) {
            throw new ApiProblem(409, 'conflict', 'Solicitud en revisión', 'Ya tienes una solicitud de acreditación en revisión.');
        }
        $row = $this->records->create(Registry::resource('CTR', 'contractor_accreditations'), array_filter([
            'contractor_id' => $c->id,
            'service_description' => $service,
            'contract_reference' => trim((string) $request->input('contract_reference')) ?: null,
            'site_id' => $site ?: null,
            'starts_on' => (string) $request->input('starts_on'),
            'ends_on' => $request->input('ends_on') ?: null,
            'status' => 'submitted',
        ], fn ($v) => $v !== null));

        $team = array_column(DB::select(
            "select m.user_id from memberships m join roles r on r.id = m.role_id
              where m.status = 'active' and m.deleted_at is null and r.code in ('sst_manager', 'sst_supervisor')"), 'user_id');
        ContractorStatus::notify($team, 'ctr.accreditation_submitted', $row['id'], 'Solicitud de acreditación',
            "{$c->legal_name} solicitó acreditación para «{$service}».", '/m/ctr?t=contractor_accreditations&open='.$row['id']);

        return response()->json(['data' => $row], 201);
    }

    private function fail(string $field, string $message): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $message, ['errors' => [$field => [$message]]]);
    }
}
