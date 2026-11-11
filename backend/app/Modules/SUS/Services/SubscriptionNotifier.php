<?php

namespace App\Modules\SUS\Services;

use App\Core\Support\SystemPulse;
use App\Modules\SUS\Mail\SubscriptionNoticeMail;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisos de la suscripción al cliente, por dos canales:
 *  - En el sistema: una fila en notifications por cada destinatario (campana del menú superior).
 *  - Por correo.
 *
 * Destinatarios: administradores de cuenta y Jefes SST activos de la empresa. Por correo van los
 * administradores (o, si no hay, los Jefes SST) y el correo de facturación de la cuenta.
 * Un correo que falla no detiene el proceso: queda en el registro de la aplicación.
 */
class SubscriptionNotifier
{
    public const RECIPIENT_ROLES = ['account_admin', 'sst_manager'];

    public function __construct(private readonly AccountsService $accounts) {}

    /**
     * @param  object  $sub  fila de subscriptions con company_id, company_name, plan_name, billing_email
     * @param  string  $kind  reminder | trial_reminder | past_due | trial_expired | blocked | paid
     * @return int destinatarios avisados en el sistema
     */
    public function notify(object $sub, string $kind, array $data, string $dedupeKey): int
    {
        [$title, $body, $tone, $priority] = $this->message($sub, $kind, $data);
        $link = '/inicio';

        $recipients = $this->accounts->withCompany($sub->company_id, fn (ConnectionInterface $db) => $db->select(
            "select u.id, u.full_name, u.email, coalesce(r.base_role_code, r.code) as role
               from memberships m join roles r on r.id = m.role_id join users u on u.id = m.user_id
              where m.company_id = ? and m.status = 'active' and m.deleted_at is null and u.deleted_at is null
                and coalesce(r.base_role_code, r.code) = any(?::text[])",
            [$sub->company_id, '{'.implode(',', self::RECIPIENT_ROLES).'}']
        ));

        // En el sistema (una vez por persona y aviso).
        $this->accounts->withCompany($sub->company_id, function (ConnectionInterface $db) use ($sub, $recipients, $kind, $title, $body, $link, $priority, $dedupeKey, $data) {
            foreach ($recipients as $r) {
                $exists = $db->selectOne('select 1 from notifications where company_id = ? and recipient_user_id = ? and dedupe_key = ? and deleted_at is null',
                    [$sub->company_id, $r->id, $dedupeKey]);
                if (! $exists) {
                    $db->insert(
                        "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, due_at, dedupe_key)
                         values (?, ?, 'subscriptions', ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$sub->company_id, 'subscription.'.$kind, $sub->id, $r->id, $title, $body, $link, $priority, $data['end'] ?? null, $dedupeKey]
                    );
                }
            }
        });

        // Por correo.
        $admins = array_filter($recipients, fn ($r) => $r->role === 'account_admin');
        $mailTo = $admins ?: $recipients;
        $emails = [];
        foreach ($mailTo as $r) {
            $emails[strtolower($r->email)] = $r->full_name;
        }
        if (! empty($sub->billing_email) && ! isset($emails[strtolower($sub->billing_email)])) {
            $emails[strtolower($sub->billing_email)] = $sub->company_name;
        }
        $url = rtrim(config('escudo.frontend_url'), '/').'/login';
        foreach ($emails as $email => $name) {
            try {
                Mail::to($email)->send(new SubscriptionNoticeMail($name, $title, $body, $tone, $url));
            } catch (Throwable $e) {
                Log::warning('No se pudo enviar el aviso de suscripción', ['to' => $email, 'kind' => $kind, 'error' => $e->getMessage()]);
                SystemPulse::mailFailed($e->getMessage());
            }
        }

        return count($recipients);
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} título, cuerpo, tono del correo, prioridad */
    private function message(object $sub, string $kind, array $data): array
    {
        $company = $sub->company_name;
        $end = isset($data['end']) ? $this->date($data['end']) : '';
        $days = $data['days'] ?? null;

        return match ($kind) {
            'reminder' => [
                $days === 0 ? "Tu plan {$sub->plan_name} vence hoy" : "Tu plan {$sub->plan_name} vence en {$days} ".($days === 1 ? 'día' : 'días'),
                "El periodo de {$company} termina el {$end}. Registra tu pago para seguir usando EscudoSST sin interrupciones. Si ya pagaste, envía el comprobante al equipo de EscudoSST.",
                $days === 0 ? 'danger' : 'warning',
                $days === 0 ? 'high' : 'normal',
            ],
            'trial_reminder' => [
                $days === 0 ? 'Tu prueba gratuita termina hoy' : "Tu prueba gratuita termina en {$days} ".($days === 1 ? 'día' : 'días'),
                "La prueba de {$company} termina el {$end}. Contrata un plan para seguir registrando; tus datos se conservan.",
                $days === 0 ? 'danger' : 'info',
                $days === 0 ? 'high' : 'normal',
            ],
            'past_due' => [
                'Tu suscripción venció: pago pendiente',
                "El periodo de {$company} terminó el {$end}. Puedes seguir trabajando hasta el ".$this->date($data['grace_until']).'; si para entonces no se registra el pago, la cuenta quedará en solo lectura.',
                'danger',
                'high',
            ],
            'trial_expired' => [
                'Terminó tu prueba gratuita',
                "La prueba de {$company} terminó el {$end}. La cuenta quedó en solo lectura: puedes ver y descargar tus registros. Contrata un plan para volver a registrar.",
                'danger',
                'high',
            ],
            'blocked' => [
                'Tu cuenta quedó en solo lectura',
                "No se registró el pago de {$company} dentro del plazo. Puedes ver y descargar tus registros, pero no crear ni editar. Se desbloquea al momento en que se registre el pago.",
                'danger',
                'critical',
            ],
            'paid' => [
                'Pago registrado: cuenta al día',
                "Registramos el pago de {$company}. Tu plan {$sub->plan_name} está activo hasta el {$end}. ¡Gracias!",
                'success',
                'normal',
            ],
        };
    }

    private function date(string $value): string
    {
        return Carbon::parse($value)->timezone('America/Lima')->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
    }
}
