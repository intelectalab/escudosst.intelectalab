<?php

namespace App\Modules\TEN;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\Xlsx;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Empresas, sedes y áreas (M-TEN):
 *  GET  /ten/sst-model                 RN-TEN-03: modelo SST actual, trabajadores activos, umbral y el modelo que corresponde
 *  POST /ten/sst-model {model, reason} el cambio nunca es automático: lo confirma una persona y queda en la bitácora
 *  GET  /ten/account-companies         SCR-TEN-01: empresas de la cuenta (RUC, razón social, trabajadores, modelo SST, estado, límite del plan)
 *  POST /ten/account-companies         crea otra empresa de la cuenta (RUC validado, límite del plan; mismo plan y periodo que la actual)
 *  GET  /ten/structure.xlsx            sedes, áreas y puestos con su número de trabajadores
 *  GET  /ten/sst-org                   organigrama SST: Jefe SST, comité o supervisor con sus miembros, brigadas y jefes de área
 */
class TenToolsController extends Controller
{
    public function sstModel(Request $request): JsonResponse
    {
        $ctx = app(CompanyContext::class);
        if ($request->isMethod('post')) {
            $this->can('ten.companies.update');
            $d = $request->validate(['model' => ['required', 'in:committee,supervisor'], 'reason' => ['nullable', 'string', 'max:300']]);
            $prev = DB::selectOne('select sst_model from companies where id = ?', [$ctx->companyId])->sst_model;
            if ($prev !== $d['model']) {
                DB::update('update companies set sst_model = ?, updated_at = now(), updated_by = ? where id = ?', [$d['model'], (string) auth()->id(), $ctx->companyId]);
                DB::insert("insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes, reason)
                            values (?, ?, ?, 'user', 'update', 'TEN', 'companies', ?, ?::jsonb, ?)",
                    [$ctx->companyId, (string) auth()->id(), $ctx->roleCode, $ctx->companyId, json_encode(['sst_model' => ['from' => $prev, 'to' => $d['model']]]), $d['reason'] ?? null]);
            }
        } else {
            $this->can('ten.companies.read');
        }
        $c = DB::selectOne('select sst_model from companies where id = ?', [$ctx->companyId]);
        $workers = (int) DB::selectOne("select count(*) as n from workers where status = 'active' and deleted_at is null")->n;
        $threshold = (int) app(AccountsService::class)->committeeThreshold();

        return response()->json(['data' => ['model' => $c->sst_model, 'workers' => $workers, 'threshold' => $threshold,
            'suggested' => $workers >= $threshold ? 'committee' : 'supervisor']]);
    }

