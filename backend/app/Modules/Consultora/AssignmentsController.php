<?php

namespace App\Modules\Consultora;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Matriz consultor × empresa (M-CON SCR-CON-04, RN-CON-01) y vínculo visto desde la empresa cliente (RN-CON-02):
 *  GET  /con/assignments   consultores de la cuenta (consultores y administradores de sus empresas propias) × empresas
 *                          cliente, con la asignación vigente y quién es el responsable principal de cada empresa
 *  POST /con/assignments   {company_id, user_id, assigned, is_lead?} asignar / quitar / marcar responsable principal
 *  GET  /con/link          la empresa activa: consultora que la gestiona y sus consultores asignados (para «Quitar acceso»)
 *
 * Solo el administrador de la cuenta consultora cambia la matriz; un consultor la ve en solo lectura. Asignar crea (o
 * reactiva) la membresía con rol consultor en la empresa cliente; quitar la asignación revoca esa membresía, así el
 * consultor deja de entrar de inmediato. Cada escritura se hace con el contexto RLS de la empresa cliente.
 */
class AssignmentsController extends Controller
{
    public function __construct(private AccountsService $accounts) {}

    public function index(): JsonResponse
    {
        $con = Consultancy::resolve();

        return response()->json(['data' => $this->matrix($con)]);
    }

