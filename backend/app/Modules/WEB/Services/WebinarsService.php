<?php

namespace App\Modules\WEB\Services;

use App\Modules\WEB\Mail\SiteRequestMail;
use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Webinars (SCR-WEB-78 y 79): inscripción con correo de confirmación (enlace de la reunión, .ics y enlace para cancelar)
 * y recordatorios 24 horas y 1 hora antes. El enlace de la reunión solo lo ven los inscritos, por correo.
 */
class WebinarsService
{
    private function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    private function site(): string
    {
        return rtrim((string) config('escudo.frontend_url'), '/');
    }

    /** @return array{result: string, token?: string} */
    public function register(string $slug, array $data, string $ipHash): array
    {
        $token = Str::random(48);
        $r = json_decode($this->db()->selectOne("select public_webinar_register(?, ?::jsonb, ?, decode(?, 'hex')) as r", [
            $slug, json_encode($data, JSON_UNESCAPED_UNICODE), $token, $ipHash,
        ])->r, true);
        if (($r['result'] ?? '') === 'registered' && empty($r['replay'])) {
            $this->confirmation($r['registration_id'], $token);
        }

        return ['result' => $r['result']];
    }

    private function confirmation(string $registrationId, string $token): void
    {
        $x = $this->registration($registrationId);
        $rows = [['Fecha', $this->when($x->starts_at)], ['Duración', "{$x->duration_minutes} minutos"], ['Ponente', $x->speaker_name]];
        if ($x->meeting_url) {
            $rows[] = ['Enlace para entrar', $x->meeting_url];
        }
        $note = ($x->meeting_url ? '' : "Te enviaremos el enlace para entrar antes del inicio.\n")
            ."Te recordaremos un día antes y una hora antes. El webinar puede grabarse: te avisaremos al inicio.\n"
            ."Si ya no puedes asistir, cancela tu inscripción aquí: {$this->site()}/webinars/gestionar/{$token}";
        $this->mail($x->email, $x->full_name, "Estás inscrito: {$x->title}", '¡Gracias por inscribirte! Estos son los datos del webinar.', $rows, $note, $x);
    }

    /** Recordatorios 24 h y 1 h antes (cada 5 minutos por el programador). */
    public function sendReminders(): int
    {
        $n = 0;
        foreach (['24h' => ['24 hours', 'mañana'], '1h' => ['1 hour', 'en una hora']] as $kind => [$window, $text]) {
            $due = $this->db()->select(
                "select r.id from webinar_registrations r join webinars w on w.id = r.webinar_id
                  where r.status = 'registered' and r.deleted_at is null and w.status in ('published', 'live') and w.deleted_at is null
                    and r.reminder_{$kind}_sent_at is null and w.starts_at > now() and w.starts_at <= now() + interval '{$window}'
                    and r.registered_at < w.starts_at - interval '{$window}' / 2"
            );
            foreach ($due as $d) {
                $x = $this->registration($d->id);
                $rows = [['Fecha', $this->when($x->starts_at)]];
                if ($x->meeting_url) {
                    $rows[] = ['Enlace para entrar', $x->meeting_url];
                }
                $this->mail($x->email, $x->full_name, "Recordatorio: {$x->title}", "Te recordamos que el webinar empieza {$text}.", $rows, null, $x);
                $this->db()->update("update webinar_registrations set reminder_{$kind}_sent_at = now() where id = ?", [$d->id]);
                $n++;
            }
        }

        return $n;
    }

    private function registration(string $id): object
    {
        return $this->db()->selectOne(
            'select r.id, l.email, l.full_name, w.id as webinar_id, w.title, w.starts_at, w.duration_minutes, w.speaker_name, w.meeting_url
               from webinar_registrations r join marketing_leads l on l.id = r.lead_id join webinars w on w.id = r.webinar_id where r.id = ?',
            [$id]
        );
    }

    private function mail(string $to, ?string $name, string $title, string $intro, array $rows, ?string $note, object $x): void
    {
        $fmt = fn ($t) => Carbon::parse($t)->utc()->format('Ymd\THis\Z');
        $esc = fn (string $s) => str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $s);
        $ics = implode("\r\n", array_filter([
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//EscudoSST//Webinar//ES', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'BEGIN:VEVENT', 'UID:webinar-'.$x->webinar_id.'@escudosst', 'DTSTAMP:'.$fmt(now()), 'DTSTART:'.$fmt($x->starts_at),
            'DTEND:'.$fmt(Carbon::parse($x->starts_at)->addMinutes($x->duration_minutes)), 'SUMMARY:'.$esc($x->title),
            $x->meeting_url ? 'URL:'.$x->meeting_url : null, 'END:VEVENT', 'END:VCALENDAR',
        ]))."\r\n";
        try {
            Mail::to($to)->send(new SiteRequestMail(explode(' ', trim((string) $name))[0] ?: 'hola', $title, $intro, $rows, $note,
                $x->meeting_url ? 'Entrar al webinar' : 'Ver el webinar', $x->meeting_url ?: "{$this->site()}/webinars", [$ics, 'webinar.ics']));
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar el correo del webinar', ['error' => $e->getMessage()]);
        }
    }

    private function when(string $t): string
    {
        return Carbon::parse($t)->timezone('America/Lima')->locale('es')->isoFormat('dddd D [de] MMMM, h:mm a').' (hora de Lima)';
    }
}