    public function accountCompanies(Request $request, AccountsService $accounts): JsonResponse
    {
        $ctx = app(CompanyContext::class);
        if ($ctx->roleCode !== 'account_admin' && ! in_array('ten.companies.create', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Las empresas de la cuenta las administra quien administra la cuenta.');
        }
        $p = DB::connection('platform');
        $account = $p->selectOne('select account_id from companies where id = ?', [$ctx->companyId])->account_id;
        if ($request->isMethod('post')) {
            $d = $request->validate([
                'ruc' => ['required', 'regex:/^(10|15|17|20)\d{9}$/'],
                'legal_name' => ['required', 'string', 'min:3', 'max:200'],
                'trade_name' => ['nullable', 'string', 'max:200'],
                'economic_sector' => ['nullable', 'string', 'max:100'],
                'headcount' => ['required', 'integer', 'min:1', 'max:100000'],
            ], ['ruc.regex' => 'El RUC debe tener 11 dígitos y empezar con 10, 15, 17 o 20.']);
            $sub = $p->selectOne("select p.code, s.billing_period from subscriptions s join plans p on p.id = s.plan_id where s.company_id = ? and s.deleted_at is null
                                   order by (s.status not in ('cancelled')) desc, s.started_at desc limit 1", [$ctx->companyId]);
            $admins = array_column($p->select("select m.user_id from memberships m join roles r on r.id = m.role_id
                                                where m.company_id = ? and m.status = 'active' and m.deleted_at is null and coalesce(r.base_role_code, r.code) = 'account_admin'", [$ctx->companyId]), 'user_id');
            $r = $accounts->addCompany($account, $d + ['plan' => $sub->code ?? 'E', 'billing_period' => $sub->billing_period ?? 'monthly'], $admins ?: [(string) auth()->id()], (string) auth()->id());

            return response()->json(['data' => $r], 201);
        }
        $rows = $p->select("select c.id, c.ruc, c.legal_name, c.trade_name, c.economic_sector, c.sst_model, c.status, p.name as plan, p.max_companies
                              from companies c left join lateral (select s.plan_id from subscriptions s where s.company_id = c.id and s.deleted_at is null
                                                                  order by (s.status not in ('cancelled')) desc, s.started_at desc limit 1) s on true
                              left join plans p on p.id = s.plan_id
                             where c.account_id = ? and c.deleted_at is null order by c.legal_name", [$account]);
        $out = [];
        foreach ($rows as $r) {
            $workers = (int) $accounts->withCompany($r->id, fn ($db) => $db->selectOne("select count(*) as n from workers where status = 'active' and deleted_at is null")->n);
            $out[] = (array) $r + ['workers' => $workers];
        }
        $limit = collect($rows)->pluck('max_companies')->filter()->max();

        return response()->json(['data' => $out, 'limit' => $limit, 'active' => count(array_filter($out, fn ($r) => $r['status'] === 'active'))]);
    }

    public function structure(): Response
    {
        $this->can('ten.companies.read');
        $out = [['Estructura de la empresa'], ['Al '.date('d/m/Y').'. Trabajadores activos.'], [], ['Sede', 'Área', 'Puesto', 'Puesto crítico', 'Trabajadores']];
        foreach (DB::select("select s.name as site, coalesce(a.name, '—') as area, coalesce(p.name, '—') as position, p.is_critical,
                                    (select count(*) from workers w where w.deleted_at is null and w.status = 'active' and w.current_site_id = s.id
                                       and w.current_area_id is not distinct from a.id and w.current_position_id is not distinct from p.id) as n
                               from sites s left join areas a on a.site_id = s.id and a.deleted_at is null
                               left join positions p on p.area_id = a.id and p.deleted_at is null
                              where s.deleted_at is null order by s.name, a.name, p.name") as $r) {
            $out[] = [$r->site, $r->area, $r->position, $r->is_critical ? 'Sí' : '', (int) $r->n];
        }

        return response(Xlsx::build('Estructura', $out, 4), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="estructura-'.date('Y-m-d').'.xlsx"',
        ]);
    }

    /** M-TEN SCR-TEN-03: ficha del puesto con su IPERC vigente (peligros y niveles), EPP obligatorio y capacitaciones exigidas. */
    public function positionSheet(string $id): JsonResponse
    {
        $this->can('ten.companies.read');
        $p = \Illuminate\Support\Str::isUuid($id) ? DB::selectOne('select p.*, a.name as area, s.name as site from positions p left join areas a on a.id = p.area_id
                                                                         left join sites s on s.id = a.site_id where p.id = ? and p.deleted_at is null', [$id]) : null;
        if (! $p) {
            throw \App\Core\Http\ApiProblem::notFound('El puesto no existe.');
        }
        $ctx = app(CompanyContext::class);
        $matrices = DB::select("select m.id, m.code, m.title, m.status, m.next_review_on, v.version_number, v.approved_at from iperc_matrices m
                                 left join iperc_versions v on v.id = m.current_version_id where m.position_id = ? and m.deleted_at is null order by m.code", [$id]);
        $rows = DB::select("select r.task, r.hazard, r.risk, r.risk_level, r.residual_level, r.is_significant from iperc_rows r
                              join iperc_matrices m on m.current_version_id = r.iperc_version_id
                             where r.deleted_at is null and (m.position_id = ? or r.position_id = ?) and m.deleted_at is null
                             order by r.is_significant desc, r.risk_score desc nulls last limit 60", [$id, $id]);

        return response()->json(['data' => [
            'company' => DB::selectOne('select legal_name, ruc from companies where id = ?', [$ctx->companyId]),
            'position' => ['name' => $p->name, 'code' => $p->code, 'area' => $p->area, 'site' => $p->site, 'description' => $p->description, 'functions' => $p->functions,
                'is_critical' => (bool) $p->is_critical, 'requires_emo' => (bool) $p->requires_emo, 'requires_sctr' => (bool) $p->requires_sctr,
                'workers' => (int) DB::selectOne("select count(*) as n from workers where current_position_id = ? and status = 'active' and deleted_at is null", [$id])->n],
            'iperc' => $matrices, 'hazards' => $rows,
            'ppe' => DB::select('select i.name, i.category, r.quantity, r.replacement_days, r.is_mandatory, r.conditions from ppe_position_requirements r join ppe_items i on i.id = r.ppe_item_id
                                  where r.position_id = ? and r.deleted_at is null order by r.is_mandatory desc, i.name', [$id]),
            'trainings' => DB::select("select coalesce(t.title, c.title) as title, r.requirement_level, r.renewal_months, r.due_within_days from position_training_requirements r
                                        left join trainings t on t.id = r.training_id left join courses c on c.id = r.course_id
                                        where r.position_id = ? and r.deleted_at is null and r.is_active order by 1", [$id]),
        ]]);
    }

    public function sstOrg(): JsonResponse
    {
        $this->can('ten.companies.read');
        $ctx = app(CompanyContext::class);
        $role = fn (string $code) => DB::select("select u.full_name from memberships m join roles r on r.id = m.role_id join users u on u.id = m.user_id
                                                  where m.status = 'active' and m.deleted_at is null and coalesce(r.base_role_code, r.code) = ? order by u.full_name", [$code]);
        $committee = DB::selectOne("select id, name, committee_type, period_end_on from committees where status = 'active' and deleted_at is null order by created_at desc limit 1");
        $members = $committee ? DB::select("select w.full_name, m.representation, m.role_in_committee, m.member_type from committee_members m join workers w on w.id = m.worker_id
                                             where m.committee_id = ? and m.status = 'active' and m.deleted_at is null order by m.role_in_committee nulls last, w.full_name", [$committee->id]) : [];

        return response()->json(['data' => [
            'company' => DB::selectOne('select legal_name, ruc, sst_model from companies where id = ?', [$ctx->companyId]),
            'management' => $role('management'), 'sst_managers' => $role('sst_manager'), 'sst_supervisors' => $role('sst_supervisor'), 'doctors' => $role('occupational_doctor'),
            'committee' => $committee, 'members' => $members,
            'brigades' => DB::select("select b.name, w.full_name as leader from brigades b left join workers w on w.id = b.leader_worker_id where b.deleted_at is null and b.status = 'active' order by b.name"),
            'area_leads' => DB::select("select a.name as area, w.full_name from areas a join workers w on w.id = a.manager_worker_id where a.deleted_at is null order by a.name"),
        ]]);
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no ve esta información de la empresa.');
        }
    }
}
