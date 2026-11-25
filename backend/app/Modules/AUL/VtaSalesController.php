<?php

namespace App\Modules\AUL;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\Xlsx;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gestión de la venta de cursos (M-VTA), para la empresa vendedora:
 *  POST /vta/orders/{id}/refund               SCR-VTA-07: reembolso total o parcial {amount, reason, seats?}; el total no supera lo cobrado (RN-VTA-18).
 *                                             Total: matrículas canceladas, certificados anulados por «reembolso», cupos anulados y aforo liberado.
 *  GET  /vta/licenses/{id}/assignments        SCR-VTA-08/19: cupos de una empresa compradora con el avance de cada asignado
 *  POST /vta/licenses/{id}/assignments        asigna un cupo {document_type, document_number, first_name, last_name, email} y matricula (RN-VTA-15)
 *  POST /vta/license-assignments/{id}/release libera un cupo solo si el asignado no inició el curso {reason}
 *  GET  /vta/dashboard?from=&to=              SCR-VTA-01: ventas, pedidos, ticket promedio, cupos, cursos más vendidos y cupones
 *  GET  /vta/reports.xlsx?from=&to=           SCR-VTA-20: ventas por pedido con curso, canal, cupón, comprador, pago y reembolso
 *  POST /vta/campaigns {course_offer_id, audience, base_course_id?, send?}
 *                                             SCR-VTA-14: alcance (send=false) o envío por correo; solo participantes con consentimiento
 *                                             de publicidad y sin el curso comprado o aprobado (RN-VTA-22).
 * Los comprobantes y notas de crédito quedan pendientes del proveedor de comprobantes (por definir, RN-VTA-17).
 */
