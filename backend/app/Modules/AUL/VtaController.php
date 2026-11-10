<?php

namespace App\Modules\AUL;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\Providers;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Venta de cursos (VTA), registrada por la empresa vendedora:
 *  POST /vta/sell                 pedido de una oferta para una persona (1 matrícula) o una empresa (cupos)
 *  POST /vta/orders/{id}/pay      registrar el pago manual {method: yape|plin|transfer|deposit|cash, reference, paid_on}
 *                                 (con la pasarela configurada en Proveedores y claves, el pago en línea se confirma solo)
 * Al pagarse: persona → participante externo matriculado en el curso; empresa → licencia con sus cupos para asignar.
 * El precio incluye IGV (18 %, parámetro igv_rate).
 */
class VtaController extends Controller
{
    public function sell(Request $request): JsonResponse
    {
        $this->can('vta.orders.create');
        $d = $request->validate([
            'course_offer_id' => ['required', 'uuid'],
            'buyer_type' => ['required', 'in:person,company'],
            'document_type' => ['required', 'in:DNI,CE,PTP,PASSPORT,RUC,OTHER'],
            'document_number' => ['required', 'string', 'min:6', 'max:20'],
            'first_name' => ['required_if:buyer_type,person', 'nullable', 'string', 'max:80'],
            'last_name' => ['required_if:buyer_type,person', 'nullable', 'string', 'max:80'],
            'legal_name' => ['required_if:buyer_type,company', 'nullable', 'string', 'max:160'],
            'ruc' => ['required_if:buyer_type,company', 'nullable', 'regex:/^(10|15|17|20)\d{9}$/'],
            'email' => ['required', 'email:rfc', 'max:160'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:500'],
        ], ['ruc.regex' => 'El RUC debe tener 11 dígitos y empezar con 10, 15, 17 o 20.', '*.required_if' => 'Completa este dato.']);
        $o = DB::selectOne("select * from course_offers where id = ? and deleted_at is null and status = 'published'", [$d['course_offer_id']]);
        if (! $o) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'La oferta no existe o no está publicada.', ['errors' => ['course_offer_id' => ['La oferta no existe o no está publicada.']]]);
        }
        $company = $d['buyer_type'] === 'company';
        $qty = $company ? (int) ($d['seats'] ?? 1) : 1;
        if ($company && ! $o->allows_company_purchase) {
            $this->fail('buyer_type', 'Esta oferta no se vende a empresas.');
        }
        if ($company && $o->min_company_seats && $qty < (int) $o->min_company_seats) {
            $this->fail('seats', "La compra para empresas es desde {$o->min_company_seats} cupos.");
        }
        if ($o->seats_total && (int) $o->seats_sold + $qty > (int) $o->seats_total) {
            $this->fail('seats', 'No quedan cupos suficientes en esta oferta.');
        }
        $unit = (float) ($o->sale_price ?? $o->list_price);
        $total = round($unit * $qty, 2);
        $sub = $o->price_includes_igv && $o->igv_affectation === 'taxed' ? round($total / 1.18, 2) : $total;
        $igv = round($total - $sub, 2);
        $n = (int) DB::selectOne('select count(*) + 1 as n from orders')->n;
        $number = sprintf('PED-%s-%05d', date('Y'), $n);
        $name = $company ? $d['legal_name'] : trim($d['first_name'].' '.$d['last_name']);
        $ctx = app(CompanyContext::class)->companyId;

