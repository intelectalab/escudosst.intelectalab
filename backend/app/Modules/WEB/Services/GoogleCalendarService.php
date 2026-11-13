<?php

namespace App\Modules\WEB\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Calendar para la agenda de demos (RN-WEB-74). Cada anfitrión conecta su cuenta por OAuth; el token se guarda
 * cifrado en demo_calendar_tokens y demo_hosts solo tiene la referencia. Al confirmar una demo con Google Meet se crea
 * el evento en su calendario con el enlace de Meet generado por Google y el visitante como invitado; al moverla o
 * cancelarla se actualiza el evento. Si Google falla, la cita sigue en el panel y queda el aviso del error.
 */
class GoogleCalendarService
{
    private const AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN = 'https://oauth2.googleapis.com/token';

    private const API = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    /** Texto que queda en last_error cuando Google no renueva el permiso (la campana del panel lo cuenta). */
    public const REFRESH_FAILED = 'Google rechazó el permiso del calendario (puede haber vencido). Vuelve a conectarlo.';

    private const SCOPES = 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.freebusy openid email';

    public function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    public function configured(): bool
    {
        return filled(config('escudo.google.client_id')) && filled(config('escudo.google.client_secret'));
    }

    /** Enlace para que el anfitrión autorice su calendario. El estado va cifrado y vence en 15 minutos. */
    public function authUrl(string $hostId, string $userId): string
    {
        $state = Crypt::encryptString(json_encode(['h' => $hostId, 'u' => $userId, 'x' => time() + 900]));

        return self::AUTH.'?'.http_build_query([
            'client_id' => config('escudo.google.client_id'), 'redirect_uri' => config('escudo.google.redirect_uri'), 'response_type' => 'code',
            'scope' => self::SCOPES, 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state,
        ]);
    }

    /** Devuelve el host conectado o lanza una excepción con un mensaje para mostrar. */
    public function handleCallback(string $code, string $state): string
    {
        $s = json_decode(Crypt::decryptString($state), true);
        if (! is_array($s) || ($s['x'] ?? 0) < time()) {
            throw new \RuntimeException('El enlace para conectar venció. Vuelve a intentarlo desde el panel.');
        }
        $r = Http::asForm()->timeout(15)->post(self::TOKEN, [
            'code' => $code, 'client_id' => config('escudo.google.client_id'), 'client_secret' => config('escudo.google.client_secret'),
            'redirect_uri' => config('escudo.google.redirect_uri'), 'grant_type' => 'authorization_code',
        ]);
        if (! $r->successful() || ! $r->json('refresh_token')) {
            throw new \RuntimeException('Google no entregó el permiso. Revisa que aceptaste el acceso al calendario.');
        }
        $email = $this->emailFromIdToken((string) $r->json('id_token'));
        $hostId = $s['h'];
        $db = $this->db();
        $db->transaction(function () use ($db, $hostId, $r, $email, $s) {
            $db->delete('delete from demo_calendar_tokens where host_id = ?', [$hostId]);
            $db->insert(
                "insert into demo_calendar_tokens (host_id, provider, account_email, refresh_token_encrypted, access_token_encrypted, access_expires_at) values (?, 'google', ?, ?, ?, now() + make_interval(secs => ?))",
                [$hostId, $email, Crypt::encryptString($r->json('refresh_token')), Crypt::encryptString((string) $r->json('access_token')), max(60, (int) $r->json('expires_in', 3600) - 60)]
            );
            $db->update("update demo_hosts set calendar_provider = 'google', calendar_secret_ref = ?, updated_by = ?, updated_at = now() where id = ?", ['db://demo-calendar/'.$hostId, $s['u'], $hostId]);
        });

        return $hostId;
    }

    public function disconnect(string $hostId): void
    {
        $t = $this->db()->selectOne('select refresh_token_encrypted from demo_calendar_tokens where host_id = ?', [$hostId]);
        if ($t) {
            try {
                Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', ['token' => Crypt::decryptString($t->refresh_token_encrypted)]);
            } catch (\Throwable) {
                // si Google no responde, igual se borra el token aquí
            }
        }
        $this->db()->delete('delete from demo_calendar_tokens where host_id = ?', [$hostId]);
        $this->db()->update("update demo_hosts set calendar_provider = 'none', calendar_secret_ref = null where id = ?", [$hostId]);
    }

    private function emailFromIdToken(string $jwt): ?string
    {
        $parts = explode('.', $jwt);
        $payload = isset($parts[1]) ? json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) : null;

