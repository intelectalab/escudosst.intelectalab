<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use App\Modules\WEB\Services\DemoService;
use App\Modules\WEB\Services\GoogleCalendarService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Agenda de demos (SCR-WEB-51 a 58; RN-WEB-68 a 73).
 *  Público: GET  /public/demo/slots?from=&to=              horarios libres (hora de Lima)
 *           POST /public/demo/book                          reservar (Idempotency-Key, privacidad, casilla comercial aparte)
 *           GET  /public/demo/manage/{token}                ver la cita (respuesta idéntica si el enlace no sirve)
 *           POST /public/demo/manage/{token}/{cancel|reschedule}
 *           GET  /public/demo/manage/{token}/ics            «Agregar a mi calendario»
 *  Panel:   agenda, ajustes, anfitriones (disponibilidad y bloqueos) y acciones sobre cada reunión.
 *           Solo el Dueño asigna anfitrión y cambia ajustes; Soporte ve y confirma solo las reuniones que tiene asignadas.
 */
class DemoBookingController extends Controller
{
    public const OUTCOMES = ['interested' => 'Interesado', 'not_interested' => 'No interesado', 'follow_up' => 'Seguimiento', 'converted' => 'Se volvió cliente'];

    private const SECTORS = 'construction,mining,agroindustry,manufacturing,retail,services,office,health,education,transport,energy,other';

    private const MESSAGES = [
        'required' => 'Completa este campo.',
        'email' => 'Escribe un correo válido.',
        'max' => 'Es muy largo.',
        'min' => 'Es muy corto.',
        'in' => 'Elige una opción de la lista.',
        'accepted' => 'Para agendar, acepta la política de privacidad.',
        'date' => 'Fecha no válida.',
        'regex' => 'Formato no válido.',
        'url' => 'Escribe el enlace completo, con https://',
        'after' => 'Elige una fecha futura.',
        'date_format' => 'Usa el formato de hora HH:MM.',
    ];

    public function __construct(private readonly DemoService $svc, private readonly GoogleCalendarService $google) {}

    private function db()
    {
        return $this->svc->db();
    }

