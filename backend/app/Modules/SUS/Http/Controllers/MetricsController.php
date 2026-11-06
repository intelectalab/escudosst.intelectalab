<?php

namespace App\Modules\SUS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Panel del dueño · Métricas (SCR-SUS-09, docs/03 §11): altas, pruebas, conversión, bajas y uso por módulo.
 * Solo agregados: nunca datos de negocio de las empresas.
 *
 *  GET /internal/metrics
 */
class MetricsController extends Controller
{
    public function index(): JsonResponse
    {
        $db = DB::connection('platform');

        // Suscripción vigente de cada empresa (la más reciente no borrada).
        $current = 'select distinct on (s.company_id) s.*
                      from subscriptions s join companies c on c.id = s.company_id and c.deleted_at is null
                     where s.deleted_at is null
                     order by s.company_id, s.started_at desc';

        $byStatus = [];
        foreach ($db->select("select status, count(*) as n from ({$current}) x group by status") as $r) {
            $byStatus[$r->status] = (int) $r->n;
        }

        $byPlan = array_map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'companies' => (int) $r->n], $db->select(
            "select p.code, p.name, count(x.id) as n
               from plans p left join ({$current}) x on x.plan_id = p.id and x.status not in ('cancelled', 'trial_expired')
              where p.code in ('E', 'P', 'C') group by p.code, p.name, p.sort_order order by p.sort_order"
        ));

        // Pruebas: empezadas (trial_ends_at), convertidas (con al menos un pago), activas y vencidas.
        $trials = $db->selectOne(
            "select count(*) filter (where x.trial_ends_at is not null) as started,
                    count(*) filter (where x.status = 'trialing') as active,
                    count(*) filter (where x.status = 'trial_expired') as expired,
                    count(*) filter (where x.trial_ends_at is not null and exists (
                        select 1 from invoices i join payments pa on pa.invoice_id = i.id and pa.deleted_at is null
                         where i.subscription_id = x.id and i.deleted_at is null)) as converted
               from ({$current}) x"
        );

        $trialList = $db->select(
            "select x.id as subscription_id, x.trial_ends_at, a.id as account_id, a.name as account_name,
                    coalesce(c.trade_name, c.legal_name) as company_name, c.ruc, c.headcount_declared,
                    (x.trial_ends_at at time zone 'America/Lima')::date - (now() at time zone 'America/Lima')::date as days_left
               from ({$current}) x join companies c on c.id = x.company_id join accounts a on a.id = x.account_id
              where x.status = 'trialing' order by x.trial_ends_at"
        );

        // Últimos 6 meses (hora de Lima): altas de empresas, bajas (canceladas y bloqueos por falta de pago) e ingresos.
        $months = $db->select(
            "with m as (
                select generate_series(date_trunc('month', now() at time zone 'America/Lima') - interval '5 months',
                                       date_trunc('month', now() at time zone 'America/Lima'), interval '1 month')::date as month)
             select to_char(m.month, 'YYYY-MM') as month,
                    (select count(*) from companies c where c.deleted_at is null
                       and date_trunc('month', c.created_at at time zone 'America/Lima') = m.month) as signups,
                    (select count(*) from subscriptions s where s.deleted_at is null and s.cancelled_at is not null
                       and date_trunc('month', s.cancelled_at at time zone 'America/Lima') = m.month) as cancellations,
                    (select count(*) from audit_logs l where l.company_id is null and l.action = 'subscription_blocked'
                       and date_trunc('month', l.occurred_at at time zone 'America/Lima') = m.month) as blocked,
                    (select coalesce(sum(pa.amount), 0) from payments pa where pa.deleted_at is null and pa.status = 'confirmed'
                       and date_trunc('month', pa.paid_at at time zone 'America/Lima') = m.month) as revenue
               from m order by m.month"
        );

        // Uso por módulo: qué empresas tienen cada módulo habilitado (o en solo lectura). Es lo contratado,
        // no la actividad dentro del módulo. Incluye el grupo del catálogo para colorear y filtrar.
        $modules = array_map(fn ($r) => [
            'code' => $r->code,
            'name' => $r->name,
            'group' => (int) $r->group_number,
            'enabled' => (int) $r->enabled,
            'read_only' => (int) $r->read_only,
            'companies' => json_decode($r->companies, true),
        ], $db->select(
            "select m.code, m.name, m.group_number,
                    count(*) filter (where f.mode = 'enabled') as enabled,
                    count(*) filter (where f.mode = 'read_only') as read_only,
                    json_agg(json_build_object('name', coalesce(c.trade_name, c.legal_name), 'account_id', c.account_id, 'mode', f.mode)
                             order by f.mode, coalesce(c.trade_name, c.legal_name))
                      filter (where f.mode in ('enabled', 'read_only')) as companies
               from modules m
               join feature_flags f on f.module_code = m.code and f.deleted_at is null
               join companies c on c.id = f.company_id and c.deleted_at is null
              where m.deleted_at is null and m.code not in ('TEN', 'USR', 'AUD')
              group by m.code, m.name, m.group_number
             having count(*) filter (where f.mode in ('enabled', 'read_only')) > 0
              order by enabled desc, m.group_number, m.name"
        ));

        $addons = array_map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'companies' => (int) $r->n], $db->select(
            "select a.code, a.name, count(sa.id) as n from addons a
               left join subscription_addons sa on sa.addon_id = a.id and sa.status = 'active' and sa.deleted_at is null
              where a.deleted_at is null group by a.code, a.name order by n desc, a.name"
        ));

        return response()->json(['data' => [
            'companies' => array_sum($byStatus),
            'accounts' => (int) $db->selectOne('select count(*) as n from accounts where deleted_at is null')->n,
            'by_status' => $byStatus,
            'by_plan' => $byPlan,
            'trials' => [
                'started' => (int) $trials->started,
                'active' => (int) $trials->active,
                'expired' => (int) $trials->expired,
                'converted' => (int) $trials->converted,
                'list' => $trialList,
            ],
            'months' => array_map(fn ($r) => [
                'month' => $r->month, 'signups' => (int) $r->signups, 'cancellations' => (int) $r->cancellations,
                'blocked' => (int) $r->blocked, 'revenue' => (float) $r->revenue,
            ], $months),
            'modules' => $modules,
            'addons' => $addons,
        ]]);
    }
}
