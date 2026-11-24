<?php

namespace App\Modules\SUS\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Core\Support\Xlsx;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel del dueño · Reportes (M-SUS §11), en Excel:
 *  GET /internal/reports/accounts.xlsx?from=&to=   cuentas por plan y complemento; altas y bajas del periodo
 *  GET /internal/reports/invoices.xlsx?from=&to=   comprobantes emitidos en el periodo con su estado SUNAT
 * Periodo por defecto: el mes en curso (hora de Lima). Cada descarga queda en la bitácora de plataforma (RN-AUD-05).
 */
class PlatformReportsController extends Controller
{
    private const STATUS = ['trialing' => 'En prueba', 'active' => 'Activa', 'past_due' => 'Pago pendiente', 'read_only' => 'Solo lectura',
        'pending_cancellation' => 'Baja programada', 'cancelled' => 'Cancelada', 'trial_expired' => 'Prueba vencida'];

    private const DOC = ['invoice' => 'Factura', 'receipt' => 'Boleta', 'credit_note' => 'Nota de crédito', 'debit_note' => 'Nota de débito'];

    private const INV_STATUS = ['draft' => 'Borrador', 'issued' => 'Emitido', 'overdue' => 'Vencido', 'paid' => 'Pagado', 'voided' => 'Anulado', 'refunded' => 'Reembolsado'];

    private const SUNAT = ['pending' => 'Pendiente', 'accepted' => 'Aceptado', 'observed' => 'Observado', 'rejected' => 'Rechazado', 'not_applicable' => 'No aplica'];