        $id = DB::transaction(function () use ($ctx, $number, $d, $company, $name, $sub, $igv, $total, $o, $qty, $unit) {
            $learner = $company ? null : $this->learner($ctx, $d);
            $id = DB::selectOne(
                "insert into orders (company_id, order_number, external_learner_id, buyer_type, buyer_document_type, buyer_document_number, buyer_name, buyer_email, buyer_ruc, buyer_legal_name,
                    billing_document_type, subtotal, igv_rate, igv_total, total, idempotency_key, source, status, expires_at, created_by, updated_by)
                 values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.18, ?, ?, ?, 'admin', 'pending', now() + interval '3 days', ?, ?) returning id",
                [$ctx, $number, $learner, $d['buyer_type'], $d['document_type'], $d['document_number'], $name, $d['email'], $company ? $d['ruc'] : null, $company ? $d['legal_name'] : null,
                    $company ? 'invoice' : 'receipt', $sub, $igv, $total, (string) Str::uuid(), (string) auth()->id(), (string) auth()->id()]
            )->id;
            DB::insert(
                'insert into order_items (company_id, order_id, course_offer_id, course_id, description, item_type, quantity, unit_price, subtotal, igv_amount, total, created_by, updated_by)
                 values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$ctx, $id, $o->id, $o->course_id, mb_substr($o->title, 0, 300), $company ? 'license' : 'individual', $qty, $unit, $sub, $igv, $total, (string) auth()->id(), (string) auth()->id()]
            );

            return $id;
        });

        return response()->json(['data' => ['id' => $id, 'order_number' => $number, 'total' => $total, 'igv' => $igv]], 201);
    }

    public function pay(Request $request, string $id): JsonResponse
    {
        $this->can('vta.orders.update');
        $order = Str::isUuid($id) ? DB::selectOne('select * from orders where id = ? and deleted_at is null', [$id]) : null;
        if (! $order) {
            throw ApiProblem::notFound('El pedido no existe o es de otra empresa.');
        }
        if ($order->status !== 'pending') {
            throw new ApiProblem(409, 'conflict', 'Pedido cerrado', 'El pedido ya no está pendiente de pago.');
        }
        $p = $request->validate([
            'method' => ['required', 'in:yape,plin,transfer,deposit,cash'],
            'reference' => ['required_unless:method,cash', 'nullable', 'string', 'min:3', 'max:60'],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
        ], ['method.required' => 'Elige cómo pagó.', 'reference.required_unless' => 'Escribe el número de operación del pago.']);
        $labels = ['yape' => 'Yape', 'plin' => 'Plin', 'transfer' => 'Transferencia', 'deposit' => 'Depósito', 'cash' => 'Efectivo'];
        $note = 'Pago manual: '.$labels[$p['method']].(! empty($p['reference']) ? ' · operación '.$p['reference'] : '').' · '.date('d/m/Y', strtotime($p['paid_on'] ?? 'today'))
            .' · registrado por '.(DB::selectOne('select full_name from users where id = ?', [(string) auth()->id()])?->full_name ?? '');
        if (! empty($p['reference']) && DB::selectOne("select 1 as ok from orders where status = 'paid' and notes ilike ? and deleted_at is null", ['%operación '.$p['reference'].'%'])) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Ese número de operación ya se registró en otro pedido.', ['errors' => ['reference' => ['Ese número de operación ya se registró en otro pedido.']]]);
        }
        $ctx = app(CompanyContext::class)->companyId;
        $result = DB::transaction(function () use ($order, $ctx, $note, $p) {
            DB::update("update orders set status = 'paid', paid_at = ?::date + (now()::time), notes = concat_ws(' ', notes, ?::text), updated_by = ? where id = ?",
                [$p['paid_on'] ?? date('Y-m-d'), $note, (string) auth()->id(), $order->id]);
            $out = [];
            foreach (DB::select('select i.*, o.access_days from order_items i join course_offers o on o.id = i.course_offer_id where i.order_id = ? and i.deleted_at is null', [$order->id]) as $it) {
                DB::update('update course_offers set seats_sold = seats_sold + ? where id = ?', [$it->quantity, $it->course_offer_id]);
                if ($it->item_type === 'license') {
                    $code = 'LIC-'.strtoupper(Str::random(8));
                    $lic = DB::selectOne(
                        "insert into course_licenses (company_id, order_item_id, course_id, course_offer_id, buyer_ruc, buyer_legal_name, buyer_contact_email, license_code, seats_purchased,
                            access_days, expires_at, status, created_by, updated_by)
                         values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, case when ?::int is null then null else now() + make_interval(days => ?::int) end, 'active', ?, ?) returning id",
                        [$ctx, $it->id, $it->course_id, $it->course_offer_id, $order->buyer_ruc, $order->buyer_legal_name, $order->buyer_email, $code, $it->quantity,
                            $it->access_days, $it->access_days, $it->access_days, (string) auth()->id(), (string) auth()->id()]
                    )->id;
                    DB::update('update order_items set course_license_id = ? where id = ?', [$lic, $it->id]);
                    $out[] = ['type' => 'license', 'code' => $code, 'seats' => (int) $it->quantity];
                } else {
                    $learner = $order->external_learner_id;
                    DB::insert(
                        "insert into course_enrollments (company_id, course_id, external_learner_id, source, order_item_id, enrolled_by, access_until, status, created_by, updated_by)
                         values (?, ?, ?, 'purchase', ?, ?, case when ?::int is null then null else now() + make_interval(days => ?::int) end, 'enrolled', ?, ?)",
                        [$ctx, $it->course_id, $learner, $it->id, (string) auth()->id(), $it->access_days, $it->access_days, (string) auth()->id(), (string) auth()->id()]
                    );
                    $out[] = ['type' => 'enrollment', 'learner' => $order->buyer_name];
                }
            }

            return $out;
        });

        return response()->json(['data' => ['status' => 'paid', 'manual' => true, 'delivered' => $result]]);
    }

    /** Participante externo por su documento (se reutiliza si ya existe). */
    private function learner(string $ctx, array $d): string
    {
        $hash = hash_hmac('sha256', strtoupper($d['document_number']), (string) config('app.key'));
        $found = DB::selectOne("select id from external_learners where document_number_hash = decode(?, 'hex') and deleted_at is null", [$hash]);
        if ($found) {
            return $found->id;
        }

        return DB::selectOne(
            "insert into external_learners (company_id, document_type, document_number, document_number_hash, document_last4, first_name, last_name, email,
                data_consent_at, consent_text_version, source, created_by, updated_by)
             values (?, ?, ?, decode(?, 'hex'), ?, ?, ?, ?, now(), 'v1', 'purchase', ?, ?) returning id",
            [$ctx, in_array($d['document_type'], ['DNI', 'CE', 'PTP', 'PASSPORT'], true) ? $d['document_type'] : 'OTHER', \Illuminate\Support\Facades\Crypt::encryptString($d['document_number']),
                $hash, substr($d['document_number'], -4), $d['first_name'], $d['last_name'], $d['email'], (string) auth()->id(), (string) auth()->id()]
        )->id;
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no gestiona ventas.');
        }
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}