    private function fail(string $field, string $msg, int $status = 422): never
    {
        throw new ApiProblem($status, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }

    // ---------------------------------------------------------------- Público

    public function slots(Request $request): JsonResponse
    {
        $s = $this->svc->settings();
        if (! $s?->public_booking_enabled) {
            return response()->json(['data' => ['result' => 'disabled', 'slots' => []]]);
        }
        $from = $this->dateOrNull($request->query('from')) ?? now('America/Lima')->toDateString();
        $to = $this->dateOrNull($request->query('to')) ?? Carbon::parse($from)->addDays(14)->toDateString();
        $to = min($to, Carbon::parse($from)->addDays(31)->toDateString());
        // Mismo cálculo que public_demo_slots, quitando además lo ocupado en el Google Calendar de cada anfitrión.
        $free = $this->google->filterBusy($this->db()->select('select host_id, starts_at, ends_at from web_demo_free_slots(?::date, ?::date)', [$from, $to]));
        $slots = collect($free)->map(fn ($x) => ['starts_at' => Carbon::parse($x->starts_at)->toIso8601String(), 'ends_at' => Carbon::parse($x->ends_at)->toIso8601String()])
            ->unique('starts_at')->sortBy('starts_at')->values();

        return response()->json(['data' => ['result' => 'ok', 'duration_minutes' => $s->duration_minutes, 'timezone' => 'America/Lima', 'slots' => $slots]]);
    }

    public function book(Request $request): JsonResponse
    {
        if (filled($request->input('website'))) {   // trampa para robots
            return response()->json(['data' => ['result' => 'ok']], 201);
        }
        $d = $request->validate([
            'starts_at' => ['required', 'date'],
            'full_name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:160'],
            'phone' => ['nullable', 'string', 'max:30', 'required_if:meeting_provider,phone'],
            'company_name' => ['required', 'string', 'min:2', 'max:160'],
            'ruc' => ['nullable', 'regex:/^(10|15|17|20)\d{9}$/'],
            'headcount' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'economic_sector' => ['nullable', 'in:'.self::SECTORS],
            'is_consultancy' => ['boolean'],
            'meeting_provider' => ['required', 'in:'.implode(',', array_keys(DemoService::PROVIDERS))],
            'message' => ['nullable', 'string', 'max:2000'],
            'privacy_consent' => ['accepted'],
            'marketing_consent' => ['boolean'],
            'recording_consent' => ['boolean'],
        ], self::MESSAGES + ['phone.required_if' => 'Para la llamada necesitamos tu celular.', 'ruc.regex' => 'El RUC tiene 11 dígitos y empieza con 10, 15, 17 o 20.']);
        $start = Carbon::parse($d['starts_at']);
        $candidates = $this->freeHostsAt($start);
        if ($candidates === []) {
            $this->fail('starts_at', 'Ese horario acaba de ocuparse. Elige otro, por favor.', 409);
        }
        $key = (string) $request->header('Idempotency-Key', '');
        $key = Str::isUuid($key) ? $key : (string) Str::uuid7();
        $token = Str::random(48);
        $message = trim(($d['is_consultancy'] ?? false ? "Es consultora SST.\n" : '').($d['message'] ?? ''));
        $data = collect($d)->except(['starts_at', 'is_consultancy', 'meeting_provider'])->all() + [
            'privacy_consent' => true, 'marketing_consent' => (bool) ($d['marketing_consent'] ?? false),
            'recording_consent' => (bool) ($d['recording_consent'] ?? false), 'message' => $message ?: null,
            'source_page' => '/demo/agendar',
        ];
        $r = json_decode($this->db()->selectOne(
            "select public_demo_book(?::jsonb, ?::timestamptz, ?, decode(?, 'hex'), ?) as r",
            [json_encode($data, JSON_UNESCAPED_UNICODE), Carbon::parse($d['starts_at'])->toIso8601String(), $token,
                hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')), $key]
        )->r, true);
        if (($r['result'] ?? '') === 'disabled') {
            $this->fail('starts_at', 'La agenda en línea no está disponible ahora. Escríbenos y coordinamos.', 409);
        }
        if (($r['result'] ?? '') === 'slot_unavailable') {
            $this->fail('starts_at', 'Ese horario acaba de ocuparse. Elige otro, por favor.', 409);
        }
        if (! ($r['replay'] ?? false)) {
            $this->db()->update('update demo_meetings set meeting_provider = ? where id = ?', [$d['meeting_provider'], $r['meeting_id']]);
            $assigned = $this->db()->selectOne('select host_id from demo_meetings where id = ?', [$r['meeting_id']])->host_id;
            if ($candidates !== null && ! in_array($assigned, $candidates, true)) {   // la base eligió a alguien ocupado en Google
                $this->db()->selectOne('select web_demo_meeting_assign(?, ?)', [$r['meeting_id'], $candidates[0]]);
            }
            $m = $this->svc->meeting($r['meeting_id']);
            $this->svc->mailVisitor($m, 'booked', $token);
            $this->svc->mailTeam($m, 'Nueva demo agendada');
        } else {
            $token = null;   // en un reintento no se devuelve un enlace distinto
        }
        $m = $this->svc->meeting($r['meeting_id']);

        return response()->json(['data' => [
            'result' => 'ok', 'starts_at' => $m->starts_at, 'ends_at' => $m->ends_at, 'host_name' => $m->host_name,
            'meeting_provider' => $m->meeting_provider, 'recording_consent' => (bool) $m->recording_consent, 'manage_token' => $token,
        ]], 201);
    }

    public function manage(string $token): JsonResponse
    {
        $r = json_decode($this->db()->selectOne('select public_demo_manage(?) as r', [$token])->r, true);
        if (($r['result'] ?? '') !== 'ok') {
            throw ApiProblem::notFound('El enlace no es válido o ya venció.');
        }
        unset($r['result']);

        return response()->json(['data' => $r]);
    }

    public function cancel(Request $request, string $token): JsonResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $id = $this->db()->selectOne('select id from demo_meetings where manage_token_hash = web_token_hash(?) and deleted_at is null', [$token])?->id;
        $r = json_decode($this->db()->selectOne('select public_demo_cancel(?, ?) as r', [$token, $reason])->r, true);
        if (($r['result'] ?? '') === 'invalid') {
            throw ApiProblem::notFound('El enlace no es válido o ya venció.');
        }
        if (($r['result'] ?? '') === 'not_allowed') {
            $this->fail('reason', 'Esta cita ya no se puede cancelar.', 409);
        }
        if ($id && ! ($r['replay'] ?? false)) {
            $m = $this->svc->meeting($id);
            $this->google->deleteEvent($m);
            $this->svc->mailTeam($m, 'Demo cancelada por el visitante');
        }

        return response()->json(['data' => ['result' => 'cancelled']]);
    }

