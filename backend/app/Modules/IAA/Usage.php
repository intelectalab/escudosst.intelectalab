<?php

namespace App\Modules\IAA;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Modules\NOT\AlertMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Cupo mensual del Asistente IA (M-IAA RN-IAA-06 y §10): el plan de la empresa fija «ai_requests_month» (vacío = sin
 * límite). Al llegar al cupo no se atienden más solicitudes hasta el mes siguiente; al pasar el 90 % se avisa una vez
 * por mes al administrador de la cuenta (campana y correo).
 */
final class Usage
{
    /** @return array{used: int, limit: ?int} */
    public static function month(): array
    {
        $used = (int) DB::selectOne("select count(*) as n from ai_requests where created_at >= date_trunc('month', now() at time zone 'America/Lima') and deleted_at is null")->n;
        $limit = DB::selectOne("select p.ai_requests_month as l from subscriptions s join plans p on p.id = s.plan_id
                                 where s.company_id = ? and s.deleted_at is null and s.status not in ('cancelled', 'trial_expired') order by s.started_at desc limit 1",
            [app(CompanyContext::class)->companyId])?->l;

        return ['used' => $used, 'limit' => $limit === null ? null : (int) $limit];
    }

    /** Antes de atender una solicitud: rechaza si ya se usó el cupo del mes; avisa si con esta se pasa el 90 %. */
    public static function guard(): void
    {
        ['used' => $used, 'limit' => $limit] = self::month();
        if ($limit === null) {
            return;
        }
        if ($used >= $limit) {
            throw new ApiProblem(429, 'ai_limit_reached', 'Cupo del mes agotado',
                "Tu empresa ya usó las {$limit} solicitudes del Asistente IA de este mes. El cupo se renueva el primer día del mes siguiente.");
        }
        if ($used + 1 >= (int) ceil($limit * 0.9)) {
            self::warn($used + 1, $limit);
        }
    }

    private static function warn(int $used, int $limit): void
    {
        $ctx = app(CompanyContext::class);
        $key = 'iaa.limit_90:'.date('Y-m');
        $admins = DB::select(
            "select u.id, u.email, u.full_name from memberships m join roles r on r.id = m.role_id join users u on u.id = m.user_id
              where m.company_id = ? and m.status = 'active' and m.deleted_at is null and coalesce(r.base_role_code, r.code) = 'account_admin'", [$ctx->companyId]);
        foreach ($admins as $a) {
            if (DB::selectOne('select 1 as ok from notifications where company_id = ? and dedupe_key = ?', [$ctx->companyId, $key.':'.$a->id])) {
                continue;
            }
            $title = 'Asistente IA: 90 % del cupo del mes';
            $body = "Tu empresa lleva {$used} de {$limit} solicitudes del Asistente IA este mes.";
            DB::insert("insert into notifications (company_id, event_code, subject_type, recipient_user_id, title, body, link, priority, dedupe_key)
                        values (?, 'iaa.limit_90', 'IAA', ?, ?, ?, '/m/iaa', 'normal', ?)", [$ctx->companyId, $a->id, $title, $body, $key.':'.$a->id]);
            try {
                Mail::to($a->email)->send(new AlertMail(explode(' ', (string) $a->full_name)[0], $ctx->companyName, $title, $body,
                    rtrim((string) config('escudo.frontend_url'), '/').'/m/iaa', 'normal'));
            } catch (Throwable) {
                // el aviso queda en la campana aunque el correo falle
            }
        }
    }
}
