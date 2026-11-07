<?php

namespace App\Modules\WEB\Services;

use App\Modules\WEB\Mail\SiteRequestMail;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Agenda de demos (RN-WEB-68 a 73): correos al visitante y al equipo, archivo de calendario (.ics) y recordatorios.
 * Los anfitriones se presentan como «asesor comercial» o «especialista SST», nunca como colegiados (RN-WEB-63).
 * La duración siempre sale de demo_booking_settings (RN-WEB-59).
 */
class DemoService
{
    public const PROVIDERS = ['meet' => 'Google Meet', 'teams' => 'Microsoft Teams', 'phone' => 'Llamada telefónica'];

    public function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    public function site(): string
    {
        return rtrim((string) config('escudo.frontend_url'), '/');
    }

    public function settings(): object
    {
        return $this->db()->selectOne('select * from demo_booking_settings where singleton and deleted_at is null');
    }

    /** Datos de una reunión con su solicitud y anfitrión. */
    public function meeting(string $id): ?object
    {
        $m = $this->db()->selectOne(
            'select m.*, r.full_name, r.email, r.company_name, r.phone, r.ruc, r.headcount, r.economic_sector, r.message, r.marketing_consent,
                    r.status as request_status, h.display_name as host_name, h.email as host_email, h.color as host_color
               from demo_meetings m join demo_requests r on r.id = m.demo_request_id left join demo_hosts h on h.id = m.host_id
              where m.id = ? and m.deleted_at is null', [$id]
        );
        if ($m) {
            unset($m->manage_token_hash);   // solo el visitante tiene el enlace; el hash nunca sale
        }

        return $m;
    }

    public function when(string $iso): string
    {
        return Carbon::parse($iso)->timezone('America/Lima')->locale('es')->isoFormat('dddd D [de] MMMM, h:mm a').' (hora de Lima)';
    }

    /** Archivo .ics para «Agregar a mi calendario». Sin datos de terceros. */
    public function ics(object $m): string
    {
        $fmt = fn ($t) => Carbon::parse($t)->utc()->format('Ymd\THis\Z');
        $esc = fn (string $s) => str_replace(["\\", ';', ',', "\n"], ["\\\\", '\;', '\,', '\n'], $s);
        $desc = 'Demo de EscudoSST con '.($m->host_name ?? 'nuestro equipo').'.'
            .($m->meeting_url ? ' Enlace: '.$m->meeting_url : ($m->meeting_provider === 'phone' ? ' Te llamaremos al número que dejaste.' : ' Te enviaremos el enlace al confirmar.'));

        return implode("\r\n", array_filter([
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//EscudoSST//Demo//ES', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'BEGIN:VEVENT', 'UID:demo-'.$m->id.'@escudosst', 'DTSTAMP:'.$fmt(now()), 'DTSTART:'.$fmt($m->starts_at), 'DTEND:'.$fmt($m->ends_at),
            'SUMMARY:'.$esc('Demo de EscudoSST'), 'DESCRIPTION:'.$esc($desc), $m->meeting_url ? 'URL:'.$m->meeting_url : null,
            'STATUS:'.($m->status === 'cancelled' ? 'CANCELLED' : 'CONFIRMED'), 'END:VEVENT', 'END:VCALENDAR',
        ]))."\r\n";
    }

    private function rows(object $m): array
    {
        return array_values(array_filter([
            ['Fecha y hora', $this->when($m->starts_at)],
            ['Duración', ((int) round((strtotime($m->ends_at) - strtotime($m->starts_at)) / 60)).' minutos'],
            ['Modalidad', self::PROVIDERS[$m->meeting_provider] ?? $m->meeting_provider],
            $m->host_name ? ['Te atiende', $m->host_name] : null,
            $m->meeting_url && in_array($m->status, ['confirmed', 'rescheduled'], true) ? ['Enlace', $m->meeting_url] : null,
        ]));
    }

    private function recordingNote(object $m): string
    {
        return $m->recording_consent
            ? 'Nos diste permiso para grabar la reunión; puedes retirarlo al inicio de la llamada.'
            : 'La reunión solo se graba si nos das tu consentimiento.';
    }

