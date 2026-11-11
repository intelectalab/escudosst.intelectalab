<?php

namespace App\Modules\CTR;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\REC\RecordsService;
use App\Modules\REC\Registry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SCTR desde el portal del contratista (M-CTR RN-CTR-08 → M-SCT): el administrador del contratista registra sus pólizas
 * de salud y pensión y la constancia de cobertura de cada trabajador por periodo (RN-SCT-06: los trabajadores
 * contratistas se controlan con las pólizas del contratista). La póliza queda acotada a su contratista por RLS
 * (rls_sctr_policies_contractor); la cobertura se valida aquí contra su póliza y sus trabajadores.
 *
 *  GET  /ctr/portal/sctr             pólizas con semáforo y cobertura de cada trabajador (salud y pensión)
 *  POST /ctr/portal/sctr/policies    {coverage_kind, insurer_type, insurer_name, insurer_ruc?, policy_number, starts_on, ends_on, file_id}
 *  POST /ctr/portal/sctr/coverages   {sctr_policy_id, contractor_worker_ids[], covered_from, covered_until, constancy_number?, file_id?}
 */
class PortalSctr extends Controller
{
    public function __construct(private RecordsService $records) {}

    public function show(): JsonResponse
    {
        $c = ContractorStatus::requirePortalContractor();
        $policies = DB::select(
            'select p.id, p.coverage_kind, p.insurer_type, p.insurer_name, p.insurer_ruc, p.policy_number, p.starts_on, p.ends_on, p.status, p.file_id,
                    f.original_name as file_name,
                    (select count(*) from sctr_coverages v where v.sctr_policy_id = p.id and v.deleted_at is null and v.covered_until >= current_date) as covered_workers
               from sctr_policies p left join files f on f.id = p.file_id
              where p.contractor_id = ? and p.deleted_at is null order by p.ends_on desc', [$c->id]);
        foreach ($policies as $p) {
            $p->status = $this->policyState($p);
            $p->covered_workers = (int) $p->covered_workers;
        }
        $coverages = DB::select(
            "select v.id, v.contractor_worker_id, p.coverage_kind, v.covered_from, least(v.covered_until, p.ends_on) as covered_until, v.constancy_number, p.policy_number
               from sctr_coverages v join sctr_policies p on p.id = v.sctr_policy_id and p.deleted_at is null and p.status <> 'cancelled'
              where p.contractor_id = ? and v.deleted_at is null and v.contractor_worker_id is not null
              order by v.covered_until desc", [$c->id]);
        $today = date('Y-m-d');
        $workers = array_map(function ($w) use ($coverages, $today) {
            $mine = array_values(array_filter($coverages, fn ($v) => $v->contractor_worker_id === $w->id));
            $kind = function (string $k) use ($mine, $today) {
                $cur = collect($mine)->first(fn ($v) => $v->coverage_kind === $k && $v->covered_from <= $today && $v->covered_until >= $today);
                $last = $cur ?? collect($mine)->first(fn ($v) => $v->coverage_kind === $k);

                return [
                    'state' => $cur ? (substr((string) $cur->covered_until, 0, 10) <= date('Y-m-d', strtotime('+'.ContractorStatus::SOON_DAYS.' days')) ? 'expiring' : 'covered') : ($last ? 'expired' : 'missing'),
                    'covered_until' => $last?->covered_until,
                    'policy_number' => $last?->policy_number,
                ];
            };
            $health = $kind('health');
            $pension = $kind('pension');

            return [
                'id' => $w->id,
                'name' => trim($w->first_name.' '.$w->paternal_last_name.' '.($w->maternal_last_name ?? '')),
                'document_last4' => $w->document_last4,
                'health' => $health,
                'pension' => $pension,
                // RN-SCT-02: con SCTR solo si salud y pensión cubren hoy.
                'has_sctr' => in_array($health['state'], ['covered', 'expiring'], true) && in_array($pension['state'], ['covered', 'expiring'], true),
            ];
        }, DB::select('select id, first_name, paternal_last_name, maternal_last_name, document_last4 from contractor_workers where contractor_id = ? and deleted_at is null and status = ? order by paternal_last_name, first_name', [$c->id, 'active']));

        return response()->json(['data' => [
            'policies' => $policies,
            'workers' => $workers,
            'kpis' => [
                'policies_active' => count(array_filter($policies, fn ($p) => in_array($p->status, ['active', 'expiring'], true))),
                'policies_expiring' => count(array_filter($policies, fn ($p) => $p->status === 'expiring')),
                'workers' => count($workers),
                'workers_without' => count(array_filter($workers, fn ($w) => ! $w['has_sctr'])),
            ],
        ]]);
    }

    public function storePolicy(Request $request): JsonResponse
    {
        $c = ContractorStatus::requirePortalContractor();
        $file = (string) $request->input('file_id');
        if (! $this->ownFile($file)) {
            $this->fail('file_id', 'Adjunta la póliza o la constancia de la aseguradora.');
        }
        if ($request->filled('insurer_ruc') && ! preg_match('/^\d{11}$/', (string) $request->input('insurer_ruc'))) {
            $this->fail('insurer_ruc', 'El RUC de la aseguradora tiene 11 dígitos.');
        }
        if ($request->filled('ends_on') && $request->input('ends_on') < date('Y-m-d')) {
            $this->fail('ends_on', 'La póliza ya está vencida: registra la vigente.');
        }
        $input = array_intersect_key($request->all(), array_flip(['coverage_kind', 'insurer_type', 'insurer_name', 'insurer_ruc', 'policy_number', 'starts_on', 'ends_on']));
        $input['contractor_id'] = $c->id;
        $input['file_id'] = $file;
        $row = $this->records->create(Registry::resource('SCT', 'sctr_policies'), array_filter($input, fn ($v) => $v !== null && $v !== ''));

        $this->notifyTeam($c, 'ctr.sctr_policy', $row['id'], 'Póliza SCTR de contratista', "{$c->legal_name} registró su póliza SCTR de ".($row['coverage_kind'] === 'health' ? 'salud' : 'pensión')." N.° {$row['policy_number']}.");

        return response()->json(['data' => $row], 201);
    }

    public function storeCoverages(Request $request): JsonResponse
    {
        $c = ContractorStatus::requirePortalContractor();
        $policyId = (string) $request->input('sctr_policy_id');
        $policy = Str::isUuid($policyId) ? DB::selectOne(
            'select id, coverage_kind, starts_on, ends_on, status, policy_number from sctr_policies where id = ? and contractor_id = ? and deleted_at is null', [$policyId, $c->id]) : null;
        if (! $policy || $policy->status === 'cancelled') {
            $this->fail('sctr_policy_id', 'Elige una de tus pólizas vigentes.');
        }
        $ids = array_values(array_unique(array_filter((array) $request->input('contractor_worker_ids', []), fn ($v) => Str::isUuid((string) $v))));
        if ($ids === []) {
            $this->fail('contractor_worker_ids', 'Elige a los trabajadores cubiertos por la constancia.');
        }
        $found = array_column(DB::select('select id from contractor_workers where contractor_id = ? and deleted_at is null and id = any(?::uuid[])', [$c->id, '{'.implode(',', $ids).'}']), 'id');
        if (count($found) !== count($ids)) {
            $this->fail('contractor_worker_ids', 'Alguno de los trabajadores no es de tu empresa.');
        }
        $from = (string) $request->input('covered_from');
        $until = (string) $request->input('covered_until');
        if (! $this->isDate($from)) {
            $this->fail('covered_from', 'Indica desde cuándo cubre la constancia.');
        }
        if (! $this->isDate($until)) {
            $this->fail('covered_until', 'Indica hasta cuándo cubre la constancia.');
        }
        if ($until < $from) {
            $this->fail('covered_until', 'La fecha de fin debe ser posterior al inicio.');
        }
        // RN-SCT-03: la cobertura no puede exceder la vigencia de su póliza.
        if ($from < substr((string) $policy->starts_on, 0, 10) || $until > substr((string) $policy->ends_on, 0, 10)) {
            $this->fail('covered_until', 'La cobertura debe estar dentro de la vigencia de la póliza ('.date('d/m/Y', strtotime($policy->starts_on)).' – '.date('d/m/Y', strtotime($policy->ends_on)).').');
        }
        $file = $request->input('file_id');
        if ($file && ! $this->ownFile((string) $file)) {
            $this->fail('file_id', 'El archivo de la constancia no es válido.');
        }
        $number = trim((string) $request->input('constancy_number')) ?: null;
        $ctx = app(CompanyContext::class);
        $user = (string) auth()->id();
        $status = $until < date('Y-m-d') ? 'expired' : 'covered';

        $created = DB::transaction(function () use ($ids, $policy, $from, $until, $number, $file, $ctx, $user, $status) {
            $out = [];
            foreach ($ids as $w) {
                // RN-SCT-07/08: una constancia del mismo tipo y periodo reemplaza a la anterior (queda en el historial, sin duplicar).
                DB::update(
                    'update sctr_coverages v set deleted_at = now(), updated_by = ? from sctr_policies p
                      where p.id = v.sctr_policy_id and v.contractor_worker_id = ? and p.coverage_kind = ? and v.deleted_at is null
                        and v.covered_from <= ? and v.covered_until >= ?', [$user, $w, $policy->coverage_kind, $until, $from]);
                $out[] = DB::selectOne(
                    'insert into sctr_coverages (company_id, sctr_policy_id, contractor_worker_id, covered_from, covered_until, constancy_number, file_id, status, created_by, updated_by)
                     values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) returning id', [$ctx->companyId, $policy->id, $w, $from, $until, $number, $file ?: null, $status, $user, $user])->id;
            }
            DB::insert(
                "insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes, ip, user_agent)
                 values (?, ?, ?, 'user', 'create', 'SCT', 'sctr_coverages', ?, ?::jsonb, ?::inet, ?)",
                [$ctx->companyId, $user, $ctx->roleCode, $policy->id, json_encode(['policy' => $policy->policy_number, 'workers' => count($ids), 'covered_from' => $from, 'covered_until' => $until]), request()->ip(), mb_substr((string) request()->userAgent(), 0, 300) ?: null]
            );

            return $out;
        });

        $this->notifyTeam($c, 'ctr.sctr_coverage', $created[0], 'Constancia SCTR de contratista',
            "{$c->legal_name} cargó la constancia SCTR de ".($policy->coverage_kind === 'health' ? 'salud' : 'pensión').' de '.count($ids).' trabajador(es).');

        return response()->json(['data' => ['created' => count($created), 'ids' => $created]], 201);
    }

    private function policyState(object $p): string
    {
        if ($p->status === 'cancelled') {
            return 'cancelled';
        }
        $end = substr((string) $p->ends_on, 0, 10);

        return $end < date('Y-m-d') ? 'expired' : ($end <= date('Y-m-d', strtotime('+'.ContractorStatus::SOON_DAYS.' days')) ? 'expiring' : 'active');
    }

    private function notifyTeam(object $c, string $event, string $subject, string $title, string $body): void
    {
        $team = array_column(DB::select(
            "select m.user_id from memberships m join roles r on r.id = m.role_id
              where m.status = 'active' and m.deleted_at is null and r.code in ('sst_manager', 'hr')"), 'user_id');
        ContractorStatus::notify($team, $event, $subject, $title, $body, '/m/sct');
    }

    private function ownFile(string $file): bool
    {
        return Str::isUuid($file) && (bool) DB::selectOne("select 1 as ok from files where id = ? and uploaded_by = ? and deleted_at is null and status = 'available'", [$file, (string) auth()->id()]);
    }

    private function isDate(string $v): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4));
    }

    private function fail(string $field, string $message): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $message, ['errors' => [$field => [$message]]]);
    }
}
