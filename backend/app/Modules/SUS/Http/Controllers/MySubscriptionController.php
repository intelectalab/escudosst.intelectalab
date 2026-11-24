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

    /**
     * GET /me/subscription/detail — SCR-SUS-05 «Mi suscripción» (administrador de la cuenta): plan, complementos y cada módulo
     * con su estado; los precios se muestran «Por definir» (criterio de aceptación 2 de M-SUS).
     */
    public function detail(): JsonResponse
    {
        $this->billingOnly();
        $company = app(CompanyContext::class)->companyId;
        $s = DB::selectOne("select s.id, s.status, s.billing_period, s.started_at, s.trial_ends_at, s.current_period_end, p.code, p.name, p.max_workers, p.max_sites, p.max_companies
                              from subscriptions s join plans p on p.id = s.plan_id where s.company_id = ? and s.deleted_at is null
                             order by (s.status not in ('cancelled')) desc, s.started_at desc limit 1", [$company]);
        $addons = $s ? DB::select("select a.code, a.name, sa.status from subscription_addons sa join addons a on a.id = sa.addon_id
                                    where sa.subscription_id = ? and sa.deleted_at is null and (sa.company_id is null or sa.company_id = ?) order by a.name", [$s->id, $company]) : [];
        $modules = DB::select("select m.code, m.name, m.group_number, m.is_addon, coalesce(f.mode, 'locked') as mode, coalesce(f.company_enabled, true) as company_enabled
                                 from modules m left join feature_flags f on f.module_code = m.code and f.deleted_at is null and f.company_id = ?
                                where m.deleted_at is null order by m.group_number, m.number", [$company]);

        return response()->json(['data' => [
            'plan' => $s ? ['code' => $s->code, 'name' => $s->name, 'status' => $s->status, 'billing_period' => $s->billing_period, 'started_at' => $s->started_at,
                'trial_ends_at' => $s->trial_ends_at, 'current_period_end' => $s->current_period_end, 'limits' => ['workers' => $s->max_workers, 'sites' => $s->max_sites, 'companies' => $s->max_companies],
                'price' => 'Por definir'] : null,
            // docs/03 §5: uso de cada límite del plan frente a su tope (null = sin límite; almacenamiento en GB).
            'usage' => $s ? $this->usage() : null,
            'addons' => $addons, 'modules' => $modules,
        ]]);
    }

    /** POST /me/subscription/upgrade {module_code?, message?} — «Solicitar mejora»: llega como consulta al equipo de EscudoSST. */
    public function upgrade(Request $request): JsonResponse
    {
        $this->billingOnly();
        $d = $request->validate(['module_code' => ['nullable', 'string', 'size:3'], 'message' => ['nullable', 'string', 'max:1000']]);
        $company = app(CompanyContext::class)->companyId;
        $mod = ! empty($d['module_code']) ? DB::selectOne('select code, name from modules where code = ? and deleted_at is null', [strtoupper($d['module_code'])]) : null;
        $subject = mb_substr('Solicitud de mejora'.($mod ? ": {$mod->code} · {$mod->name}" : ' del plan'), 0, 120);
        $msg = trim((string) ($d['message'] ?? '')) ?: ($mod ? "La empresa solicita activar el módulo {$mod->name}." : 'La empresa solicita mejorar su plan.');
        $db = DB::connection('platform');
        $n = (int) $db->selectOne('select count(*) as n from support_tickets')->n + 1;
        $code = 'CON-'.now('America/Lima')->format('Y').'-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        $row = $db->selectOne("insert into support_tickets (code, company_id, user_id, topic, subject, message, page) values (?, ?, ?, 'billing', ?, ?, '/cuenta/suscripcion') returning id, code, status",
            [$code, $company, (string) auth()->id(), $subject, mb_substr($msg, 0, 4000)]);

        return response()->json(['data' => $row], 201);
    }

    /** @return array<string, array{used: int|float, limit: ?int}> */
    private function usage(): array
    {
        $u = \App\Modules\SUS\Services\PlanLimits::summary();
        $u['storage']['used'] = round($u['storage']['used'] / 1073741824, 2);

        return $u;
    }

    private function billingOnly(): void
    {
        if (! in_array(app(CompanyContext::class)->roleCode, SubscriptionNotifier::RECIPIENT_ROLES, true)) {
            throw \App\Core\Http\ApiProblem::forbidden('«Mi suscripción» la ve quien administra la cuenta.');
        }
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
