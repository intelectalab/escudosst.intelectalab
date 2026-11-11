<?php

namespace App\Modules\Consultora;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * La consultora del usuario (M-CON, RN-CON-01): la cuenta consultora de la empresa activa (o la que gestiona la
 * empresa cliente activa) y las membresías del usuario en sus empresas propias y en sus empresas cliente.
 * Solo entra el administrador de la cuenta o un consultor de esa consultora.
 */
final class Consultancy
{
    /**
     * @return object{account_id: string, name: string, is_admin: bool, own: list<object>, clients: list<object>}
     */
    public static function resolve(): object
    {
        $p = DB::connection('platform');
        $active = app(CompanyContext::class)->companyId;
        $c = $p->selectOne('select c.account_id, c.managed_by_account_id, a.account_type from companies c join accounts a on a.id = c.account_id where c.id = ?', [$active]);
        $account = $c?->account_type === 'consultancy' ? $c->account_id : $c?->managed_by_account_id;
        if (! $account) {
            throw ApiProblem::forbidden('El panel de la consultora es para las cuentas con el plan Consultora.');
        }
        $mine = DB::select('select membership_id, company_id, company_name, ruc, role_code from app_my_memberships() order by company_name');
        $info = [];
        if ($mine) {
            foreach ($p->select('select id, account_id, managed_by_account_id, economic_sector, sst_model from companies where id = any(?::uuid[]) and deleted_at is null',
                ['{'.implode(',', array_column($mine, 'company_id')).'}']) as $r) {
                $info[$r->id] = $r;
            }
        }
        $own = [];
        $clients = [];
        foreach ($mine as $m) {
            $i = $info[$m->company_id] ?? null;
            if (! $i) {
                continue;
            }
            $m->ruc = trim($m->ruc);
            $m->economic_sector = $i->economic_sector;
            $m->sst_model = $i->sst_model;
            if ($i->account_id === $account) {
                $own[] = $m;
            } elseif ($i->managed_by_account_id === $account) {
                $clients[] = $m;
            }
        }
        $isAdmin = (bool) array_filter($own, fn ($m) => $m->role_code === 'account_admin');
        $allowed = $isAdmin || array_filter([...$own, ...$clients], fn ($m) => $m->role_code === 'consultant');
        if (! $allowed) {
            throw ApiProblem::forbidden('El panel de la consultora es para el administrador de la cuenta y los consultores SST.');
        }
        $name = $p->selectOne("select coalesce(w.brand_name, a.name) as n from accounts a left join white_label_settings w on w.account_id = a.id and w.deleted_at is null where a.id = ?", [$account])->n;

        return (object) ['account_id' => $account, 'name' => $name, 'is_admin' => $isAdmin, 'own' => $own, 'clients' => $clients];
    }
}