        return is_array($payload) && is_string($payload['email'] ?? null) ? $payload['email'] : null;
    }

    private function accessToken(string $hostId): ?string
    {
        $t = $this->db()->selectOne("select * from demo_calendar_tokens where host_id = ? and provider = 'google'", [$hostId]);
        if (! $t) {
            return null;
        }
        if ($t->access_token_encrypted && $t->access_expires_at && Carbon::parse($t->access_expires_at)->isFuture()) {
            return Crypt::decryptString($t->access_token_encrypted);
        }
        $r = Http::asForm()->timeout(15)->post(self::TOKEN, [
            'client_id' => config('escudo.google.client_id'), 'client_secret' => config('escudo.google.client_secret'),
            'refresh_token' => Crypt::decryptString($t->refresh_token_encrypted), 'grant_type' => 'refresh_token',
        ]);
        if (! $r->successful()) {
            $this->fail($hostId, self::REFRESH_FAILED);
            if ($t->last_error !== self::REFRESH_FAILED) {
                $this->mailDisconnected($hostId);   // solo la primera vez: no se repite en cada intento
            }

            return null;
        }
        $this->db()->update(
            'update demo_calendar_tokens set access_token_encrypted = ?, access_expires_at = now() + make_interval(secs => ?), last_error = null, updated_at = now() where host_id = ?',
            [Crypt::encryptString((string) $r->json('access_token')), max(60, (int) $r->json('expires_in', 3600) - 60), $hostId]
        );

        return (string) $r->json('access_token');
    }

    /** Calendario del anfitrión desconectado (M-WEB §10): correo al anfitrión y al equipo de ventas. Nunca rompe la acción. */
    private function mailDisconnected(string $hostId): void
    {
        try {
            $h = $this->db()->selectOne('select display_name, email from demo_hosts where id = ?', [$hostId]);
            $s = json_decode($this->db()->selectOne('select public_site_settings() as s')->s, true);
            $to = array_filter(array_unique([$h?->email, ($s['sales_email'] ?? null) ?: ($s['contact_email'] ?? null)]));
            $site = rtrim((string) config('escudo.frontend_url'), '/');
            foreach ($to as $email) {
                \Illuminate\Support\Facades\Mail::to($email)->send(new \App\Modules\WEB\Mail\SiteRequestMail(
                    $email === $h?->email ? (explode(' ', trim((string) $h->display_name))[0] ?: 'Hola') : 'equipo',
                    'Se desconectó el calendario de Google',
                    'Google ya no acepta el permiso del calendario. Mientras no se vuelva a conectar, las demos no se guardan en el calendario ni se crea el enlace de Meet.',
                    [['Anfitrión', $h?->display_name ?? 'Sin nombre']],
                    'Entra a la agenda de demos y vuelve a conectar Google Calendar.', 'Ir a la agenda', $site.'/plataforma/sitio/agenda'
                ));
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo avisar que el calendario se desconectó', ['error' => $e->getMessage()]);
        }
    }

    private function fail(string $hostId, string $msg): void
    {
        $this->db()->update('update demo_calendar_tokens set last_error = ?, updated_at = now() where host_id = ?', [$msg, $hostId]);
        Log::warning('Google Calendar: '.$msg, ['host' => $hostId]);
    }

    public function connected(?string $hostId): bool
    {
        return $hostId && $this->configured() && (bool) $this->db()->selectOne("select 1 from demo_calendar_tokens where host_id = ? and provider = 'google'", [$hostId]);
    }

    /** Crea (o actualiza) el evento de la reunión. Devuelve [id del evento, enlace de Meet] o null si no se pudo. */
    public function upsertEvent(object $m, bool $withMeet): ?array
    {
        $token = $this->accessToken($m->host_id);
        if (! $token) {
            return null;
        }
        $body = [
            'summary' => 'Demo EscudoSST · '.$m->company_name,
            'description' => "Demo de EscudoSST con {$m->full_name} ({$m->company_name}).\nGrabación: ".($m->recording_consent ? 'autorizada por el visitante.' : 'NO autorizada: no grabar.')."\nGestiona la cita en el panel de la plataforma.",
            'start' => ['dateTime' => Carbon::parse($m->starts_at)->toRfc3339String(), 'timeZone' => 'America/Lima'],
            'end' => ['dateTime' => Carbon::parse($m->ends_at)->toRfc3339String(), 'timeZone' => 'America/Lima'],
            'attendees' => [['email' => $m->email, 'displayName' => $m->full_name]],
            'reminders' => ['useDefault' => true],
        ];
        if ($withMeet && $m->meeting_provider === 'meet') {
            $body['conferenceData'] = ['createRequest' => ['requestId' => 'escudo-'.$m->id, 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]];
        }
        $q = '?conferenceDataVersion=1&sendUpdates=all';
        try {
            $r = $m->calendar_event_ref
                ? Http::withToken($token)->timeout(15)->patch(self::API.'/'.rawurlencode($m->calendar_event_ref).$q, $body)
                : Http::withToken($token)->timeout(15)->post(self::API.$q, $body);
        } catch (\Throwable $e) {
            $this->fail($m->host_id, 'No se pudo conectar con Google Calendar.');

            return null;
        }
        if (! $r->successful()) {
            $this->fail($m->host_id, 'Google Calendar respondió '.$r->status().' al guardar la demo.');

            return null;
        }
        $this->db()->update('update demo_calendar_tokens set last_error = null where host_id = ?', [$m->host_id]);
        $meet = $r->json('hangoutLink') ?? collect($r->json('conferenceData.entryPoints', []))->firstWhere('entryPointType', 'video')['uri'] ?? null;

        return [(string) $r->json('id'), is_string($meet) && str_starts_with($meet, 'https://') ? $meet : null];
    }

    /**
     * Quita de una lista de horarios [host_id, starts_at, ends_at] los que chocan con algo ocupado en el Google Calendar
     * de ese anfitrión (reuniones personales, etc.). Si Google no responde, no quita nada (la agenda sigue funcionando).
     */
    public function filterBusy(array $slots): array
    {
        if (! $slots || ! $this->configured()) {
            return $slots;
        }
        $hosts = array_values(array_unique(array_map(fn ($s) => $s->host_id, $slots)));
        $connected = collect($this->db()->select("select host_id from demo_calendar_tokens where provider = 'google'"))->pluck('host_id')->intersect($hosts)->all();
        if (! $connected) {
            return $slots;
        }
        $from = Carbon::parse(min(array_map(fn ($s) => $s->starts_at, $slots)))->startOfDay();
        $to = Carbon::parse(max(array_map(fn ($s) => $s->ends_at, $slots)))->endOfDay();
        $busy = [];
        foreach ($connected as $h) {
            $busy[$h] = $this->busy($h, $from, $to);
        }

        return array_values(array_filter($slots, function ($s) use ($busy) {
            foreach ($busy[$s->host_id] ?? [] as [$b0, $b1]) {
                if (strtotime($s->starts_at) < $b1 && strtotime($s->ends_at) > $b0) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** Intervalos ocupados [inicio, fin] (segundos) del calendario principal del anfitrión. En caché 5 minutos. */
    public function busy(string $hostId, Carbon $from, Carbon $to): array
    {
        return Cache::remember("gcal-busy:{$hostId}:".$from->timestamp.':'.$to->timestamp, 300, function () use ($hostId, $from, $to) {
            $token = $this->accessToken($hostId);
            if (! $token) {
                return [];
            }
            try {
                $r = Http::withToken($token)->timeout(10)->post('https://www.googleapis.com/calendar/v3/freeBusy', [
                    'timeMin' => $from->toRfc3339String(), 'timeMax' => $to->toRfc3339String(), 'timeZone' => 'America/Lima', 'items' => [['id' => 'primary']],
                ]);
            } catch (\Throwable) {
                return [];
            }
            if (! $r->successful()) {
                if ($r->status() === 403) {
                    $this->fail($hostId, 'Para no ofrecer tus horas ocupadas, vuelve a conectar Google Calendar (falta el permiso de disponibilidad).');
                }

                return [];
            }

            return array_map(fn ($b) => [strtotime($b['start']), strtotime($b['end'])], $r->json('calendars.primary.busy', []));
        });
    }

    public function deleteEvent(object $m): void
    {
        if (! $m->calendar_event_ref || ! $m->host_id || ! ($token = $this->accessToken($m->host_id))) {
            return;
        }
        try {
            Http::withToken($token)->timeout(15)->delete(self::API.'/'.rawurlencode($m->calendar_event_ref).'?sendUpdates=all');
        } catch (\Throwable) {
            $this->fail($m->host_id, 'No se pudo quitar la demo del calendario de Google.');
        }
    }
}