    public function reschedule(Request $request, string $token): JsonResponse
    {
        $d = $request->validate(['starts_at' => ['required', 'date']], self::MESSAGES);
        $id = $this->db()->selectOne('select id from demo_meetings where manage_token_hash = web_token_hash(?) and deleted_at is null', [$token])?->id;
        if ($id && $this->freeHostsAt(Carbon::parse($d['starts_at']), $id) === []) {
            $this->fail('starts_at', 'Ese horario acaba de ocuparse. Elige otro, por favor.', 409);
        }
        $r = json_decode($this->db()->selectOne('select public_demo_reschedule(?, ?::timestamptz) as r', [$token, Carbon::parse($d['starts_at'])->toIso8601String()])->r, true);
        match ($r['result'] ?? '') {
            'invalid' => throw ApiProblem::notFound('El enlace no es válido o ya venció.'),
            'not_allowed' => $this->fail('starts_at', 'Esta cita ya no se puede reprogramar.', 409),
            'slot_unavailable' => $this->fail('starts_at', 'Ese horario acaba de ocuparse. Elige otro, por favor.', 409),
            default => null,
        };
        $m = $this->svc->meeting($id);
        if ($m->calendar_event_ref && $this->google->connected($m->host_id)) {
            $this->google->upsertEvent($m, false);
        }
        $this->svc->mailVisitor($m, 'rescheduled', $token);
        $this->svc->mailTeam($m, 'Demo reprogramada por el visitante');

        return response()->json(['data' => ['result' => 'ok', 'starts_at' => $m->starts_at, 'status' => $m->status]]);
    }

