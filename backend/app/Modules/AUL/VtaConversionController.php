<?php

namespace App\Modules\AUL;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\Xlsx;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * M-VTA §11 «Conversión de recomendaciones y campañas en ventas»: pedidos atribuidos a una recomendación (converted_order_id, por
 * matrícula de compra o por cupos de empresa, RN-VTA-23), por pedido, por campaña y por empresa compradora.
 *  GET /vta/conversion?from=&to=        recomendaciones enviadas en el periodo (o creadas, si no se enviaron)
 *  GET /vta/conversion.xlsx?from=&to=   lo mismo en Excel
 * Una campaña es el envío masivo de una oferta (reason_code «campaign») en un día. Lo vendido es el total cobrado menos lo reembolsado.
 */
class VtaConversionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->can('vta.orders.read');

        return response()->json(['data' => $this->build($request)]);
    }

    public function xlsx(Request $request): Response
    {
        $this->can('vta.orders.export');
        $d = $this->build($request);
        $money = fn ($n) => round((float) $n, 2);
        $out = [['Conversión de recomendaciones y campañas en ventas'], ["Recomendaciones enviadas del {$d['from']} al {$d['to']}."], [], ['Por pedido'],
            ['Pedido', 'Pagado', 'Comprador', 'RUC', 'Curso', 'Recomendación', 'Campaña', 'Vendido (S/)']];
        foreach ($d['orders'] as $o) {
            $out[] = [$o['order_number'], $o['paid_at'] ? substr($o['paid_at'], 0, 10) : '', $o['buyer'], $o['buyer_ruc'] ?? '', $o['course'], $o['source'], $o['campaign'] ?? '', $money($o['amount'])];
        }
        if ($d['orders'] === []) {
            $out[] = ['Sin pedidos atribuidos'];
        }
        $out[] = [];
        $out[] = ['Por campaña'];
        $out[] = ['Oferta', 'Envío', 'Enviadas', 'Vistas', 'Con clic', 'Convertidas', 'Pedidos', 'Vendido (S/)', 'Tasa (%)'];
        foreach ($d['campaigns'] as $c) {
            $out[] = [$c['offer'], $c['sent_on'], $c['sent'], $c['viewed'], $c['clicked'], $c['converted'], $c['orders'], $money($c['amount']), $c['conversion_pct'] ?? ''];
        }
        if ($d['campaigns'] === []) {
            $out[] = ['Sin campañas'];
        }
        $out[] = [];
        $out[] = ['Por empresa compradora'];
        $out[] = ['Empresa', 'RUC', 'Recomendaciones', 'Convertidas', 'Pedidos', 'Vendido (S/)', 'Tasa (%)'];
        foreach ($d['companies'] as $c) {
            $out[] = [$c['name'], $c['ruc'], $c['recommendations'], $c['converted'], $c['orders'], $money($c['amount']), $c['conversion_pct'] ?? ''];
        }
        if ($d['companies'] === []) {
            $out[] = ['Sin recomendaciones a empresas compradoras'];
        }

        return response(Xlsx::build('Conversión', $out, 5), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"conversion-ventas-{$d['from']}-{$d['to']}.xlsx\"",
        ]);
    }

    private function build(Request $request): array
    {
        $p = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']],
            ['to.after_or_equal' => 'La fecha «Hasta» debe ser igual o posterior a «Desde».']);
        $from = $p['from'] ?? now('America/Lima')->startOfMonth()->toDateString();
        $to = $p['to'] ?? now('America/Lima')->toDateString();
        $period = "r.deleted_at is null and (coalesce(r.sent_at, r.created_at) at time zone 'America/Lima')::date between ?::date and ?::date";
        $sold = "case when o.status in ('paid', 'partially_refunded', 'refunded') then o.total - o.refunded_total else 0 end";
        $source = ['rule' => 'Regla', 'manual' => 'Manual', 'ai' => 'Asistente (IA)'];

        $orders = DB::select(
            "select o.id, o.order_number, o.paid_at, coalesce(o.buyer_legal_name, o.buyer_name) as buyer, o.buyer_ruc, concat_ws(' · ', c.code, c.title) as course,
                    r.source, r.reason_code, rr.name as rule_name, f.title as offer, (coalesce(r.sent_at, r.created_at) at time zone 'America/Lima')::date as sent_on, {$sold} as amount
               from course_recommendations r join orders o on o.id = r.converted_order_id join courses c on c.id = r.course_id
               left join recommendation_rules rr on rr.id = r.rule_id left join course_offers f on f.id = r.course_offer_id
              where {$period} and r.status = 'converted' order by o.paid_at desc nulls last, o.order_number", [$from, $to]);
        $campaigns = DB::select(
            "select r.course_offer_id, coalesce(f.title, c.title) as offer, (coalesce(r.sent_at, r.created_at) at time zone 'America/Lima')::date as sent_on,
                    count(*) filter (where r.sent_at is not null) as sent, count(*) filter (where r.viewed_at is not null) as viewed,
                    count(*) filter (where r.clicked_at is not null) as clicked, count(*) filter (where r.status = 'converted') as converted,
                    count(distinct r.converted_order_id) as orders,
                    coalesce((select sum({$sold}) from orders o where o.id = any(array_agg(r.converted_order_id))), 0) as amount
               from course_recommendations r join courses c on c.id = r.course_id left join course_offers f on f.id = r.course_offer_id
              where {$period} and r.reason_code = 'campaign'
              group by r.course_offer_id, r.course_id, 2, 3 order by 3 desc, 2", [$from, $to]);
        $companies = DB::select(
            "select coalesce(r.buyer_company_ruc, xl.buyer_company_ruc) as ruc, max(coalesce(r.buyer_company_name, xl.buyer_company_name)) as name,
                    count(*) as recommendations, count(*) filter (where r.status = 'converted') as converted, count(distinct r.converted_order_id) as orders,
                    coalesce((select sum({$sold}) from orders o where o.id = any(array_agg(r.converted_order_id))), 0) as amount
               from course_recommendations r left join external_learners xl on xl.id = r.external_learner_id
              where {$period} and coalesce(r.buyer_company_ruc, xl.buyer_company_ruc) is not null
              group by 1 order by 6 desc, 2", [$from, $to]);

        $rate = fn ($conv, $base) => (int) $base > 0 ? round((int) $conv * 100 / (int) $base, 1) : null;

        return [
            'from' => $from, 'to' => $to,
            'orders' => array_map(fn ($o) => ['id' => $o->id, 'order_number' => $o->order_number, 'paid_at' => $o->paid_at, 'buyer' => $o->buyer, 'buyer_ruc' => $o->buyer_ruc,
                'course' => $o->course, 'source' => ($source[$o->source] ?? $o->source).($o->rule_name ? " · {$o->rule_name}" : ''),
                'campaign' => $o->reason_code === 'campaign' ? trim(($o->offer ?? '').' · '.implode('/', array_reverse(explode('-', (string) $o->sent_on))), ' ·') : null,
                'amount' => (float) $o->amount], $orders),
            'campaigns' => array_map(fn ($c) => ['offer' => $c->offer, 'sent_on' => $c->sent_on, 'sent' => (int) $c->sent, 'viewed' => (int) $c->viewed, 'clicked' => (int) $c->clicked,
                'converted' => (int) $c->converted, 'orders' => (int) $c->orders, 'amount' => (float) $c->amount, 'conversion_pct' => $rate($c->converted, $c->sent)], $campaigns),
            'companies' => array_map(fn ($c) => ['ruc' => trim((string) $c->ruc), 'name' => $c->name, 'recommendations' => (int) $c->recommendations, 'converted' => (int) $c->converted,
                'orders' => (int) $c->orders, 'amount' => (float) $c->amount, 'conversion_pct' => $rate($c->converted, $c->recommendations)], $companies),
        ];
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no ve las ventas.');
        }
    }
}