    public function update(Request $request): JsonResponse
    {
        $con = Consultancy::resolve();
        if (! $con->is_admin) {
            throw ApiProblem::forbidden('Las asignaciones las cambia el administrador de la cuenta de la consultora.');
        }
        $company = (string) $request->input('company_id');
        $user = (string) $request->input('user_id');
        if (! Str::isUuid($company) || ! $this->p()->selectOne('select 1 as ok from companies where id = ? and managed_by_account_id = ? and deleted_at is null', [$company, $con->account_id])) {
            $this->fail('company_id', 'Elige una empresa cliente de tu consultora.');
        }
        $team = $this->consultants($con->account_id);
        if (! Str::isUuid($user) || ! isset($team[$user])) {
            $this->fail('user_id', 'Elige a un consultor de tu consultora.');
        }
        $assign = $request->boolean('assigned');
        $lead = $request->boolean('is_lead');
        $actor = (string) auth()->id();
        $account = $con->account_id;

        $this->accounts->withCompany($company, function (ConnectionInterface $db) use ($company, $user, $assign, $lead, $actor, $account) {
            $current = $db->selectOne("select id, is_lead from consultant_assignments where consultant_user_id = ? and status = 'active' and deleted_at is null", [$user]);
            if ($assign) {
                $m = $db->selectOne('select m.id, m.status, r.code from memberships m join roles r on r.id = m.role_id where m.company_id = ? and m.user_id = ? and m.deleted_at is null', [$company, $user]);
                if (! $m) {
                    $mid = $db->selectOne(
                        "insert into memberships (company_id, user_id, role_id, status, invited_by, invited_at, accepted_at, created_by)
                         select ?, ?, r.id, 'active', ?, now(), now(), ? from roles r where r.account_id is null and r.code = 'consultant' returning id",
                        [$company, $user, $actor, $actor])->id;
                } else {
                    $mid = $m->id;
                    if ($m->code === 'consultant' && $m->status !== 'active') {
                        $db->update("update memberships set status = 'active', updated_at = now() where id = ?", [$mid]);
                    }
                }
                if ($lead) {
                    $db->update("update consultant_assignments set is_lead = false, updated_at = now(), updated_by = ? where consultancy_account_id = ? and status = 'active' and deleted_at is null and consultant_user_id <> ?",
                        [$actor, $account, $user]);
                }
                if ($current) {
                    $db->update('update consultant_assignments set is_lead = ?, membership_id = coalesce(membership_id, ?), updated_at = now(), updated_by = ? where id = ?', [$lead, $mid, $actor, $current->id]);
                } else {
                    $db->insert('insert into consultant_assignments (company_id, consultancy_account_id, consultant_user_id, membership_id, is_lead, created_by, updated_by) values (?, ?, ?, ?, ?, ?, ?)',
                        [$company, $account, $user, $mid, $lead, $actor, $actor]);
                }
            } elseif ($current) {
                $db->update("update consultant_assignments set status = 'ended', ends_on = current_date, is_lead = false, updated_at = now(), updated_by = ? where id = ?", [$actor, $current->id]);
                // RN-CON-01: sin asignación vigente no entra. Solo se revoca el acceso que le dio la consultora (rol consultor).
                $db->update("update memberships m set status = 'revoked', updated_at = now() from roles r
                              where r.id = m.role_id and r.code = 'consultant' and m.company_id = ? and m.user_id = ? and m.status = 'active'", [$company, $user]);
            }
        });
        $this->accounts->audit($actor, $assign ? 'consultant_assigned' : 'consultant_unassigned', 'consultant_assignments', $company,
            ['user_id' => $user, 'company_id' => $company, 'is_lead' => $assign && $lead]);

        return response()->json(['data' => $this->matrix(Consultancy::resolve())]);
    }

    /** Vínculo de la empresa activa con su consultora (lo ve la empresa cliente para poder quitar el acceso). */
    public function link(): JsonResponse
    {
        $ctx = app(CompanyContext::class);
        $account = $this->p()->selectOne('select managed_by_account_id from companies where id = ?', [$ctx->companyId])?->managed_by_account_id;
        if (! $account) {
            return response()->json(['data' => ['managed' => false]]);
        }
        $name = $this->p()->selectOne('select coalesce(w.brand_name, a.name) as n from accounts a left join white_label_settings w on w.account_id = a.id and w.deleted_at is null where a.id = ?', [$account])?->n;
        $rows = DB::select("select consultant_user_id, is_lead, starts_on from consultant_assignments where consultancy_account_id = ? and status = 'active' and deleted_at is null order by is_lead desc, starts_on", [$account]);
        $names = $this->names(array_column($rows, 'consultant_user_id'));

        return response()->json(['data' => [
            'managed' => true,
            'consultancy' => $name,
            'consultants' => array_map(fn ($r) => ['name' => $names[$r->consultant_user_id]->full_name ?? 'Consultor', 'is_lead' => (bool) $r->is_lead, 'since' => $r->starts_on], $rows),
            'can_revoke' => in_array($ctx->roleCode, ['account_admin', 'sst_manager'], true),
        ]]);
    }

    private function matrix(object $con): array
    {
        $team = $this->consultants($con->account_id);
        $clients = $this->p()->select(
            'select id, coalesce(trade_name, legal_name) as name, trim(ruc) as ruc from companies where managed_by_account_id = ? and deleted_at is null order by 2', [$con->account_id]);
        $cells = [];
        foreach ($clients as $c) {
            $rows = $this->accounts->withCompany($c->id, fn (ConnectionInterface $db) => $db->select(
                "select consultant_user_id, is_lead, starts_on from consultant_assignments where consultancy_account_id = ? and status = 'active' and deleted_at is null", [$con->account_id]));
            foreach ($rows as $r) {
                $cells[] = ['company_id' => $c->id, 'user_id' => $r->consultant_user_id, 'is_lead' => (bool) $r->is_lead, 'since' => $r->starts_on];
                if (! isset($team[$r->consultant_user_id])) {
                    $team[$r->consultant_user_id] = 'consultant';   // asignado que ya no está en la cuenta: se muestra para poder quitarlo
                }
            }
        }
        $names = $this->names(array_keys($team));
        $consultants = array_map(fn ($id) => [
            'id' => $id,
            'name' => $names[$id]->full_name ?? 'Consultor',
            'email' => $names[$id]->email ?? null,
            'role' => $team[$id],
            'companies' => count(array_filter($cells, fn ($x) => $x['user_id'] === $id)),
        ], array_keys($team));
        usort($consultants, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'consultancy' => $con->name,
            'can_edit' => $con->is_admin,
            'consultants' => $consultants,
            'companies' => array_map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'ruc' => $c->ruc,
                'assigned' => count(array_filter($cells, fn ($x) => $x['company_id'] === $c->id))], $clients),
            'assignments' => $cells,
        ];
    }

    /** @return array<string, string> user_id => rol en la cuenta (consultores y administradores de las empresas propias). */
    private function consultants(string $account): array
    {
        $out = [];
        foreach ($this->p()->select('select id from companies where account_id = ? and deleted_at is null', [$account]) as $c) {
            $rows = $this->accounts->withCompany($c->id, fn (ConnectionInterface $db) => $db->select(
                "select m.user_id, r.code from memberships m join roles r on r.id = m.role_id
                  where m.company_id = ? and m.status = 'active' and m.deleted_at is null and r.code in ('consultant', 'account_admin')", [$c->id]));
            foreach ($rows as $r) {
                $out[$r->user_id] = ($out[$r->user_id] ?? null) === 'account_admin' ? 'account_admin' : $r->code;
            }
        }

        return $out;
    }

    /** @return array<string, object> */
    private function names(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (! $ids) {
            return [];
        }
        $out = [];
        foreach ($this->p()->select('select id, full_name, email from users where id = any(?::uuid[])', ['{'.implode(',', $ids).'}']) as $u) {
            $out[$u->id] = $u;
        }

        return $out;
    }

    private function fail(string $field, string $message): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $message, ['errors' => [$field => [$message]]]);
    }

    private function p(): ConnectionInterface
    {
        return DB::connection('platform');
    }
}