    public function ics(string $token): Response
    {
        $id = $this->db()->selectOne(
            "select id from demo_meetings where manage_token_hash = web_token_hash(?) and deleted_at is null and status in ('proposed', 'confirmed', 'rescheduled')", [$token]
        )?->id ?? throw ApiProblem::notFound('El enlace no es válido o ya venció.');

        return response($this->svc->ics($this->svc->meeting($id)), 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="demo-escudosst.ics"', 'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Anfitriones libres a esa hora según la base y su Google Calendar. null = no hay calendarios que revisar
     * (decide la base); [] = nadie libre.
     */
    private function freeHostsAt(Carbon $start, ?string $exclude = null): ?array
    {
        $day = $start->copy()->timezone('America/Lima')->toDateString();
        $rows = array_values(array_filter(
            $this->db()->select('select host_id, starts_at, ends_at from web_demo_free_slots((?::date - 1), (?::date + 1), ?::uuid)', [$day, $day, $exclude]),
            fn ($r) => Carbon::parse($r->starts_at)->equalTo($start)
        ));
        if (! $rows) {
            return null;   // la base responde «horario no disponible» con su propio mensaje
        }
        if (! $this->google->configured() || ! $this->db()->selectOne("select 1 from demo_calendar_tokens where provider = 'google' and host_id = any(?::uuid[])", ['{'.implode(',', array_map(fn ($r) => $r->host_id, $rows)).'}'])) {
            return null;
        }

        return array_values(array_map(fn ($r) => $r->host_id, $this->google->filterBusy($rows)));
    }

    private function dateOrNull($v): ?string
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }

    // ---------------------------------------------------------------- Panel

    private function isOwner(Request $request): bool
    {
        return $request->user()->platform_role !== 'support';
    }

    /** Soporte solo ve y toca las reuniones donde es el anfitrión (RN-WEB-70). */
    private function findForUser(Request $request, string $id): object
    {
        $m = $this->svc->meeting($id) ?? throw ApiProblem::notFound('La reunión no existe.');
        if (! $this->isOwner($request)) {
            $mine = $this->db()->selectOne('select 1 from demo_hosts where id = ? and user_id = ?', [$m->host_id, $request->user()->id]);
            if (! $mine) {
                throw new ApiProblem(403, 'forbidden', 'Sin permiso', 'Solo puedes gestionar las reuniones que tienes asignadas.');
            }
        }

        return $m;
    }

    public function index(Request $request): JsonResponse
    {
        $from = $this->dateOrNull($request->query('from')) ?? now('America/Lima')->startOfMonth()->subDays(7)->toDateString();
        $to = $this->dateOrNull($request->query('to')) ?? now('America/Lima')->endOfMonth()->addDays(7)->toDateString();
        $owner = $this->isOwner($request);
        $meetings = $this->db()->select(
            "select m.id, m.starts_at, m.ends_at, m.status, m.meeting_provider, m.meeting_url, m.host_id, m.recording_consent, m.outcome, m.outcome_notes,
                    m.cancel_reason, m.confirmed_at, m.reminder_24h_sent_at, m.reminder_1h_sent_at, m.created_at,
                    r.id as request_id, r.full_name, r.email, r.phone, r.company_name, r.ruc, r.headcount, r.economic_sector, r.message, r.marketing_consent
               from demo_meetings m join demo_requests r on r.id = m.demo_request_id
              where m.deleted_at is null and m.starts_at >= (?::date)::timestamp at time zone 'America/Lima' and m.starts_at < ((?::date) + 1)::timestamp at time zone 'America/Lima'
                ".($owner ? '' : 'and m.host_id in (select id from demo_hosts where user_id = ?)').'
              order by m.starts_at',
            $owner ? [$from, $to] : [$from, $to, $request->user()->id]
        );
        $pending = $owner ? $this->db()->select(
            "select r.id, r.full_name, r.email, r.company_name, r.phone, r.headcount, r.message, r.created_at from demo_requests r
              where r.request_type = 'demo' and r.status in ('new', 'contacted') and r.deleted_at is null
                and not exists (select 1 from demo_meetings m where m.demo_request_id = r.id and m.deleted_at is null and m.status in ('proposed', 'confirmed', 'rescheduled'))
              order by r.created_at desc limit 50"
        ) : [];

        return response()->json(['data' => [
            'meetings' => $meetings, 'pending' => $pending, 'hosts' => $this->hosts(), 'settings' => $this->svc->settings(),
            'providers' => DemoService::PROVIDERS, 'outcomes' => self::OUTCOMES,
        ]]);
    }

    private function hosts(): array
    {
        $hosts = $this->db()->select('select h.*, u.full_name as user_name from demo_hosts h left join users u on u.id = h.user_id where h.deleted_at is null order by h.display_name');
        $rules = collect($this->db()->select('select id, host_id, weekday, to_char(start_time, \'HH24:MI\') as start_time, to_char(end_time, \'HH24:MI\') as end_time from demo_availability_rules where deleted_at is null order by weekday, start_time'))->groupBy('host_id');
        $blocks = collect($this->db()->select('select id, host_id, starts_at, ends_at, reason from demo_blackouts where deleted_at is null and ends_at > now() order by starts_at'));
        $tokens = collect($this->db()->select('select host_id, account_email, last_error from demo_calendar_tokens'))->keyBy('host_id');
        foreach ($hosts as $h) {
            $h->rules = $rules[$h->id] ?? [];
            $h->calendar_account = $tokens[$h->id]->account_email ?? null;
            $h->calendar_error = $tokens[$h->id]->last_error ?? null;
            unset($h->calendar_secret_ref);   // nunca se muestra la referencia del secreto
        }

        return ['google_ready' => $this->google->configured(), 'list' => $hosts, 'blackouts' => $blocks->values(), 'team' => $this->db()->select("select id, full_name, email from users where platform_role is not null and deleted_at is null and status = 'active' order by full_name")];
    }

    /** Horarios libres para que el panel proponga o mueva una reunión. */
    public function freeSlots(Request $request): JsonResponse
    {
        $from = $this->dateOrNull($request->query('from')) ?? now('America/Lima')->toDateString();
        $to = $this->dateOrNull($request->query('to')) ?? now('America/Lima')->addDays(14)->toDateString();
        $exclude = Str::isUuid((string) $request->query('exclude')) ? $request->query('exclude') : null;
        $rows = $this->google->filterBusy($this->db()->select('select host_id, starts_at, ends_at from web_demo_free_slots(?::date, ?::date, ?::uuid) order by starts_at', [$from, $to, $exclude]));

        return response()->json(['data' => $rows]);
    }

    public function updateSettings(Request $request, AccountsService $accounts): Response
    {
        $d = $request->validate([
            'public_booking_enabled' => ['required', 'boolean'],
            'duration_minutes' => ['required', 'integer', 'between:10,240'],
            'buffer_minutes' => ['required', 'integer', 'between:0,120'],
            'min_notice_hours' => ['required', 'integer', 'between:0,720'],
            'max_days_ahead' => ['required', 'integer', 'between:1,180'],
            'default_meeting_provider' => ['required', 'in:'.implode(',', array_keys(DemoService::PROVIDERS))],
            'reminder_24h' => ['required', 'boolean'],
            'reminder_1h' => ['required', 'boolean'],
        ], self::MESSAGES);
        if ($d['public_booking_enabled'] && ! $this->db()->selectOne('select 1 from demo_hosts h join demo_availability_rules r on r.host_id = h.id and r.deleted_at is null where h.is_active and h.deleted_at is null')) {
            $this->fail('public_booking_enabled', 'Antes de activar la agenda, agrega al menos un anfitrión con horario.');
        }
        $this->db()->table('demo_booking_settings')->where('singleton', true)->update($d + ['updated_by' => $request->user()->id, 'updated_at' => now()]);
        $accounts->audit($request->user()->id, 'demo_settings_updated', 'demo_booking_settings', null, $d);

        return response()->noContent();
    }

    public function saveHost(Request $request, AccountsService $accounts, ?string $id = null): JsonResponse
    {
        $d = $request->validate([
            'user_id' => ['required', 'uuid'],
            'display_name' => ['required', 'string', 'min:3', 'max:80'],
            'email' => ['required', 'email', 'max:160'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'max_per_day' => ['nullable', 'integer', 'between:1,20'],
            'is_active' => ['boolean'],
            'rules' => ['array', 'max:21'],
            'rules.*.weekday' => ['required', 'integer', 'between:1,7'],
            'rules.*.start_time' => ['required', 'date_format:H:i'],
            'rules.*.end_time' => ['required', 'date_format:H:i'],
        ], self::MESSAGES + ['display_name.min' => 'Escribe el nombre como lo verá el cliente.', 'user_id.required' => 'Elige a la persona del equipo que atiende.']);
        foreach ($d['rules'] ?? [] as $i => $r) {
            if ($r['end_time'] <= $r['start_time']) {
                $this->fail("rules.{$i}.end_time", 'La hora de fin debe ser después del inicio.');
            }
        }
        if (! $this->db()->selectOne('select 1 from users where id = ? and platform_role is not null and deleted_at is null', [$d['user_id']])) {
            $this->fail('user_id', 'Elige a alguien del equipo de la plataforma.');
        }
        $row = [
            'user_id' => $d['user_id'], 'display_name' => trim($d['display_name']), 'email' => mb_strtolower($d['email']), 'color' => $d['color'] ?? null,
            'max_per_day' => $d['max_per_day'] ?? null, 'is_active' => (bool) ($d['is_active'] ?? true), 'timezone' => 'America/Lima', 'updated_by' => $request->user()->id,
        ];
        $db = $this->db();
        $id = $db->transaction(function () use ($db, $id, $row, $d, $request) {
            if ($id) {
                $db->table('demo_hosts')->where('id', $id)->whereNull('deleted_at')->update($row + ['updated_at' => now()]) ?: throw ApiProblem::notFound('El anfitrión no existe.');
            } else {
                $id = $db->table('demo_hosts')->insertGetId($row + ['calendar_provider' => 'none', 'created_by' => $request->user()->id]);
            }
            $db->delete('delete from demo_availability_rules where host_id = ?', [$id]);
            foreach ($d['rules'] ?? [] as $r) {
                $db->insert('insert into demo_availability_rules (host_id, weekday, start_time, end_time, created_by) values (?, ?, ?, ?, ?)', [$id, $r['weekday'], $r['start_time'], $r['end_time'], $request->user()->id]);
            }

            return $id;
        });
        $accounts->audit($request->user()->id, 'demo_host_saved', 'demo_hosts', $id, ['name' => $row['display_name'], 'rules' => count($d['rules'] ?? [])]);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function addBlackout(Request $request, AccountsService $accounts): JsonResponse
    {
        $d = $request->validate([
            'host_id' => ['nullable', 'uuid'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:200'],
        ], self::MESSAGES + ['ends_at.after' => 'El fin debe ser después del inicio.']);
        $id = $this->db()->table('demo_blackouts')->insertGetId([
            'host_id' => $d['host_id'] ?? null, 'starts_at' => Carbon::parse($d['starts_at']), 'ends_at' => Carbon::parse($d['ends_at']),
            'reason' => $d['reason'] ?? null, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
        ]);
        $accounts->audit($request->user()->id, 'demo_blackout_added', 'demo_blackouts', $id, []);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function deleteBlackout(Request $request, string $id): Response
    {
        $this->db()->delete('delete from demo_blackouts where id = ?', [$id]);

        return response()->noContent();
    }

    /** Proponer una demo a una solicitud sin horario (desde la agenda). Crea la reunión y avisa al visitante. */
    public function propose(Request $request, AccountsService $accounts): JsonResponse
    {
        $d = $request->validate([
            'demo_request_id' => ['required', 'uuid'],
            'host_id' => ['required', 'uuid'],
            'starts_at' => ['required', 'date', 'after:now'],
            'meeting_provider' => ['required', 'in:'.implode(',', array_keys(DemoService::PROVIDERS))],
            'meeting_url' => ['nullable', 'url:https', 'max:500'],
        ], self::MESSAGES);
        $req = $this->db()->selectOne("select id from demo_requests where id = ? and deleted_at is null and request_type = 'demo'", [$d['demo_request_id']])
            ?? $this->fail('demo_request_id', 'La solicitud no existe.');
        $s = $this->svc->settings();
        $token = Str::random(48);
        $start = Carbon::parse($d['starts_at']);
        $autoMeet = $d['meeting_provider'] === 'meet' && empty($d['meeting_url']) && $this->google->connected($d['host_id']);
        $confirm = $d['meeting_provider'] === 'phone' || filled($d['meeting_url'] ?? null) || $autoMeet;
        try {
            $id = $this->db()->transaction(function () use ($d, $s, $token, $start, $confirm, $req, $request) {
                $id = $this->db()->table('demo_meetings')->insertGetId([
                    'demo_request_id' => $req->id, 'host_id' => $d['host_id'], 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes($s->duration_minutes),
                    'status' => $confirm ? 'confirmed' : 'proposed', 'confirmed_at' => $confirm ? now() : null, 'meeting_provider' => $d['meeting_provider'],
                    'meeting_url' => $d['meeting_url'] ?? null, 'manage_token_hash' => hash('sha256', $token, true), 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
                ]);
                $this->db()->update("update demo_requests set status = 'demo_scheduled', demo_scheduled_at = ? where id = ? and status in ('new', 'contacted')", [$start, $req->id]);

                return $id;
            });
        } catch (QueryException $e) {
            $this->overlap($e);
        }
        if ($confirm && $this->google->connected($d['host_id'])) {
            [$url, $ref] = $this->calendarFor($this->svc->meeting($id), $d['meeting_provider'], $d['meeting_url'] ?? null);
            if ($autoMeet && ! $url) {   // Google no respondió: queda por confirmar en vez de confirmada sin enlace
                $this->db()->update("update demo_meetings set status = 'proposed', confirmed_at = null where id = ?", [$id]);
                $confirm = false;
            } else {
                $this->db()->update('update demo_meetings set meeting_url = coalesce(?, meeting_url), calendar_event_ref = ? where id = ?', [$url, $ref, $id]);
            }
        }
        $accounts->audit($request->user()->id, 'demo_meeting_proposed', 'demo_meetings', $id, ['starts_at' => $start->toIso8601String()]);
        $this->svc->mailVisitor($this->svc->meeting($id), $confirm ? 'confirmed' : 'booked', $token);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function act(Request $request, string $id, string $action, AccountsService $accounts): JsonResponse
    {
        $m = $this->findForUser($request, $id);
        $db = $this->db();
        $actor = $request->user()->id;
        try {
            switch ($action) {
                case 'assign':
                    if (! $this->isOwner($request)) {
                        throw new ApiProblem(403, 'forbidden', 'Sin permiso', 'Solo el Dueño asigna anfitrión.');
                    }
                    $d = $request->validate(['host_id' => ['required', 'uuid']], self::MESSAGES);
                    $db->selectOne('select web_demo_meeting_assign(?, ?)', [$id, $d['host_id']]);
                    break;
                case 'confirm':
                    $d = $request->validate(['meeting_url' => ['nullable', 'url:https', 'max:500'], 'meeting_provider' => ['required', 'in:'.implode(',', array_keys(DemoService::PROVIDERS))]], self::MESSAGES);
                    if (! $m->host_id) {
                        $this->fail('host_id', 'Primero asigna un anfitrión.');
                    }
                    [$url, $ref] = $this->calendarFor($m, $d['meeting_provider'], $d['meeting_url'] ?? null);
                    if ($d['meeting_provider'] !== 'phone' && ! $url && ! $m->meeting_url) {
                        $this->fail('meeting_url', $this->google->connected($m->host_id) && $d['meeting_provider'] === 'meet'
                            ? 'Google Calendar no pudo crear el enlace de Meet. Revisa la conexión del anfitrión o pega el enlace.'
                            : 'Pega el enlace de Meet o Teams para confirmar.');
                    }
                    $db->selectOne('select web_demo_meeting_confirm(?, ?, ?, ?)', [$id, $url, $d['meeting_provider'], $ref]);
                    $this->svc->mailVisitor($this->svc->meeting($id), 'confirmed');
                    break;
                case 'move':
                    $d = $request->validate(['starts_at' => ['required', 'date', 'after:now']], self::MESSAGES);
                    if (! in_array($m->status, ['proposed', 'confirmed', 'rescheduled'], true)) {
                        $this->fail('starts_at', 'Esta reunión ya no se puede mover.');
                    }
                    $start = Carbon::parse($d['starts_at']);
                    $len = (int) round((strtotime($m->ends_at) - strtotime($m->starts_at)) / 60);
                    $db->update(
                        "update demo_meetings set starts_at = ?, ends_at = ?, status = case when status = 'confirmed' then 'rescheduled' else status end,
                                reminder_24h_sent_at = null, reminder_1h_sent_at = null, updated_by = ? where id = ?",
                        [$start, $start->copy()->addMinutes($len), $actor, $id]
                    );
                    $db->update("update demo_requests set demo_scheduled_at = ? where id = ? and status = 'demo_scheduled'", [$start, $m->demo_request_id]);
                    $moved = $this->svc->meeting($id);
                    if ($moved->calendar_event_ref && $this->google->connected($moved->host_id)) {
                        $this->google->upsertEvent($moved, false);
                    }
                    $this->svc->mailVisitor($moved, 'rescheduled');
                    break;
                case 'cancel':
                    $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], self::MESSAGES + ['reason.required' => 'Escribe el motivo: se le envía al visitante.']);
                    if (! in_array($m->status, ['proposed', 'confirmed', 'rescheduled'], true)) {
                        $this->fail('reason', 'Esta reunión ya no se puede cancelar.');
                    }
                    $db->update("update demo_meetings set status = 'cancelled', cancel_reason = ?, updated_by = ? where id = ?", [$d['reason'], $actor, $id]);
                    $this->google->deleteEvent($m);
                    $this->svc->mailVisitor($this->svc->meeting($id), 'cancelled', null, $d['reason']);
                    break;
                case 'complete':
                    $d = $request->validate([
                        'no_show' => ['boolean'],
                        'outcome' => ['nullable', 'required_unless:no_show,true', 'in:'.implode(',', array_keys(self::OUTCOMES))],
                        'notes' => ['nullable', 'string', 'max:2000'],
                    ], self::MESSAGES + ['outcome.required_unless' => 'Elige el resultado de la reunión.']);
                    $db->selectOne('select web_demo_meeting_complete(?, ?, ?, ?)', [$id, $d['outcome'] ?? null, $d['notes'] ?? null, (bool) ($d['no_show'] ?? false)]);
                    break;
            }
        } catch (QueryException $e) {
            $this->overlap($e);
        }
        $accounts->audit($actor, "demo_meeting_{$action}", 'demo_meetings', $id, []);

        return response()->json(['data' => $this->svc->meeting($id)]);
    }

    /** Crea o actualiza el evento en Google si el anfitrión lo tiene conectado. Devuelve [enlace, id del evento]. */
    private function calendarFor(object $m, string $provider, ?string $url): array
    {
        if (! $this->google->connected($m->host_id)) {
            return [$url, null];
        }
        $m->meeting_provider = $provider;
        $ev = $this->google->upsertEvent($m, $provider === 'meet' && ! $url && ! $m->meeting_url);

        return [$url ?? $ev[1] ?? null, $ev[0] ?? $m->calendar_event_ref];
    }

    /** Enlace para conectar Google Calendar: el Dueño o la propia persona anfitriona. */
    public function googleConnect(Request $request, string $id): JsonResponse
    {
        $h = $this->db()->selectOne('select id, user_id from demo_hosts where id = ? and deleted_at is null', [$id]) ?? throw ApiProblem::notFound('El anfitrión no existe.');
        if (! $this->isOwner($request) && $h->user_id !== $request->user()->id) {
            throw new ApiProblem(403, 'forbidden', 'Sin permiso', 'Solo el Dueño o la propia persona conectan su calendario.');
        }
        if (! $this->google->configured()) {
            throw new ApiProblem(409, 'not_configured', 'Falta configurar Google', 'Aún no se cargaron las credenciales de Google en el servidor (GOOGLE_CLIENT_ID y GOOGLE_CLIENT_SECRET).');
        }

        return response()->json(['data' => ['url' => $this->google->authUrl($h->id, $request->user()->id)]]);
    }

    public function googleDisconnect(Request $request, string $id, AccountsService $accounts): Response
    {
        $h = $this->db()->selectOne('select id, user_id from demo_hosts where id = ? and deleted_at is null', [$id]) ?? throw ApiProblem::notFound('El anfitrión no existe.');
        if (! $this->isOwner($request) && $h->user_id !== $request->user()->id) {
            throw new ApiProblem(403, 'forbidden', 'Sin permiso', 'Solo el Dueño o la propia persona desconectan su calendario.');
        }
        $this->google->disconnect($id);
        $accounts->audit($request->user()->id, 'demo_host_calendar_disconnected', 'demo_hosts', $id, []);

        return response()->noContent();
    }

    /** Google vuelve aquí en el navegador (sin sesión): el estado cifrado dice qué anfitrión conectar. */
    public function googleCallback(Request $request)
    {
        $back = rtrim((string) config('escudo.frontend_url'), '/').'/plataforma/sitio/agenda?tab=hosts';
        if ($request->query('error') || ! $request->query('code') || ! $request->query('state')) {
            return redirect()->away($back.'&google=cancelado');
        }
        try {
            $this->google->handleCallback((string) $request->query('code'), (string) $request->query('state'));

            return redirect()->away($back.'&google=ok');
        } catch (\Throwable $e) {
            report($e);

            return redirect()->away($back.'&google=error');
        }
    }

    private function overlap(QueryException $e): never
    {
        $state = $e->errorInfo[0] ?? '';
        if ($state === '23P01') {
            $this->fail('starts_at', 'El anfitrión ya tiene otra reunión confirmada a esa hora.', 409);
        }
        if (in_array($state, ['23514', 'P0001'], true)) {
            $this->fail('starts_at', 'No se pudo: revisa el estado de la reunión y los datos.', 409);
        }
        throw $e;
    }
}
