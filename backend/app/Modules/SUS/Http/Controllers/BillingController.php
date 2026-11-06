<?php

namespace App\Modules\SUS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Panel del dueño · Facturación (SCR-SUS-06, docs/03 §11): pagos y comprobantes de todas las cuentas,
 * y las suscripciones que hoy deben (pago pendiente o solo lectura por falta de pago).
 * Los comprobantes quedan con estado SUNAT «pendiente» hasta integrar el proveedor OSE/PSE (DA-06).
 *
 *  GET /internal/billing
 */
class BillingController extends Controller
{
    public function index(): JsonResponse
    {
        $db = DB::connection('platform');

        $payments = array_map(fn ($r) => array_merge((array) $r, ['amount' => (float) $r->amount]), $db->select(
            "select pa.id, pa.amount, pa.currency, pa.method, pa.reference, pa.paid_at, pa.status, pa.created_at,
                    i.id as invoice_id, i.series, i.number, i.sunat_status, i.lines->0->>'description' as concept,
                    a.id as account_id, a.name as account_name, coalesce(c.trade_name, c.legal_name) as company_name, c.ruc,
                    p.code as plan_code, u.full_name as registered_by
               from payments pa
               join invoices i on i.id = pa.invoice_id and i.deleted_at is null
               join accounts a on a.id = pa.account_id
               left join subscriptions s on s.id = i.subscription_id
               left join companies c on c.id = s.company_id
               left join plans p on p.id = s.plan_id
               left join users u on u.id = pa.created_by
              where pa.deleted_at is null
              order by pa.paid_at desc, pa.created_at desc
              limit 500"
        ));

        $debtors = $db->select(
            "select s.id as subscription_id, s.status, s.current_period_end, s.grace_until, a.id as account_id, a.name as account_name,
                    coalesce(c.trade_name, c.legal_name) as company_name, c.ruc, p.code as plan_code, p.name as plan_name
               from subscriptions s
               join companies c on c.id = s.company_id and c.deleted_at is null
               join accounts a on a.id = s.account_id and a.deleted_at is null
               join plans p on p.id = s.plan_id
              where s.deleted_at is null and s.status in ('past_due', 'read_only')
              order by s.current_period_end nulls last"
        );

        return response()->json(['data' => ['payments' => $payments, 'debtors' => $debtors]]);
    }
}