class VtaSalesController extends Controller
{
    public function refund(Request $request, string $id): JsonResponse
    {
        $this->can('vta.orders.update');
        $o = $this->order($id);
        if (! in_array($o->status, ['paid', 'partially_refunded'], true)) {
            throw new ApiProblem(409, 'conflict', 'Sin cobro', 'Solo se reembolsa un pedido pagado.');
        }
        $left = round((float) $o->total - (float) $o->refunded_total, 2);
        $d = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'min:5', 'max:300'],
            'seats' => ['nullable', 'integer', 'min:1'],
        ], ['amount.required' => 'Escribe el importe a devolver.', 'reason.required' => 'Escribe el motivo del reembolso.']);
        $amount = round((float) $d['amount'], 2);
        if ($amount > $left) {
            $this->fail('amount', 'El reembolso no puede superar lo cobrado: quedan S/ '.number_format($left, 2).' por devolver.');
        }
        $full = abs($amount - $left) < 0.005;
        $me = (string) auth()->id();
        $why = "Reembolso del pedido {$o->order_number}: ".trim($d['reason']);

        $out = DB::transaction(function () use ($o, $amount, $full, $me, $why, $d) {
            $out = ['enrollments_cancelled' => 0, 'certificates_voided' => 0, 'seats_removed' => 0];
            $items = DB::select('select * from order_items where order_id = ? and deleted_at is null', [$o->id]);
            if ($full) {
                foreach ($items as $it) {
                    $enr = array_column(DB::select(
                        "select e.id from course_enrollments e left join license_assignments a on a.id = e.license_assignment_id
                          where e.deleted_at is null and (e.order_item_id = ? or (a.course_license_id is not null and a.course_license_id = ?))", [$it->id, $it->course_license_id]), 'id');
                    if ($enr) {
                        $ids = '{'.implode(',', $enr).'}';
                        $out['certificates_voided'] += DB::update("update training_certificates set status = 'voided', voided_by = ?, voided_at = now(), void_reason = ?, updated_at = now()
                                                                    where course_enrollment_id = any(?::uuid[]) and status <> 'voided' and deleted_at is null", [$me, $why, $ids]);
                        $out['enrollments_cancelled'] += DB::update("update course_enrollments set status = 'cancelled', updated_at = now(), updated_by = ? where id = any(?::uuid[]) and status <> 'cancelled'", [$me, $ids]);
                    }
                    if ($it->course_license_id) {
                        DB::update("update license_assignments set status = 'revoked', revoked_at = now(), revoke_reason = ?, updated_at = now() where course_license_id = ? and status <> 'revoked'", [$why, $it->course_license_id]);
                        DB::update("update course_licenses set status = 'voided', updated_at = now() where id = ?", [$it->course_license_id]);
                    }
                    DB::update('update order_items set refunded_quantity = quantity, updated_at = now() where id = ?', [$it->id]);
                    DB::update('update course_offers set seats_sold = greatest(seats_sold - ?, 0), updated_at = now() where id = ?', [$it->quantity, $it->course_offer_id]);
                }
            } elseif (! empty($d['seats'])) {
                // Parcial con cupos: se descuentan cupos todavía no asignados de la licencia.
                $it = collect($items)->firstWhere('course_license_id', '!=', null);
                $lic = $it ? DB::selectOne('select * from course_licenses where id = ?', [$it->course_license_id]) : null;
                if (! $lic || (int) $lic->seats_purchased - (int) $lic->seats_used < (int) $d['seats'] || (int) $lic->seats_purchased - (int) $d['seats'] < 1) {
                    $this->fail('seats', 'Solo se pueden descontar cupos que aún no se asignaron.');
                }
                DB::update("update course_licenses set seats_purchased = seats_purchased - ?, status = case when seats_used >= seats_purchased - ? then 'exhausted' else status end, updated_at = now() where id = ?",
                    [$d['seats'], $d['seats'], $lic->id]);
                DB::update('update order_items set refunded_quantity = refunded_quantity + ?, updated_at = now() where id = ?', [$d['seats'], $it->id]);
                DB::update('update course_offers set seats_sold = greatest(seats_sold - ?, 0), updated_at = now() where id = ?', [$d['seats'], $it->course_offer_id]);
                $out['seats_removed'] = (int) $d['seats'];
            }
            DB::update("update orders set refunded_total = refunded_total + ?, status = ?, refunded_at = case when ? then now() else refunded_at end,
                               refund_reason = concat_ws(' | ', refund_reason, ?::text), updated_at = now(), updated_by = ? where id = ?",
                [$amount, $full ? 'refunded' : 'partially_refunded', $full ? 'true' : 'false', 'S/ '.number_format($amount, 2).': '.trim($d['reason']), $me, $o->id]);

            return $out;
        });
        DB::insert("insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes) values (?, ?, ?, 'user', 'update', 'VTA', 'orders', ?, ?::jsonb)",
            [$o->company_id, $me, app(CompanyContext::class)->roleCode, $o->id, json_encode(['refund' => $amount, 'full' => $full, 'reason' => $d['reason']] + $out)]);
        // M-VTA §10: «Reembolso confirmado» → comprador, por correo, al confirmarse.
        if ($o->buyer_email) {
            $seller = DB::selectOne('select coalesce(trade_name, legal_name) as name from companies where id = ?', [$o->company_id])?->name ?? 'la empresa';
            \Illuminate\Support\Facades\Mail::to($o->buyer_email)->send(new \App\Modules\WEB\Mail\SiteRequestMail(
                explode(' ', trim((string) $o->buyer_name))[0] ?: 'Hola',
                'Reembolso confirmado de tu pedido',
                "{$seller} confirmó el reembolso de tu pedido. El abono se verá en tu medio de pago según los plazos de tu banco o billetera.",
                [['Importe reembolsado', ($o->currency ?: 'PEN').' '.number_format($amount, 2)], ['Tipo', $full ? 'Total' : 'Parcial'], ['Motivo', trim($d['reason'])]]));
        }

        return response()->json(['data' => $out + ['status' => $full ? 'refunded' : 'partially_refunded', 'refunded' => $amount, 'credit_note' => 'pendiente del proveedor de comprobantes']]);
    }

    public function assignments(string $id): JsonResponse
    {
        $this->can('vta.license_assignments.read');
        $lic = $this->license($id);
        $rows = DB::select(
            "select a.id, a.status, a.assigned_at, a.revoked_at, a.revoke_reason, l.first_name, l.last_name, l.email, l.document_last4,
                    e.status as enrollment_status, e.progress_pct, e.final_score, e.passed, e.certificate_id
               from license_assignments a join external_learners l on l.id = a.external_learner_id
               left join course_enrollments e on e.id = a.enrollment_id
              where a.course_license_id = ? and a.deleted_at is null order by a.assigned_at desc", [$lic->id]);

        return response()->json(['data' => ['license' => ['id' => $lic->id, 'code' => $lic->license_code, 'buyer' => $lic->buyer_legal_name, 'seats' => (int) $lic->seats_purchased,
            'used' => (int) $lic->seats_used, 'expires_at' => $lic->expires_at, 'status' => $lic->status], 'assignments' => $rows]]);
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $this->can('vta.license_assignments.create');
        $lic = $this->license($id);
        $d = $request->validate(self::PERSON, ['*.required' => 'Completa este dato.', 'document_number.regex' => 'Escribe el documento sin espacios ni guiones.']);

        return response()->json(['data' => $this->assignOne($lic, $d)], 201);
    }

    private const PERSON = [
        'document_type' => ['required', 'in:DNI,CE,PTP,PASSPORT'],
        'document_number' => ['required', 'string', 'min:6', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
        'first_name' => ['required', 'string', 'max:80'],
        'last_name' => ['required', 'string', 'max:80'],
        'email' => ['required', 'email:rfc', 'max:160'],
    ];

    /** Plantilla para asignar cupos desde Excel (SCR-VTA-19). */
    public function assignTemplate(string $id): \Illuminate\Http\Response
    {
        $this->can('vta.license_assignments.create');
        $lic = $this->license($id);
        $rows = [['ASIGNACIÓN DE CUPOS · '.$lic->license_code], ['Tipo de documento: DNI, CE, PTP o PASSPORT. Una persona por fila.'], [],
            ['Tipo de documento', 'Número de documento', 'Nombres', 'Apellidos', 'Correo']];

        return response(\App\Core\Support\Xlsx::build('Cupos', $rows, 4), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="asignar-cupos.xlsx"',
        ]);
    }

    /**
     * SCR-VTA-19 «importar Excel»: revisa cada fila (datos válidos, persona repetida, cupos que quedan, RN-VTA-15) y con «apply»
     * asigna las válidas con las mismas reglas que la asignación de a uno (matrícula, cuenta y aviso).
     */
    public function assignImport(Request $request, string $id): JsonResponse
    {
        $this->can('vta.license_assignments.create');
        $lic = $this->license($id);
        $request->validate(['file' => ['required', 'file', 'max:5120']], ['file.required' => 'Elige el archivo de Excel.']);
        $file = $request->file('file');
        // docs/10 §6: extensión y tipo real del contenido (magic bytes), no solo el nombre del archivo.
        \App\Core\Support\UploadGuard::spreadsheet($file, 'file', 'Sube el archivo en Excel (.xlsx) o CSV.');
        try {
            $sheet = \App\Modules\TRB\SheetReader::read($file->getRealPath(), $file->getClientOriginalName());
        } catch (\Throwable) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa el archivo', 'No pudimos leer el archivo.', ['errors' => ['file' => ['No pudimos leer el archivo.']]]);
        }
        $titles = ['document_type' => 'tipo de documento', 'document_number' => 'número de documento', 'first_name' => 'nombres', 'last_name' => 'apellidos', 'email' => 'correo'];
        $map = null;
        $header = 0;
        foreach ($sheet as $n => $cells) {
            $c = array_map(fn ($x) => mb_strtolower(trim((string) $x)), $cells);
            if (in_array('número de documento', $c, true)) {
                $map = [];
                foreach ($titles as $k => $t) {
                    if (($i = array_search($t, $c, true)) !== false) {
                        $map[$k] = $i;
                    }
                }
                $header = $n;
                break;
            }
        }
        if (! $map || count($map) < 5) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa el archivo', 'No encontramos la fila de títulos (Tipo de documento, Número de documento, Nombres, Apellidos, Correo). Usa la plantilla.',
                ['errors' => ['file' => ['No encontramos la fila de títulos. Usa la plantilla.']]]);
        }
        $apply = $request->boolean('apply');
        $left = (int) $lic->seats_purchased - (int) $lic->seats_used;
        $seen = [];
        $out = [];
        $saved = 0;
        foreach ($sheet as $n => $cells) {
            if ($n <= $header) {
                continue;
            }
            $d = [];
            foreach ($map as $k => $i) {
                $d[$k] = trim((string) ($cells[$i] ?? ''));
            }
            if (implode('', $d) === '') {
                continue;
            }
            $d['document_type'] = strtoupper($d['document_type'] ?: 'DNI');
            $v = validator($d, self::PERSON, ['*.required' => 'Falta :attribute.', 'document_number.regex' => 'El documento no debe tener espacios ni guiones.'],
                ['document_type' => 'el tipo de documento', 'document_number' => 'el número de documento', 'first_name' => 'los nombres', 'last_name' => 'los apellidos', 'email' => 'el correo']);
            $err = $v->fails() ? array_values(array_map(fn ($m) => $m[0], $v->errors()->toArray())) : [];
            $key = $d['document_type'].':'.$d['document_number'];
            if (! $err && isset($seen[$key])) {
                $err[] = 'Esta persona ya está en otra fila del archivo.';
            }
            $seen[$key] = true;
            if (! $err && DB::selectOne("select 1 as ok from license_assignments a join external_learners l on l.id = a.external_learner_id
                                          where a.course_license_id = ? and a.status <> 'revoked' and a.deleted_at is null and l.document_number_hash = decode(?, 'hex')",
                [$lic->id, hash_hmac('sha256', strtoupper($d['document_number']), (string) config('app.key'))])) {
                $err[] = 'Esta persona ya tiene un cupo de esta compra.';
            }
            if (! $err && $left <= 0) {
                $err[] = "No quedan cupos (se compraron {$lic->seats_purchased}).";
            }
            $row = ['row' => $n + 1, 'name' => trim($d['first_name'].' '.$d['last_name']), 'document' => $d['document_type'].' ***'.substr($d['document_number'], -4), 'email' => $d['email'],
                'action' => $err ? 'error' : 'assign', 'errors' => $err];
            if (! $err) {
                $left--;
                if ($apply) {
                    try {
                        $this->assignOne($this->license($id), $d);
                        $saved++;
                        $row['action'] = 'assigned';
                    } catch (ApiProblem $e) {
                        $row['action'] = 'error';
                        $row['errors'][] = $e->getMessage() ?: 'No se pudo asignar.';
                        $left++;
                    }
                }
            }
            $out[] = $row;
        }

        return response()->json(['data' => ['applied' => $apply, 'saved' => $saved, 'seats_left' => max(0, $left), 'rows' => $out]]);
    }

    /** Asigna un cupo a una persona: participante, asignación, matrícula, contador de cupos, cuenta y aviso (RN-VTA-15). */
    private function assignOne(object $lic, array $d): array
    {
        if ($lic->status === 'voided' || $lic->status === 'expired' || ($lic->expires_at && strtotime($lic->expires_at) <= time())) {
            throw new ApiProblem(409, 'conflict', 'Cupos no disponibles', 'Estos cupos ya no se pueden asignar (vencidos o anulados).');
        }
        if ((int) $lic->seats_used >= (int) $lic->seats_purchased) {
            throw new ApiProblem(409, 'conflict', 'Sin cupos', "Ya se asignaron los {$lic->seats_purchased} cupos comprados.");
        }
        $me = (string) auth()->id();
        $res = DB::transaction(function () use ($lic, $d, $me) {
            $learner = VtaSales::learner($lic->company_id, $d, $me, $lic->buyer_ruc, $lic->buyer_legal_name);
            if (DB::selectOne("select 1 as ok from license_assignments where course_license_id = ? and external_learner_id = ? and status <> 'revoked' and deleted_at is null", [$lic->id, $learner])) {
                throw new ApiProblem(409, 'conflict', 'Ya asignado', 'Esa persona ya tiene un cupo de esta compra.');
            }
            $a = DB::selectOne("insert into license_assignments (company_id, course_license_id, external_learner_id, assigned_by, assigned_at, status, created_by, updated_by)
                                values (?, ?, ?, ?, now(), 'assigned', ?, ?) returning id", [$lic->company_id, $lic->id, $learner, $me, $me, $me])->id;
            $e = DB::selectOne("insert into course_enrollments (company_id, course_id, external_learner_id, source, license_assignment_id, enrolled_by, access_until, status, created_by, updated_by)
                                values (?, ?, ?, 'license', ?, ?, case when ?::int is null then null else now() + make_interval(days => ?::int) end, 'enrolled', ?, ?) returning id",
                [$lic->company_id, $lic->course_id, $learner, $a, $me, $lic->access_days, $lic->access_days, $me, $me])->id;
            DB::update('update license_assignments set enrollment_id = ? where id = ?', [$e, $a]);
            DB::update("update course_licenses set seats_used = seats_used + 1, status = case when seats_used + 1 >= seats_purchased then 'exhausted' else status end, updated_at = now() where id = ?", [$lic->id]);

            return ['assignment_id' => $a, 'enrollment_id' => $e];
        });
        try {
            VtaSales::ensureAccount(DB::selectOne('select external_learner_id from license_assignments where id = ?', [$res['assignment_id']])->external_learner_id);
            AulAlerts::enrolled([$res['enrollment_id']]);
        } catch (\Throwable $e) {
            report($e);
        }

        return $res;
    }

    public function release(Request $request, string $id): JsonResponse
    {
        $perms = Permissions::effective();
        if (! in_array('vta.license_assignments.revoke', $perms, true) && ! in_array('vta.license_assignments.update', $perms, true)) {
            throw ApiProblem::forbidden('Tu rol no libera cupos.');
        }
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']], ['reason.required' => 'Escribe el motivo.']);
        $a = Str::isUuid($id) ? DB::selectOne("select a.*, e.status as enr_status, e.started_at, e.progress_pct from license_assignments a left join course_enrollments e on e.id = a.enrollment_id
                                                 where a.id = ? and a.deleted_at is null", [$id]) : null;
        if (! $a) {
            throw ApiProblem::notFound('El cupo asignado no existe.');
        }
        $this->license($a->course_license_id);   // el representante solo libera cupos de su compra
        if ($a->status === 'revoked') {
            throw new ApiProblem(409, 'conflict', 'Ya liberado', 'Este cupo ya se liberó.');
        }
        if ($a->started_at || (float) $a->progress_pct > 0 || ($a->enr_status && $a->enr_status !== 'enrolled')) {
            throw new ApiProblem(409, 'conflict', 'Curso iniciado', 'No se puede liberar: la persona ya inició el curso.');
        }
        DB::transaction(function () use ($a, $d) {
            DB::update("update license_assignments set status = 'revoked', revoked_at = now(), revoke_reason = ?, updated_at = now(), updated_by = ? where id = ?", [$d['reason'], (string) auth()->id(), $a->id]);
            if ($a->enrollment_id) {
                DB::update("update course_enrollments set status = 'cancelled', updated_at = now() where id = ?", [$a->enrollment_id]);
            }
            DB::update("update course_licenses set seats_used = greatest(seats_used - 1, 0), status = case when status = 'exhausted' then 'active' else status end, updated_at = now() where id = ?", [$a->course_license_id]);
        });

        return response()->json(['data' => ['released' => true]]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $this->can('vta.orders.read');
        [$from, $to] = $this->period($request);
        $k = DB::selectOne(
            "select coalesce(sum(total - refunded_total) filter (where status in ('paid', 'partially_refunded', 'refunded')), 0) as sales,
                    count(*) filter (where status in ('paid', 'partially_refunded')) as paid,
                    count(*) filter (where status = 'pending' and (expires_at is null or expires_at > now())) as pending,
                    count(*) filter (where status in ('refunded', 'partially_refunded')) as refunded,
                    count(*) filter (where status = 'expired') as expired,
                    coalesce(sum(refunded_total), 0) as refunded_amount,
                    count(*) filter (where status in ('paid', 'partially_refunded') and coupon_id is not null) as with_coupon
               from orders where deleted_at is null and created_at::date between ? and ?", [$from, $to]);
        $paid = (int) $k->paid;
        $seats = DB::selectOne("select coalesce(sum(l.seats_purchased), 0) as sold, coalesce(sum(l.seats_used), 0) as used from course_licenses l
                                  join order_items i on i.id = l.order_item_id join orders o on o.id = i.order_id
                                 where l.deleted_at is null and l.status <> 'voided' and o.created_at::date between ? and ?", [$from, $to]);
        $top = DB::select("select i.description as title, sum(i.quantity - i.refunded_quantity) as units, sum(i.total) as amount from order_items i join orders o on o.id = i.order_id
                            where o.deleted_at is null and o.status in ('paid', 'partially_refunded') and o.created_at::date between ? and ?
                            group by i.description order by amount desc limit 5", [$from, $to]);
        $coupons = DB::select("select c.code, count(o.id) as uses, sum(o.discount_total) as discount from orders o join coupons c on c.id = o.coupon_id
                                where o.deleted_at is null and o.status in ('paid', 'partially_refunded') and o.created_at::date between ? and ? group by c.code order by uses desc limit 5", [$from, $to]);

        return response()->json(['data' => [
            'from' => $from, 'to' => $to, 'currency' => 'PEN',
            'sales' => round((float) $k->sales, 2), 'paid' => $paid, 'pending' => (int) $k->pending, 'refunded' => (int) $k->refunded, 'expired' => (int) $k->expired,
            'refunded_amount' => round((float) $k->refunded_amount, 2), 'average_ticket' => $paid ? round((float) $k->sales / $paid, 2) : 0,
            'seats_sold' => (int) $seats->sold, 'seats_assigned' => (int) $seats->used, 'top_courses' => $top, 'coupons' => $coupons,
        ]]);
    }

    public function report(Request $request): Response
    {
        $this->can('vta.orders.export');
        [$from, $to] = $this->period($request);
        $rows = DB::select(
            "select o.order_number, o.created_at, o.paid_at, o.status, o.source, o.manual_payment_method, o.buyer_type, coalesce(o.buyer_legal_name, o.buyer_name) as buyer,
                    o.buyer_ruc, c.code as coupon, o.subtotal, o.discount_total, o.igv_total, o.total, o.refunded_total,
                    string_agg(i.description || ' x' || i.quantity, '; ') as items
               from orders o left join coupons c on c.id = o.coupon_id left join order_items i on i.order_id = o.id and i.deleted_at is null
              where o.deleted_at is null and o.created_at::date between ? and ?
              group by o.id, c.code order by o.created_at", [$from, $to]);
        $st = ['pending' => 'Pendiente', 'paid' => 'Pagado', 'failed' => 'Rechazado', 'voided' => 'Anulado', 'expired' => 'Vencido', 'partially_refunded' => 'Reembolso parcial', 'refunded' => 'Reembolsado'];
        $ch = ['public_catalog' => 'En línea (catálogo)', 'admin' => 'Registrado por la empresa', 'api' => 'API'];
        $pm = ['yape' => 'Yape', 'plin' => 'Plin', 'transfer' => 'Transferencia', 'deposit' => 'Depósito', 'cash' => 'Efectivo', 'other' => 'Otro'];
        $out = [['Reporte de ventas de cursos'], ["Del {$from} al {$to}. Montos en soles. Sin datos de tarjeta."], [],
            ['Pedido', 'Fecha', 'Pagado', 'Estado', 'Canal', 'Medio de pago', 'Comprador', 'Tipo', 'RUC', 'Cursos', 'Cupón', 'Subtotal', 'Descuento', 'IGV', 'Total', 'Reembolsado']];
        foreach ($rows as $r) {
            $out[] = [$r->order_number, substr((string) $r->created_at, 0, 10), $r->paid_at ? substr((string) $r->paid_at, 0, 10) : '', $st[$r->status] ?? $r->status, $ch[$r->source] ?? $r->source,
                $r->manual_payment_method ? ($pm[$r->manual_payment_method] ?? $r->manual_payment_method) : '', $r->buyer, $r->buyer_type === 'company' ? 'Empresa' : 'Persona', $r->buyer_ruc ?? '',
                $r->items ?? '', $r->coupon ?? '', (float) $r->subtotal, (float) $r->discount_total, (float) $r->igv_total, (float) $r->total, (float) $r->refunded_total];
        }

        return response(Xlsx::build('Ventas', $out, 4), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="ventas-cursos-'.$from.'-'.$to.'.xlsx"',
        ]);
    }

    public function campaign(Request $request): JsonResponse
    {
        $this->can('aul.course_recommendations.create');
        $d = $request->validate([
            'course_offer_id' => ['required', 'uuid'],
            'audience' => ['required', 'in:passed_course,license_buyers'],
            'base_course_id' => ['required', 'uuid'],
            'message' => ['nullable', 'string', 'max:500'],
            'send' => ['boolean'],
        ], ['audience.in' => 'Elige a quién enviar.', 'base_course_id.required' => 'Elige el curso base.']);
        $offer = DB::selectOne("select id, course_id, title from course_offers where id = ? and status = 'published' and deleted_at is null", [$d['course_offer_id']]);
        if (! $offer) {
            $this->fail('course_offer_id', 'La oferta no existe o no está publicada.');
        }
        // Base: quienes aprobaron el curso base, o los asignados por empresas compradoras con cupos de ese curso.
        $base = $d['audience'] === 'passed_course'
            ? "select e.external_learner_id as id from course_enrollments e where e.course_id = ? and e.passed and e.deleted_at is null and e.external_learner_id is not null"
            : "select a.external_learner_id as id from license_assignments a join course_licenses l on l.id = a.course_license_id where l.course_id = ? and a.deleted_at is null";
        $all = DB::select(
            "select l.id, l.first_name, l.email, l.marketing_consent,
                    exists (select 1 from course_enrollments x where x.external_learner_id = l.id and x.course_id = ? and x.deleted_at is null and x.status <> 'cancelled') as has_course
               from external_learners l where l.deleted_at is null and l.status = 'active' and l.id in ({$base})", [$offer->course_id, $d['base_course_id']]);
        $target = array_values(array_filter($all, fn ($l) => $l->marketing_consent && ! $l->has_course && $l->email));
        $preview = ['base' => count($all), 'without_consent' => count(array_filter($all, fn ($l) => ! $l->marketing_consent)),
            'already_have' => count(array_filter($all, fn ($l) => $l->has_course)), 'reach' => count($target)];
        if (empty($d['send'])) {
            return response()->json(['data' => $preview]);
        }
        $me = (string) auth()->id();
        $company = app(CompanyContext::class)->companyId;
        $msg = trim((string) ($d['message'] ?? '')) ?: "Te puede interesar el curso «{$offer->title}».";
        $sent = 0;
        foreach ($target as $l) {
            $key = "vta-campaign:{$offer->id}:{$l->id}";
            $rec = DB::selectOne(
                "insert into course_recommendations (company_id, course_id, course_offer_id, external_learner_id, source, recommended_by, target_scope, reason_code, message, channel, status, sent_at, expires_at, dedupe_key, created_by)
                 values (?, ?, ?, ?, 'manual', ?, 'person', 'campaign', ?, 'email', 'sent', now(), now() + interval '60 days', ?, ?)
                 on conflict do nothing returning id", [$company, $offer->course_id, $offer->id, $l->id, $me, $msg, $key, $me]);
            if ($rec) {
                $sent += AulAlerts::learnerMail($l->email, (string) $l->first_name, 'Un curso para ti', $msg, $key);
            }
        }

        return response()->json(['data' => $preview + ['sent' => $sent]], 201);
    }

    private function period(Request $request): array
    {
        $d = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return [$d['from'] ?? now('America/Lima')->startOfMonth()->toDateString(), $d['to'] ?? now('America/Lima')->toDateString()];
    }

    private function order(string $id): object
    {
        $o = Str::isUuid($id) ? DB::selectOne('select * from orders where id = ? and deleted_at is null', [$id]) : null;

        return $o ?? throw ApiProblem::notFound('El pedido no existe o es de otra empresa.');
    }

    /** La empresa vendedora ve todos los cupos; el representante de la empresa compradora, solo los suyos (RN-VTA-16). */
    private function license(string $id): object
    {
        $l = Str::isUuid($id) ? DB::selectOne('select * from course_licenses where id = ? and deleted_at is null', [$id]) : null;
        if ($l && ! in_array('vta.orders.update', Permissions::effective(), true) && $l->manager_user_id !== (string) auth()->id()) {
            $l = null;
        }

        return $l ?? throw ApiProblem::notFound('Los cupos no existen o son de otra empresa.');
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
