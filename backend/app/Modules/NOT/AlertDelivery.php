<?php

namespace App\Modules\NOT;

use App\Core\Support\Providers;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Entrega por correo y WhatsApp de los avisos que ya están en la campana (M-NOT, docs/09 §2 y §3). Corre cada minuto
 * (escudo:alerts-deliver) sobre los avisos recientes y deja cada intento en notification_deliveries (inalterable):
 *
 *  Correo inmediato (Laravel Mail, como el resumen diario): avisos críticos de cualquier módulo, accidente incapacitante
 *    (M-ACC §10), convocatoria a elecciones del comité (M-COM) y fallas de sincronización con Moodle (M-AUL). Respeta las
 *    preferencias de la persona (correo desactivado para todo o para ese módulo = «suppressed»). Los avisos del resumen
 *    diario (SstAlerts) ya salen en ese correo y no se repiten.
 *  WhatsApp (API Cloud de Meta, solo si está «en vivo»): avisos críticos (accidente mortal o incidente peligroso, plazo de
 *    notificación obligatoria), convocatoria y apertura de la votación, enlace personal para votar y respuesta al reportante
 *    de un acto o condición; el resto del resumen diario SST va en un solo mensaje por persona y día. Solo a quien aceptó
 *    WhatsApp (workers.whatsapp_opt_in, con celular) y no lo desactivó en sus preferencias (RN-NOT-04). Las variables
 *    no llevan datos médicos: el detalle se ve en la app con el enlace.
 * Sin WhatsApp en vivo no se intenta nada: los avisos quedan en la app (y en el correo) como siempre.
 */
final class AlertDelivery
{
    /** Ventana de avisos que se miran (los más viejos ya no se entregan por estos canales). */
    private const WINDOW = '6 hours';

    /** Avisos que siempre van por correo en el momento (además de los críticos). */
    private const EMAIL_EVENTS = ['com.election_called', 'aul.lms_sync_failed'];

    /** Avisos que van por WhatsApp uno a uno (además de los críticos). */
    private const WHATSAPP_EVENTS = ['com.election_called', 'com.election_voting_open', 'com.vote_link', 'act.report_closed'];

    /** Avisos del resumen diario (SstAlerts): por WhatsApp en un solo mensaje por persona y día. */
    public const DIGEST_EVENTS = ['acr.overdue', 'acr.due_soon', 'acc.notification', 'ipc.review', 'ins.late', 'emo.expiring', 'epp.expiring', 'fot.expiring',
        'cap.expiring', 'fot.blocked', 'act.untriaged', 'vul.adjustment_expiring', 'vul.adjustment_expired', 'des.return_to_work'];

    private ?WhatsAppCloud $wa = null;

    public function __construct(private AccountsService $accounts) {}