    public function accounts(Request $request, AccountsService $accounts): Response
    {
        [$from, $to] = $this->period($request);
        $db = DB::connection('platform');
        // Suscripción vigente de cada empresa (la más reciente no borrada), con sus complementos activos.
        $rows = $db->select(
            "select a.name as account_name, coalesce(c.trade_name, c.legal_name) as company_name, c.ruc, p.code as plan_code, p.name as plan_name,
                    x.status, x.billing_period, x.started_at, x.trial_ends_at, x.cancelled_at, x.cancel_reason, c.created_at as company_created_at,
                    (select string_agg(ad.name, ', ' order by ad.name) from subscription_addons sa join addons ad on ad.id = sa.addon_id
                      where sa.subscription_id = x.id and sa.status = 'active' and sa.deleted_at is null) as addons
               from (select distinct on (s.company_id) s.* from subscriptions s where s.deleted_at is null and s.company_id is not null
                      order by s.company_id, s.started_at desc) x
               join companies c on c.id = x.company_id and c.deleted_at is null
               join accounts a on a.id = x.account_id
               join plans p on p.id = x.plan_id
              order by p.sort_order, a.name, company_name");
        $in = fn (?string $ts) => $ts !== null && ($d = (new \DateTime($ts))->setTimezone(new \DateTimeZone('America/Lima'))->format('Y-m-d')) >= $from && $d <= $to;
        $lima = fn (?string $ts) => $ts ? (new \DateTime($ts))->setTimezone(new \DateTimeZone('America/Lima'))->format('d/m/Y') : '';
        $plans = [];
        $signups = $drops = 0;
        $data = [];
        foreach ($rows as $r) {
            $alta = $in($r->company_created_at);
            $baja = $in($r->cancelled_at);
            $signups += (int) $alta;
            $drops += (int) $baja;
            $plans[$r->plan_name] = ($plans[$r->plan_name] ?? 0) + (in_array($r->status, ['cancelled', 'trial_expired'], true) ? 0 : 1);
            $data[] = [$r->account_name, $r->company_name, trim((string) $r->ruc), $r->plan_name, $r->addons ?? '', self::STATUS[$r->status] ?? $r->status,
                $r->billing_period === 'annual' ? 'Anual' : 'Mensual', $lima($r->company_created_at), $lima($r->trial_ends_at), $lima($r->cancelled_at), $r->cancel_reason ?? '',
                $alta ? 'Sí' : 'No', $baja ? 'Sí' : 'No'];
        }
        $byPlan = implode(' · ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($plans), $plans));
        $out = [['Cuentas por plan y complemento; altas y bajas'],
            ["Del {$from} al {$to}. Empresas vigentes por plan — {$byPlan}. Altas del periodo: {$signups} · Bajas del periodo: {$drops}."], [],
            ['Cuenta', 'Empresa', 'RUC', 'Plan', 'Complementos activos', 'Estado', 'Facturación', 'Alta', 'Fin de la prueba', 'Baja', 'Motivo de la baja', 'Alta en el periodo', 'Baja en el periodo'],
            ...$data];
        $accounts->audit((string) auth()->id(), 'export', 'subscriptions', null, ['report' => 'accounts', 'filters' => ['from' => $from, 'to' => $to], 'rows' => count($data)]);

        return $this->xlsx(Xlsx::build('Cuentas', $out, 4), "cuentas-{$from}-{$to}.xlsx");
    }

    public function invoices(Request $request, AccountsService $accounts): Response
    {
        [$from, $to] = $this->period($request);
        $rows = DB::connection('platform')->select(
            "select i.document_type, i.series, i.number, coalesce(i.issue_on, (i.created_at at time zone 'America/Lima')::date) as issued, i.due_on,
                    a.name as account_name, i.customer_name, i.customer_tax_id, i.currency, i.subtotal, i.tax, i.total, i.status, i.sunat_status,
                    i.lines->0->>'description' as concept
               from invoices i join accounts a on a.id = i.account_id
              where i.deleted_at is null and coalesce(i.issue_on, (i.created_at at time zone 'America/Lima')::date) between ?::date and ?::date
              order by issued, i.series, i.number", [$from, $to]);
        $total = 0.0;
        $data = [];
        foreach ($rows as $r) {
            $total += $r->status === 'voided' ? 0 : (float) $r->total;
            $data[] = [self::DOC[$r->document_type] ?? $r->document_type, trim(($r->series ?? '').'-'.($r->number ?? ''), '-'), date('d/m/Y', strtotime($r->issued)),
                $r->due_on ? date('d/m/Y', strtotime($r->due_on)) : '', $r->account_name, $r->customer_name ?? '', $r->customer_tax_id ?? '', $r->concept ?? '', $r->currency,
                (float) $r->subtotal, (float) $r->tax, (float) $r->total, self::INV_STATUS[$r->status] ?? $r->status, self::SUNAT[$r->sunat_status] ?? $r->sunat_status];
        }
        $out = [['Comprobantes emitidos por periodo'], ["Del {$from} al {$to}. ".count($data).' comprobante(s); total sin anulados: '.number_format($total, 2, '.', ',').'.'], [],
            ['Tipo', 'Serie y número', 'Emisión', 'Vencimiento', 'Cuenta', 'Cliente', 'RUC o documento', 'Concepto', 'Moneda', 'Subtotal', 'IGV', 'Total', 'Estado', 'Estado SUNAT'],
            ...$data];
        $accounts->audit((string) auth()->id(), 'export', 'invoices', null, ['report' => 'invoices', 'filters' => ['from' => $from, 'to' => $to], 'rows' => count($data)]);

        return $this->xlsx(Xlsx::build('Comprobantes', $out, 4), "comprobantes-{$from}-{$to}.xlsx");
    }

    /** @return array{0: string, 1: string} */
    private function period(Request $request): array
    {
        $from = (string) ($request->query('from') ?: now('America/Lima')->startOfMonth()->toDateString());
        $to = (string) ($request->query('to') ?: now('America/Lima')->toDateString());
        foreach (['from' => $from, 'to' => $to] as $k => $v) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || ! strtotime($v)) {
                throw new ApiProblem(422, 'validation_failed', 'Revisa el periodo', 'La fecha no es válida.', ['errors' => [$k => ['La fecha no es válida.']]]);
            }
        }
        if ($from > $to) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa el periodo', 'La fecha inicial es posterior a la final.', ['errors' => ['from' => ['La fecha inicial es posterior a la final.']]]);
        }

        return [$from, $to];
    }

    private function xlsx(string $bytes, string $name): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$name}\"",
        ]);
    }
}
