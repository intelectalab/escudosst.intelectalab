<?php

namespace App\Modules\SUS\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\SubscriptionNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Lado del cliente, con empresa activa (RLS por app.company_id):
 *
 *  GET  /me/subscription                     estado del plan para el aviso superior (por vencer, pago pendiente, solo lectura)
 *  GET  /me/notifications                    avisos de la persona en esta empresa (campana)
 *  POST /me/notifications/{id}/read          marcar uno como leído
 *  POST /me/notifications/read-all           marcar todos como leídos
 */
class MySubscriptionController extends Controller
{
    public function show(): JsonResponse
    {
        $context = app(CompanyContext::class);
        $s = DB::selectOne(
            "select s.status, s.billing_period, s.trial_ends_at, s.current_period_end, s.grace_until, p.code as plan_code, p.name as plan_name,
                    ((case when s.status = 'trialing' then s.trial_ends_at else s.current_period_end end) at time zone 'America/Lima')::date
                      - (now() at time zone 'America/Lima')::date as days_left
               from subscriptions s join plans p on p.id = s.plan_id
              where s.company_id = ? and s.deleted_at is null
              order by (s.status not in ('cancelled')) desc, s.started_at desc limit 1",
            [$context->companyId]
        );

        return response()->json(['data' => $s ? [
            'status' => $s->status,
            'plan_code' => $s->plan_code,
            'plan_name' => $s->plan_name,
            'trial_ends_at' => $s->trial_ends_at,
            'current_period_end' => $s->current_period_end,
            'grace_until' => $s->grace_until,
            'days_left' => $s->days_left !== null ? (int) $s->days_left : null,
            'reminder_days' => max(config('escudo.subscriptions.reminder_days')),
            // Los avisos de pago se muestran a quien administra o paga; el de solo lectura, a todos.
            'billing_contact' => in_array($context->roleCode, SubscriptionNotifier::RECIPIENT_ROLES, true),
        ] : null]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $context = app(CompanyContext::class);
        $rows = DB::select(
            'select id, event_code, title, body, link, priority, read_at, created_at
               from notifications
              where company_id = ? and recipient_user_id = ? and deleted_at is null and dismissed_at is null and coalesce(scheduled_for, created_at) <= now()
              order by coalesce(scheduled_for, created_at) desc limit 30',
            [$context->companyId, $request->user()->id]
        );

        return response()->json([
            'data' => $rows,
            'unread' => count(array_filter($rows, fn ($r) => $r->read_at === null)),
        ]);
    }

    public function read(Request $request, string $notification): Response
    {
        $n = DB::update(
            'update notifications set read_at = coalesce(read_at, now()) where id = ? and company_id = ? and recipient_user_id = ? and deleted_at is null',
            [$notification, app(CompanyContext::class)->companyId, $request->user()->id]
        );
        if ($n === 0) {
            throw ApiProblem::notFound('El aviso no existe.');
        }

        return response()->noContent();
    }

    public function readAll(Request $request): Response
    {
        DB::update(
            'update notifications set read_at = now() where company_id = ? and recipient_user_id = ? and read_at is null and deleted_at is null',
            [app(CompanyContext::class)->companyId, $request->user()->id]
        );

        return response()->noContent();
    }
}