    /** @return array{email: int, whatsapp: int} envíos hechos (sin contar los omitidos) */
    public function run(): array
    {
        $companies = DB::connection('platform')->select(
            "select c.id from companies c join subscriptions s on s.company_id = c.id
              where c.deleted_at is null and c.status = 'active' and s.deleted_at is null and s.status in ('trialing','active','past_due','grace')"
        );
        $total = ['email' => 0, 'whatsapp' => 0];
        foreach ($companies as $c) {
            try {
                $n = $this->accounts->withCompany($c->id, fn (ConnectionInterface $db) => $this->forCompany($db, $c->id));
                $total['email'] += $n['email'];
                $total['whatsapp'] += $n['whatsapp'];
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $total;
    }

    /** @return array{email: int, whatsapp: int} */
    public function forCompany(ConnectionInterface $db, string $company): array
    {
        $this->wa = new WhatsAppCloud();
        $this->reportFeedback($db, $company);
        $front = rtrim((string) config('escudo.frontend_url'), '/');
        $name = $db->selectOne('select coalesce(trade_name, legal_name) as n from companies where id = ?', [$company])?->n ?? 'tu empresa';

        return ['email' => $this->emails($db, $company, $name, $front), 'whatsapp' => $this->wa->ready() ? $this->whatsapp($db, $company, $front) : 0];
    }

    // ---------------------------------------------------------------- Correo

    private function emails(ConnectionInterface $db, string $company, string $companyName, string $front): int
    {
        $rows = $db->select(
            "select n.id, n.event_code, n.recipient_user_id, n.title, n.body, n.link, n.priority, u.full_name, u.email
               from notifications n join users u on u.id = n.recipient_user_id and u.deleted_at is null
              where n.company_id = ? and n.deleted_at is null and n.created_at > now() - interval '".self::WINDOW."'
                and (n.priority = 'critical' or n.event_code = any(?::text[]) or (n.event_code = 'acc.event_registered' and n.priority = 'high'))
                and not exists (select 1 from notification_deliveries d where d.notification_id = n.id and d.channel = 'email')
              order by n.created_at limit 200",
            [$company, '{'.implode(',', self::EMAIL_EVENTS).'}']
        );
        $sent = 0;
        foreach ($rows as $n) {
            if ($this->optedOut($db, $company, $n->recipient_user_id, 'email', $n->event_code)) {
                $this->record($db, $company, $n->id, 'email', 'suppressed', null, 'preference_off', 'La persona desactivó estos avisos por correo.');

                continue;
            }
            try {
                Mail::to($n->email)->send(new AlertMail(explode(' ', (string) $n->full_name)[0], $companyName, $n->title, (string) $n->body,
                    $front.($n->link ?: '/inicio'), $n->priority));
                $this->record($db, $company, $n->id, 'email', 'sent', null);
                $sent++;
            } catch (Throwable $e) {
                $this->record($db, $company, $n->id, 'email', 'failed', null, 'send_error', mb_substr($e->getMessage(), 0, 300));
            }
        }

        return $sent;
    }

    // ---------------------------------------------------------------- WhatsApp

    private function whatsapp(ConnectionInterface $db, string $company, string $front): int
    {
        $sent = 0;
        $rows = $db->select(
            "select n.id, n.event_code, n.recipient_user_id, n.recipient_worker_id, n.subject_type, n.subject_id, n.title, n.body, n.link, n.priority
               from notifications n
              where n.company_id = ? and n.deleted_at is null and n.created_at > now() - interval '".self::WINDOW."'
                and (n.priority = 'critical' or n.event_code = any(?::text[]))
                and not exists (select 1 from notification_deliveries d where d.notification_id = n.id and d.channel = 'whatsapp')
              order by n.created_at limit 200",
            [$company, '{'.implode(',', self::WHATSAPP_EVENTS).'}']
        );
        foreach ($rows as $n) {
            $phone = $this->phoneFor($db, $company, $n->recipient_user_id, $n->recipient_worker_id, $n->event_code);
            if (! $phone) {
                $this->record($db, $company, $n->id, 'whatsapp', 'suppressed', null, 'no_opt_in', 'Sin aceptación de WhatsApp o sin celular.');

                continue;
            }
            [$template, $params] = $this->message($db, $company, $n, $front);
            if (! $template) {
                $this->record($db, $company, $n->id, 'whatsapp', 'suppressed', null, 'no_link', 'El enlace ya no está disponible.');

                continue;
            }
            $sent += $this->sendAndRecord($db, $company, [$n->id], $phone, $template, $params);
        }

        // Resumen diario: un mensaje por persona con la cantidad de avisos nuevos (el detalle, en la app).
        $digest = $db->select(
            "select n.recipient_user_id as user_id, array_agg(n.id::text) as ids, count(*) as total
               from notifications n
              where n.company_id = ? and n.deleted_at is null and n.recipient_user_id is not null and n.priority <> 'critical'
                and n.created_at > now() - interval '".self::WINDOW."' and n.event_code = any(?::text[])
                and not exists (select 1 from notification_deliveries d where d.notification_id = n.id and d.channel = 'whatsapp')
              group by n.recipient_user_id",
            [$company, '{'.implode(',', self::DIGEST_EVENTS).'}']
        );
        foreach ($digest as $g) {
            $ids = str_getcsv(trim((string) $g->ids, '{}'));
            $phone = $this->phoneFor($db, $company, $g->user_id, null, 'sst.digest');
            if (! $phone) {
                foreach ($ids as $id) {
                    $this->record($db, $company, $id, 'whatsapp', 'suppressed', null, 'no_opt_in', 'Sin aceptación de WhatsApp o sin celular.');
                }

                continue;
            }
            $first = explode(' ', (string) ($db->selectOne('select full_name from users where id = ?', [$g->user_id])?->full_name ?? ''))[0] ?: 'Hola';
            $url = $front.'/inicio';
            [$template, $params] = $this->wa->template('digest')
                ? [$this->wa->template('digest'), [$first, (string) $g->total, $url]]
                : [$this->wa->template('alert'), ["{$g->total} alerta(s) SST para hoy", 'Revisa los vencimientos y pendientes en EscudoSST.', $url]];
            $sent += $this->sendAndRecord($db, $company, $ids, $phone, (string) $template, $params);
        }

        return $sent;
    }

    /** @return array{0: ?string, 1: list<string>} plantilla y variables */
    private function message(ConnectionInterface $db, string $company, object $n, string $front): array
    {
        $alert = $this->wa->template('alert');
        if ($n->event_code === 'com.vote_link') {
            $r = $db->selectOne('select r.vote_token_enc, r.has_voted, e.title from election_roll r join elections e on e.id = r.election_id where r.id = ?', [$n->subject_id]);
            $token = null;
            try {
                $token = $r && ! $r->has_voted && $r->vote_token_enc ? Crypt::decryptString($r->vote_token_enc) : null;
            } catch (Throwable) {
                $token = null;
            }
            if (! $token) {
                return [null, []];
            }
            $url = "{$front}/votar/{$company}/{$token}";

            return $this->wa->template('vote') ? [$this->wa->template('vote'), [(string) $r->title, $url]] : [$alert, [$n->title, 'Vota con tu enlace personal (no lo compartas).', $url]];
        }
        $url = $front.($n->link ?: '/inicio');
        if ($n->event_code === 'act.report_closed' && $this->wa->template('report')) {
            $code = $db->selectOne('select code from substandard_reports where id = ?', [$n->subject_id])?->code ?? '';

            return [$this->wa->template('report'), [$code, $url]];
        }

        return [$alert, [$n->title, mb_substr((string) $n->body, 0, 300), $url]];
    }

    private function sendAndRecord(ConnectionInterface $db, string $company, array $ids, string $phone, string $template, array $params): int
    {
        $r = $this->wa->sendTemplate($phone, $template, $params);
        foreach ($ids as $id) {
            $this->record($db, $company, $id, 'whatsapp', $r['ok'] ? 'sent' : 'failed', $r['id'], $r['ok'] ? null : $r['code'], $r['error']);
        }

        return $r['ok'] ? 1 : 0;
    }

    /**
     * Celular de WhatsApp de quien recibe, solo si aceptó el canal (trabajador con whatsapp_opt_in y celular) y no lo
     * desactivó en sus preferencias. Para un usuario se usa el trabajador vinculado en esta empresa.
     */
    private function phoneFor(ConnectionInterface $db, string $company, ?string $user, ?string $worker, string $event): ?string
    {
        if ($user && $this->optedOut($db, $company, $user, 'whatsapp', $event)) {
            return null;
        }
        $w = $worker
            ? $db->selectOne('select phone_e164, whatsapp_opt_in from workers where id = ? and deleted_at is null', [$worker])
            : ($user ? $db->selectOne(
                "select w.phone_e164, w.whatsapp_opt_in from workers w
                  where w.deleted_at is null and w.status <> 'terminated' and (w.user_id = ?
                     or w.id in (select m.worker_id from memberships m where m.user_id = ? and m.company_id = ? and m.status = 'active' and m.deleted_at is null))
                  order by w.whatsapp_opt_in desc limit 1", [$user, $user, $company]) : null);
        if (! $w || ! $w->whatsapp_opt_in || ! $w->phone_e164) {
            return null;
        }
        try {
            $phone = Crypt::decryptString($w->phone_e164);
        } catch (Throwable) {
            $phone = $w->phone_e164;   // dato antiguo sin cifrar
        }

        return WhatsAppCloud::normalize($phone);
    }

    /** ¿Desactivó ese canal para todo («*») o para el módulo del aviso? */
    private function optedOut(ConnectionInterface $db, string $company, string $user, string $channel, string $event): bool
    {
        $module = strtolower(explode('.', $event)[0]);

        return (bool) $db->selectOne(
            "select 1 as ok from user_notification_prefs where company_id = ? and user_id = ? and channel = ? and not enabled and deleted_at is null
                and event_category in ('*', ?, ?)",
            [$company, $user, $channel, $module, $event]
        );
    }

    /**
     * Respuesta al reportante (M-ACT §10 «Reporte cerrado → reportante con contacto»): al cerrarse un reporte con
     * reportante identificado (trabajador o usuario), se le deja un aviso; el reparto lo hace whatsapp().
     */
    private function reportFeedback(ConnectionInterface $db, string $company): void
    {
        $rows = $db->select(
            "select id, code, reporter_user_id, reporter_worker_id from substandard_reports
              where deleted_at is null and status = 'closed' and closed_at > now() - interval '2 days' and not is_anonymous
                and (reporter_user_id is not null or reporter_worker_id is not null)"
        );
        foreach ($rows as $r) {
            $to = $r->reporter_user_id ?: $r->reporter_worker_id;
            $db->insert(
                "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, recipient_worker_id, title, body, link, priority, dedupe_key)
                 values (?, 'act.report_closed', 'substandard_reports', ?, ?, ?, ?, ?, ?, 'normal', ?) on conflict do nothing",
                [$company, $r->id, $r->reporter_user_id, $r->reporter_user_id ? null : $r->reporter_worker_id, "Tu reporte {$r->code} fue atendido",
                    'Gracias por reportar: el acto o la condición que informaste ya fue corregido y el reporte quedó cerrado.',
                    $r->reporter_user_id ? "/m/act?t=substandard_reports&open={$r->id}" : '/inicio', "act.report_closed:{$r->id}:{$to}"]
            );
        }
    }

    private function record(ConnectionInterface $db, string $company, string $notification, string $channel, string $status, ?string $messageId,
        ?string $errorCode = null, ?string $error = null): void
    {
        $provider = $channel === 'email' ? (string) config('mail.default') : 'meta';
        $db->insert(
            'insert into notification_deliveries (company_id, notification_id, channel, provider, provider_message_id, status, attempt, error_code, error_message, attempted_at)
             values (?, ?, ?, ?, ?, ?, 1, ?, ?, now())',
            [$company, $notification, $channel, $provider, $messageId, $status, $errorCode, $error !== null ? mb_substr($error, 0, 300) : null]
        );
    }

    /** Para quien emite credenciales de voto: deja el aviso que lleva el enlace personal por WhatsApp (si está en vivo). */
    public static function queueVoteLinks(string $company, array $rollIds): void
    {
        if (! $rollIds || ! (new WhatsAppCloud(Providers::get('whatsapp')))->ready()) {
            return;
        }
        foreach (DB::select(
            'select r.id, r.worker_id, r.vote_token_issued_at, e.title, e.id as election_id from election_roll r join elections e on e.id = r.election_id
              where r.id = any(?::uuid[]) and not r.excluded and not r.has_voted and r.vote_token_enc is not null',
            ['{'.implode(',', $rollIds).'}']) as $r) {
            DB::insert(
                "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_worker_id, title, body, link, priority, dedupe_key)
                 values (?, 'com.vote_link', 'election_roll', ?, ?, ?, ?, '/inicio', 'high', ?) on conflict do nothing",
                [$company, $r->id, $r->worker_id, 'Tu enlace para votar por el Comité SST', "Elección «{$r->title}»: vota con tu enlace personal.",
                    'com.vote_link:'.$r->id.':'.strtotime((string) $r->vote_token_issued_at).':'.$r->worker_id]
            );
        }
    }
}
