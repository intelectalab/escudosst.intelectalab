<?php

namespace App\Modules\AUL;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Portal del participante externo (M-VTA SCR-VTA-18/19), con su propia cuenta en la empresa vendedora:
 *  GET /vta/portal            sus datos y consentimientos, certificados, pedidos y, si representa a una empresa compradora, sus cupos
 *  PUT /vta/portal/consents   {marketing_consent}: el consentimiento de publicidad es opcional y revocable (RN-VTA-19)
 * «Mis cursos» es el aula virtual (las matrículas quedan ligadas a su cuenta). Los cupos se asignan y liberan con
 * /vta/licenses/{id}/assignments y /vta/license-assignments/{id}/release, solo los de su compra (RN-VTA-16).
 */
class VtaPortalController extends Controller
{
    public function show(): JsonResponse
    {
        $me = (string) auth()->id();
        $l = $this->learner();
        $email = DB::connection('platform')->selectOne('select email from users where id = ?', [$me])?->email;
        $certs = $l ? DB::select(
            "select id, certificate_code, verification_code, course_title, issued_on, expires_on, hours, status from training_certificates
              where external_learner_id = ? and deleted_at is null and status in ('validated', 'expired') order by issued_on desc", [$l->id]) : [];
        $orders = DB::select(
            "select o.id, o.order_number, o.created_at, o.paid_at, o.status, o.total, o.refunded_total, o.discount_total, o.buyer_type,
                    string_agg(i.description || case when i.quantity > 1 then ' x' || i.quantity else '' end, '; ') as items
               from orders o left join order_items i on i.order_id = o.id and i.deleted_at is null
              where o.deleted_at is null and (o.external_learner_id = ? or lower(o.buyer_email) = lower(?))
              group by o.id order by o.created_at desc limit 50", [$l?->id, (string) $email]);
        $licenses = DB::select(
            "select l.id, l.license_code, c.title, l.seats_purchased, l.seats_used, l.expires_at, l.status
               from course_licenses l join courses c on c.id = l.course_id where l.manager_user_id = ? and l.deleted_at is null order by l.created_at desc", [$me]);

        return response()->json(['data' => [
            'learner' => $l ? ['first_name' => $l->first_name, 'last_name' => $l->last_name, 'email' => $l->email, 'document' => '***'.$l->document_last4,
                'marketing_consent' => (bool) $l->marketing_consent, 'data_consent_at' => $l->data_consent_at] : null,
            'certificates' => $certs, 'orders' => $orders, 'licenses' => $licenses,
        ]]);
    }

    public function consents(Request $request): JsonResponse
    {
        $d = $request->validate(['marketing_consent' => ['required', 'boolean']]);
        $l = $this->learner() ?? throw ApiProblem::notFound('Tu cuenta no tiene datos de participante.');
        DB::update('update external_learners set marketing_consent = ?, marketing_consent_at = case when ? then now() else null end, updated_at = now() where id = ?',
            [$d['marketing_consent'] ? 'true' : 'false', $d['marketing_consent'] ? 'true' : 'false', $l->id]);

        return response()->json(['data' => ['marketing_consent' => (bool) $d['marketing_consent']]]);
    }

    private function learner(): ?object
    {
        return DB::selectOne('select * from external_learners where user_id = ? and deleted_at is null', [(string) auth()->id()]);
    }
}