    /** Correo al visitante según lo que pasó con su cita. $token solo existe al reservar o al crear desde el panel. */
    public function mailVisitor(object $m, string $kind, ?string $token = null, ?string $reason = null): void
    {
        $first = explode(' ', trim((string) $m->full_name))[0] ?: 'Hola';
        $manage = $token ? $this->site().'/demo/gestionar/'.$token : null;
        [$title, $intro] = match ($kind) {
            'booked' => ['Recibimos tu solicitud de demo', 'Reservaste este horario. Nuestro equipo lo revisará y te confirmará la reunión por correo.'],
            'confirmed' => ['Tu demo está confirmada', $m->meeting_provider === 'phone' ? 'Te llamaremos al número que dejaste a la hora indicada.' : 'Te esperamos. Abajo tienes el enlace de la reunión.'],
            'rescheduled' => ['Cambiamos el horario de tu demo', 'Este es el nuevo horario de tu demo.'],
            'cancelled' => ['Tu demo fue cancelada', $reason ? 'Motivo: '.$reason : 'La reunión quedó cancelada. Si quieres, puedes agendar otra.'],
            'reminder_24h' => ['Mañana es tu demo', 'Te recordamos tu demo de EscudoSST.'],
            'reminder_1h' => ['Tu demo empieza en una hora', 'Te recordamos tu demo de EscudoSST.'],
        };
        $note = $kind === 'cancelled' ? null : $this->recordingNote($m).($manage ? "\n\nGuarda este correo: el botón te permite reprogramar o cancelar." : '');
        $button = match (true) {
            $kind === 'cancelled' => ['Agendar otra demo', $this->site().'/demo/agendar'],
            $manage !== null => ['Reprogramar o cancelar', $manage],
            (bool) $m->meeting_url && in_array($kind, ['confirmed', 'reminder_24h', 'reminder_1h'], true) => ['Entrar a la reunión', $m->meeting_url],
            default => [null, null],
        };
        $this->send($m->email, new SiteRequestMail(
            $first, $title, $intro, $kind === 'cancelled' ? [['Fecha y hora', $this->when($m->starts_at)]] : $this->rows($m), $note, $button[0], $button[1],
            $kind === 'cancelled' ? null : [$this->ics($m), 'demo-escudosst.ics'],
        ));
    }

    public function mailTeam(object $m, string $title): void
    {
        $s = json_decode($this->db()->selectOne('select public_site_settings() as s')->s, true);
        $to = array_filter(array_unique([$m->host_email ?? null, ($s['sales_email'] ?? null) ?: ($s['contact_email'] ?? null)]));
        foreach ($to as $email) {
            $this->send($email, new SiteRequestMail(
                'equipo', "{$title}: {$m->company_name}", 'Revisa la agenda de demos en el panel.',
                [['Empresa', $m->company_name.($m->ruc ? " (RUC {$m->ruc})" : '')], ['Contacto', $m->full_name.' · '.$m->email], ['Fecha y hora', $this->when($m->starts_at)],
                    ['Modalidad', self::PROVIDERS[$m->meeting_provider] ?? $m->meeting_provider], ['Grabación', $m->recording_consent ? 'Autorizada' : 'No autorizada']],
                $m->message, 'Ver la agenda', $this->site().'/plataforma/sitio/agenda'
            ));
        }
    }

    /** Recordatorios del día anterior y de la hora previa (RN-WEB-72): solo confirmadas, una sola vez. */
    public function sendReminders(): int
    {
        $s = $this->settings();
        $n = 0;
        foreach ([['reminder_24h', '24 hours', '23 hours'], ['reminder_1h', '1 hour', '45 minutes']] as [$kind, $max, $min]) {
            if (! $s->{$kind}) {
                continue;
            }
            $col = $kind.'_sent_at';
            $due = $this->db()->select(
                "select id from demo_meetings where status in ('confirmed', 'rescheduled') and deleted_at is null and {$col} is null
                    and starts_at <= now() + interval '{$max}' and starts_at > now() + interval '{$min}'"
            );
            foreach ($due as $d) {
                // Se marca antes de enviar: si el correo falla no se repite en bucle.
                if ($this->db()->update("update demo_meetings set {$col} = now() where id = ? and {$col} is null", [$d->id])) {
                    $this->mailVisitor($this->meeting($d->id), $kind);
                    $n++;
                }
            }
        }

        return $n;
    }

    private function send(string $to, SiteRequestMail $m): void
    {
        try {
            Mail::to($to)->send($m);
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar el correo de la agenda de demos', ['error' => $e->getMessage()]);
        }
    }
}
