<?php

namespace App\Modules\NOT;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\Xlsx;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Avisos (M-NOT):
 *  GET  /not/report.xlsx?from=&to=   §11: avisos emitidos frente a atendidos (leídos) por módulo, con su constancia de entrega por canal
 *  POST /public/mail-events          RN-NOT-07: rebotes que informa el proveedor de correo {email, message_id?, reason?}; exige el
 *                                    secreto compartido en la cabecera X-Mail-Webhook-Secret (MAIL_WEBHOOK_SECRET). Sin secreto
 *                                    configurado no se acepta nada.
 */
class NotReportsController extends Controller
{
    public function report(Request $request): Response
    {
        if (! in_array('not.notifications.read', Permissions::effective(), true) || ! in_array('aud.audit_logs.read', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no ve el reporte de avisos.');
        }
        $from = (string) ($request->query('from') ?: now('America/Lima')->startOfMonth()->toDateString());
        $to = (string) ($request->query('to') ?: now('America/Lima')->toDateString());
        $rows = DB::select(
            "select coalesce(m.name, split_part(n.event_code, '.', 1)) as module, count(*) as emitted, count(*) filter (where n.read_at is not null) as attended,
                    count(*) filter (where exists (select 1 from notification_deliveries d where d.notification_id = n.id and d.channel = 'email' and d.status in ('sent', 'delivered', 'read'))) as email_sent,
                    count(*) filter (where exists (select 1 from notification_deliveries d where d.notification_id = n.id and d.channel = 'whatsapp' and d.status in ('sent', 'delivered', 'read'))) as wa_sent,
                    count(*) filter (where exists (select 1 from notification_deliveries d where d.notification_id = n.id and d.status in ('failed', 'bounced'))) as failed
               from notifications n left join modules m on m.code = upper(split_part(n.event_code, '.', 1))
              where n.deleted_at is null and (n.created_at at time zone 'America/Lima')::date between ? and ?
              group by 1 order by 2 desc", [$from, $to]);
        $out = [['Avisos emitidos y atendidos por módulo'], ["Del {$from} al {$to}. Atendido = la persona abrió el aviso."], [],
            ['Módulo', 'Emitidos', 'Atendidos', '% atendidos', 'Enviados por correo', 'Enviados por WhatsApp', 'Con fallas o rebotes']];
        foreach ($rows as $r) {
            $out[] = [$r->module, (int) $r->emitted, (int) $r->attended, (int) $r->emitted ? round((int) $r->attended * 100 / (int) $r->emitted).' %' : '', (int) $r->email_sent, (int) $r->wa_sent, (int) $r->failed];
        }

        return response(Xlsx::build('Avisos', $out, 4), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"avisos-{$from}-{$to}.xlsx\"",
        ]);
    }

    public function mailEvent(Request $request): JsonResponse
    {
        $secret = (string) config('escudo.mail_webhook_secret');
        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Mail-Webhook-Secret'))) {
            throw ApiProblem::forbidden('No autorizado.');
        }
        $d = $request->validate(['email' => ['required', 'email'], 'message_id' => ['nullable', 'string', 'max:200'], 'reason' => ['nullable', 'string', 'max:300']]);

        return response()->json(['data' => ['recorded' => AlertDelivery::bounced($d['email'], $d['message_id'] ?? null, $d['reason'] ?? null)]]);
    }
}
