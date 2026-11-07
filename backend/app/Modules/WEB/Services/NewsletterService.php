<?php

namespace App\Modules\WEB\Services;

use App\Modules\WEB\Mail\NewsletterIssueMail;
use App\Modules\WEB\Mail\SiteRequestMail;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Boletín normativo y avisos de estado (RN-WEB-49 a 51 y 54).
 *  - Doble confirmación: en la base solo queda el hash del token; el enlace va por correo.
 *  - Baja de un clic: el token de baja se deriva del correo con la llave de la app, así cada envío puede llevar el
 *    enlace sin guardarlo en claro.
 *  - Envío por lotes con idempotencia por edición y suscriptor (newsletter_deliveries).
 */
class NewsletterService
{
    public const TOPICS = ['regulations' => 'Cambios en la normativa', 'sunafil' => 'SUNAFIL e inspecciones', 'sectors' => 'Novedades por sector'];

    public const STATUS_TOPIC = 'status';

    private const BATCH = 200;

    public function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    public function site(): string
    {
        return rtrim((string) config('escudo.frontend_url'), '/');
    }

    private function api(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1';
    }

    public function unsubscribeToken(string $email): string
    {
        return hash_hmac('sha256', 'newsletter-unsub|'.mb_strtolower(trim($email)), (string) config('app.key'));
    }

    /** Suscribe (boletín o avisos de estado) y manda el correo de confirmación si hace falta. */
    public function subscribe(string $email, array $topics, ?array $leadData, string $ipHash): void
    {
        $confirm = Str::random(48);
        $unsub = $this->unsubscribeToken($email);
        if ($leadData !== null) {
            $r = $this->db()->selectOne(
                "select public_newsletter_subscribe(?::jsonb, ?, ?, decode(?, 'hex')) as r",
                [json_encode($leadData + ['email' => $email, 'topics' => $topics], JSON_UNESCAPED_UNICODE), $confirm, $unsub, $ipHash]
            );
        } else {
            $r = $this->db()->selectOne("select public_status_subscribe(?, ?, ?, true, decode(?, 'hex')) as r", [$email, $confirm, $unsub, $ipHash]);
        }
        $res = json_decode($r->r, true);
        if (! ($res['send_confirmation'] ?? false)) {
            return;
        }
        $hours = (int) ($this->db()->selectOne('select newsletter_confirm_token_validity as h from site_settings where singleton')->h ?? 72);
        $isStatus = $topics === [self::STATUS_TOPIC];
        $this->mail($email, new SiteRequestMail(
            'Hola', $isStatus ? 'Confirma los avisos de estado del servicio' : 'Confirma tu suscripción al boletín',
            $isStatus
                ? 'Pulsa el botón para empezar a recibir avisos cuando EscudoSST tenga una interrupción o un mantenimiento programado.'
                : 'Pulsa el botón para empezar a recibir el boletín normativo SST de EscudoSST.',
            [], "El enlace vence en {$hours} horas. Si no fuiste tú, ignora este correo: no te suscribiremos.",
            'Confirmar mi correo', $this->site().'/boletin/confirmar/'.$confirm
        ));
    }

    /** Envía las ediciones programadas que ya tocan. Devuelve cuántos correos salieron. */
    public function sendDue(?string $onlyIssueId = null): int
    {
        $issues = $this->db()->select(
            "select * from newsletter_issues where status = 'scheduled' and scheduled_at <= now() and deleted_at is null".($onlyIssueId ? ' and id = ?' : '').' order by scheduled_at',
            $onlyIssueId ? [$onlyIssueId] : []
        );
        $total = 0;
        foreach ($issues as $issue) {
            $total += $this->sendIssue($issue);
        }

        return $total;
    }

    private function sendIssue(object $issue): int
    {
        $blocks = NewsletterIssueMail::blocks($issue->body);
        $sent = 0;
        do {
            $batch = $this->db()->select(
                "select n.id, n.email from newsletter_subscribers n
                  where n.status = 'confirmed' and n.deleted_at is null
                    and (jsonb_array_length(?::jsonb) = 0 or exists (select 1 from jsonb_array_elements_text(?::jsonb) t where n.topics_json @> jsonb_build_array(t)))
                    and not exists (select 1 from newsletter_deliveries d where d.issue_id = ? and d.subscriber_id = n.id)
                  order by n.id limit ".self::BATCH,
                [$issue->topics_json, $issue->topics_json, $issue->id]
            );
            foreach ($batch as $s) {
                $error = null;
                try {
                    Mail::to($s->email)->send($this->issueMail($issue, $blocks, $s->email, false));
                    $sent++;
                } catch (\Throwable $e) {
                    $error = mb_substr($e->getMessage(), 0, 500);
                }
                $this->db()->insert(
                    'insert into newsletter_deliveries (issue_id, subscriber_id, status, error) values (?, ?, ?, ?) on conflict do nothing',
                    [$issue->id, $s->id, $error ? 'failed' : 'sent', $error]
                );
            }
        } while (count($batch) === self::BATCH);

        $ok = (int) $this->db()->selectOne("select count(*) as n from newsletter_deliveries where issue_id = ? and status = 'sent'", [$issue->id])->n;
        $this->db()->update('update newsletter_issues set recipients_count = ? where id = ?', [$ok, $issue->id]);
        $this->db()->selectOne('select web_newsletter_issue_sent(?, null)', [$issue->id]);

        return $sent;
    }

    public function issueMail(object $issue, array $blocks, string $email, bool $test): NewsletterIssueMail
    {
        $token = $this->unsubscribeToken($email);

        return new NewsletterIssueMail(
            $issue->subject, $issue->preheader, $blocks,
            $this->site().'/boletin/'.$issue->slug,
            $this->site().'/boletin/baja/'.$token.'?e='.$issue->id,
            $this->api().'/public/newsletter/unsubscribe/'.$token.'?e='.$issue->id,
            $test,
        );
    }

    public function mail(string $to, $mailable): bool
    {
        try {
            Mail::to($to)->send($mailable);

            return true;
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar el correo del boletín', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
