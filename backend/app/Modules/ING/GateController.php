<?php

namespace App\Modules\ING;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\REC\RecordsService;
use App\Modules\REC\Registry;
use App\Modules\REC\Rules\PeopleRules;
use App\Modules\REC\Rules\WorkerRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Portería (M-ING SCR-ING-01/02/03), solo para quien opera la garita: permiso `ing.access_events.create` (M-ING §4:
 * supervisor SST; también Jefe SST y consultor por la matriz de docs/05), acotado a los puntos de acceso de sus sedes.
 *  GET  /ing/gate                 puntos de acceso activos e indicadores del día
 *  POST /ing/gate/scan            evalúa un QR o código (token del fotocheck, N.° de fotocheck o DNI) y registra el evento
 *  POST /ing/gate/visitor         registra el ingreso o la salida de un visitante (con inducción de visita aceptada)
 *  POST /ing/gate/exit            registra la salida de quien está dentro (a partir de su evento de ingreso)
 *  GET  /ing/inside               quiénes están dentro hoy (último evento de hoy = ingreso permitido)
 *
 * El resultado lo calcula la regla común de access_events (AddonRules::access, RN-ING-01) al crearlo por el motor de
 * registros: así quedan la auditoría y la inalterabilidad (RN-ING-03). Los motivos que ve el operador nunca llevan
 * términos médicos (RN-ING-02): se muestran como «Requisito pendiente». Un ingreso por excepción exige motivo y
 * avisa al Jefe SST (RN-ING-04).
 */
class GateController extends Controller
{
    public function __construct(private RecordsService $records) {}

    public function index(): JsonResponse
    {
        $sites = $this->scopedSites();
        $points = DB::select(
            'select p.id, p.code, p.name, p.point_type, p.allows_visitors, p.requires_credential, s.name as site
               from access_points p left join sites s on s.id = p.site_id
              where p.deleted_at is null and p.is_active'.($sites ? ' and p.site_id = any(?::uuid[])' : '').' order by s.name nulls last, p.name',
            $sites ? ['{'.implode(',', $sites).'}'] : []);

        return response()->json(['data' => ['points' => $points, 'kpis' => $this->kpis()]]);
    }

    public function scan(Request $request): JsonResponse
    {
        $in = $request->validate([
            'access_point_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:500'],
            'direction' => ['nullable', 'in:in,out'],
            'override_reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->point($in['access_point_id']);
        $subject = $this->resolve(trim($in['code']));
        if (! $subject) {
            return response()->json(['data' => [
                'result' => 'unknown', 'granted' => false, 'person' => null,
                'reasons' => ['Código no reconocido: no es un fotocheck de esta empresa. Pide un documento y avisa al área SST.'],
            ]]);
        }
        $override = trim((string) ($in['override_reason'] ?? ''));
        $values = [
            'access_point_id' => $in['access_point_id'],
            'direction' => $in['direction'] ?? 'in',
            'subject_type' => $subject['type'],
            $subject['type'] === 'worker' ? 'worker_id' : 'contractor_worker_id' => $subject['id'],
        ];
        if ($override !== '') {
            $values['result'] = 'overridden';
            $values['override_reason'] = $override;
        }
        $event = $this->create($values);
        if ($event['result'] === 'overridden') {
            $this->alertOverride($event['id'], $this->person($subject)['name'] ?? 'Persona', $override);
        }

        return response()->json(['data' => $this->present($event, $this->person($subject))], 201);
    }

    public function visitor(Request $request): JsonResponse
    {
        $in = $request->validate([
            'access_point_id' => ['required', 'uuid'],
            'full_name' => ['required', 'string', 'min:3', 'max:160'],
            'document_number' => ['required', 'string', 'max:20'],
            'host_name' => ['required_unless:direction,out', 'nullable', 'string', 'min:2', 'max:160'],
            'purpose' => ['nullable', 'string', 'max:300'],
            'induction_accepted' => ['nullable', 'boolean'],
            'direction' => ['nullable', 'in:in,out'],
        ], ['host_name.required_unless' => 'Indica a quién visita.']);
        $this->point($in['access_point_id']);
        $direction = $in['direction'] ?? 'in';
        $doc = strtoupper(preg_replace('/[\s.\-]/', '', $in['document_number']));
        if (! preg_match('/^[A-Z0-9]{6,15}$/', $doc)) {
            throw ValidationException::withMessages(['document_number' => 'Revisa el número de documento (DNI de 8 dígitos o carné de extranjería).']);
        }
        if ($direction === 'in' && empty($in['induction_accepted'])) {
            throw ValidationException::withMessages(['induction_accepted' => 'El visitante debe recibir y aceptar la inducción de visita antes de ingresar.']);
        }
        $event = $this->create([
            'access_point_id' => $in['access_point_id'],
            'direction' => $direction,
            'subject_type' => 'visitor',
            'visitor_name' => trim($in['full_name']),
            'visitor_document_masked' => '****'.substr($doc, -4),
        ]);
        // En la salida, si no se indica, se toma a quién visitó en su último ingreso.
        $host = trim((string) ($in['host_name'] ?? '')) ?: (DB::selectOne(
            "select host_name from access_visits where document_hash = decode(?, 'hex') order by created_at desc limit 1", [$this->visitorHash($doc)])?->host_name ?? 'Sin indicar');
        DB::insert(
            "insert into access_visits (company_id, access_event_id, host_name, purpose, document_hash, induction_accepted_at, created_by)
             values (?, ?, ?, ?, decode(?, 'hex'), ?, ?)",
            [app(CompanyContext::class)->companyId, $event['id'], $host, $in['purpose'] ?? null,
                $this->visitorHash($doc), $direction === 'in' ? now() : null, (string) auth()->id()]);

        return response()->json(['data' => $this->present($event, [
            'kind' => 'visitor', 'name' => $event['visitor_name'] ?? trim($in['full_name']), 'company' => 'Visitante',
            'document_masked' => '****'.substr($doc, -4), 'position' => 'Visita a '.$host, 'photo_file_id' => null,
        ])], 201);
    }

    public function exit(Request $request): JsonResponse
    {
        $in = $request->validate(['event_id' => ['required', 'uuid'], 'access_point_id' => ['nullable', 'uuid']]);
        $e = DB::selectOne("select * from access_events where id = ? and direction = 'in'", [$in['event_id']]);
        if (! $e) {
            throw ApiProblem::notFound('El ingreso no existe.');
        }
        $point = $in['access_point_id'] ?? $e->access_point_id;
        $this->point($point);
        $values = ['access_point_id' => $point, 'direction' => 'out', 'subject_type' => $e->subject_type];
        if ($e->subject_type === 'visitor') {
            $values += ['visitor_name' => $e->visitor_name, 'visitor_document_masked' => $e->visitor_document_masked];
        } elseif ($e->worker_id) {
            $values['worker_id'] = $e->worker_id;
        } else {
            $values['contractor_worker_id'] = $e->contractor_worker_id;
        }
        $out = $this->create($values);
        if ($e->subject_type === 'visitor') {
            $v = DB::selectOne("select host_name, encode(document_hash, 'hex') as h from access_visits where access_event_id = ?", [$e->id]);
            DB::insert("insert into access_visits (company_id, access_event_id, host_name, document_hash, created_by) values (?, ?, ?, decode(?, 'hex'), ?)",
                [app(CompanyContext::class)->companyId, $out['id'], $v->host_name ?? 'Sin indicar', $v->h ?? null, (string) auth()->id()]);
        }

        return response()->json(['data' => ['id' => $out['id'], 'direction' => 'out', 'occurred_at' => $out['occurred_at']]], 201);
    }

    public function inside(Request $request): JsonResponse
    {
        $point = (string) $request->query('access_point_id', '');
        $rows = DB::select($this->insideSql(Str::isUuid($point)), Str::isUuid($point) ? [$point] : []);

        return response()->json(['data' => ['people' => array_map(fn ($r) => [
            'event_id' => $r->id,
            'kind' => $r->subject_type,
            'name' => $r->name,
            'company' => $r->company,
            'document_masked' => $r->document_masked,
            'host_name' => $r->host_name,
            'access_point' => $r->point_name,
            'since' => $r->occurred_at,
            'overridden' => $r->result === 'overridden',
        ], $rows), 'kpis' => $this->kpis()]]);
    }

    // ── Apoyo ──

    /**
     * GET /ing/gate/roster?access_point_id= — RN-ING-06: lista de estados para operar sin conexión. Por cada fotocheck vigente:
     * huella SHA-256 del token del QR y del número (el navegador compara la huella del código leído), nombre, empresa y si
     * hoy pasaría por este punto. Sin datos médicos: lo pendiente se muestra como «Requisito pendiente».
     */
    public function roster(Request $request): JsonResponse
    {
        $in = $request->validate(['access_point_id' => ['required', 'uuid']]);
        $p = $this->point($in['access_point_id']);
        $out = [];
        foreach (DB::select(
            "select c.id, c.credential_type_id, c.worker_id, c.contractor_worker_id, c.expires_on, encode(c.token_hash, 'hex') as th, c.credential_number,
                    coalesce(w.full_name, trim(cw.first_name || ' ' || cw.paternal_last_name)) as name, coalesce(ct.legal_name, co.trade_name, co.legal_name) as company
               from credentials c
               left join workers w on w.id = c.worker_id and w.deleted_at is null and w.status = 'active'
               left join contractor_workers cw on cw.id = c.contractor_worker_id and cw.deleted_at is null and cw.status = 'active'
               left join contractors ct on ct.id = cw.contractor_id
               left join companies co on co.id = c.company_id
              where c.deleted_at is null and c.status <> 'revoked' and (w.id is not null or cw.id is not null)") as $c) {
            $ok = ! PeopleRules::credentialReasonItems($c->credential_type_id, $c->worker_id, $c->contractor_worker_id, $c->expires_on);
            if ($ok && $c->contractor_worker_id && $p->site_id) {
                $ok = (bool) DB::selectOne(
                    "select 1 as ok from contractor_accreditations a join contractor_workers w on w.contractor_id = a.contractor_id
                      where w.id = ? and a.deleted_at is null and a.status in ('accredited', 'expiring') and (a.expires_on is null or a.expires_on >= current_date)
                        and (a.starts_on is null or a.starts_on <= current_date) and (a.ends_on is null or a.ends_on >= current_date)
                        and (a.site_ids = '{}' or ?::uuid = any(a.site_ids)) and (a.contractor_worker_ids = '{}' or w.id = any(a.contractor_worker_ids))", [$c->contractor_worker_id, $p->site_id]);
            }
            $out[] = ['th' => $c->th, 'nh' => $c->credential_number ? hash('sha256', strtoupper($c->credential_number)) : null, 'name' => $c->name, 'company' => $c->company, 'ok' => $ok,
                'note' => $ok ? null : 'Requisito pendiente'];
        }

        return response()->json(['data' => ['access_point_id' => $p->id, 'generated_at' => now()->toIso8601String(),
            'max_age_hours' => (int) \App\Core\Support\LegalParams::number('access_offline_max_age', 12), 'people' => $out]]);
    }

    /**
     * POST /ing/gate/sync {access_point_id, events: [{hash, direction, occurred_at, client_id}]} — eventos registrados sin conexión:
     * se crean con su hora real, marcados offline (RN-ING-06) y sin duplicarse si se envían dos veces (client_id).
     */
    public function sync(Request $request): JsonResponse
    {
        $in = $request->validate([
            'access_point_id' => ['required', 'uuid'],
            'events' => ['required', 'array', 'max:500'],
            'events.*.hash' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'events.*.direction' => ['required', 'in:in,out'],
            'events.*.occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'events.*.client_id' => ['required', 'string', 'max:80'],
        ]);
        $this->point($in['access_point_id']);
        $done = 0;
        $unknown = 0;
        foreach ($in['events'] as $e) {
            $key = 'offline:'.$e['client_id'];
            if (DB::selectOne('select 1 as ok from access_events where idempotency_key = ?', [$key])) {
                $done++;
                continue;
            }
            $cred = DB::selectOne("select worker_id, contractor_worker_id from credentials where deleted_at is null and (token_hash = decode(?, 'hex') or encode(sha256(convert_to(upper(credential_number), 'UTF8')), 'hex') = ?) limit 1",
                [$e['hash'], $e['hash']]);
            if (! $cred) {
                $unknown++;
                continue;
            }
            // Los eventos de portería son inalterables: la marca offline y la clave van al crearlos (AddonRules::access).
            $request->attributes->set('ing_offline', ['key' => $key]);
            $this->create(['access_point_id' => $in['access_point_id'], 'direction' => $e['direction'], 'occurred_at' => $e['occurred_at'],
                'subject_type' => $cred->worker_id ? 'worker' : 'contractor_worker', $cred->worker_id ? 'worker_id' : 'contractor_worker_id' => $cred->worker_id ?? $cred->contractor_worker_id]);
            $request->attributes->remove('ing_offline');
            $done++;
        }

        return response()->json(['data' => ['synced' => $done, 'unknown' => $unknown]]);
    }

    /** GET /ing/reports.xlsx?from=&to=&by=day|point|contractor — M-ING §11: ingresos por día, punto o empresa contratista. */
    public function report(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $d = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'by' => ['nullable', 'in:day,point,contractor']]);
        $from = $d['from'] ?? now('America/Lima')->startOfMonth()->toDateString();
        $to = $d['to'] ?? now('America/Lima')->toDateString();
        $by = $d['by'] ?? 'day';
        [$group, $label] = match ($by) {
            'point' => ["coalesce(p.name, 'Sin punto')", 'Punto de acceso'],
            'contractor' => ["coalesce(ct.legal_name, case e.subject_type when 'worker' then 'Personal propio' when 'visitor' then 'Visitas' else 'Otros' end)", 'Empresa'],
            default => ["to_char((e.occurred_at at time zone 'America/Lima')::date, 'YYYY-MM-DD')", 'Día'],
        };
        $rows = DB::select(
            "select {$group} as g, count(*) filter (where e.direction = 'in' and e.result in ('granted', 'overridden')) as ins,
                    count(*) filter (where e.direction = 'out') as outs, count(*) filter (where e.result = 'denied') as denied,
                    count(*) filter (where e.result = 'overridden') as overridden, count(*) filter (where e.is_offline) as offline,
                    count(distinct coalesce(e.worker_id, e.contractor_worker_id)) as people
               from access_events e left join access_points p on p.id = e.access_point_id
               left join contractor_workers cw on cw.id = e.contractor_worker_id left join contractors ct on ct.id = cw.contractor_id
              where (e.occurred_at at time zone 'America/Lima')::date between ? and ? group by 1 order by 1", [$from, $to]);
        $out = [["Ingresos por {$label}"], ["Del {$from} al {$to}."], [], [$label, 'Ingresos', 'Salidas', 'Negados', 'Por excepción', 'Sin conexión', 'Personas distintas']];
        foreach ($rows as $r) {
            $out[] = [$r->g, (int) $r->ins, (int) $r->outs, (int) $r->denied, (int) $r->overridden, (int) $r->offline, (int) $r->people];
        }

        return response(\App\Core\Support\Xlsx::build('Ingresos', $out, 4), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"ingresos-{$by}-{$from}-{$to}.xlsx\"",
        ]);
    }

    /** Crea el evento por el motor de registros (regla de acceso, auditoría) y lo devuelve tal como quedó. */
    private function create(array $values): array
    {
        $res = Registry::resource('ING', 'access_events');
        $this->records->authorize($res, 'create');
        $row = $this->records->create($res, $values);
        $raw = DB::selectOne('select id, result, direction, occurred_at, deny_reasons, visitor_name from access_events where id = ?', [$row['id']]);

        return ['id' => $raw->id, 'result' => $raw->result, 'direction' => $raw->direction, 'occurred_at' => $raw->occurred_at,
            'deny_reasons' => json_decode((string) ($raw->deny_reasons ?? '[]'), true) ?: [], 'visitor_name' => $raw->visitor_name];
    }

    /**
     * Sedes de la membresía (membership_scopes «site»): quien opera la portería solo ve y usa los puntos de acceso de sus
     * sedes (M-ING §4). Sin alcance por sede: todas las de la empresa.
     *
     * @return list<string>
     */
    private function scopedSites(): array
    {
        return array_column(DB::select(
            "select distinct site_id from membership_scopes where membership_id = ? and scope_type = 'site' and site_id is not null and deleted_at is null",
            [app(CompanyContext::class)->membershipId]), 'site_id');
    }

    private function point(string $id): object
    {
        $p = DB::selectOne('select id, name, is_active, site_id from access_points where id = ? and deleted_at is null', [$id]);
        if (! $p) {
            throw ValidationException::withMessages(['access_point_id' => 'Elige un punto de acceso de esta empresa.']);
        }
        $sites = $this->scopedSites();
        if ($sites && ! in_array($p->site_id, $sites, true)) {
            throw ValidationException::withMessages(['access_point_id' => 'Ese punto de acceso no es de la sede que tienes asignada.']);
        }

        return $p;
    }

    /**
     * Qué persona corresponde al código leído: la URL o el token del QR del fotocheck, el número de fotocheck
     * (FOT-2026-00001) o el DNI (trabajador propio o de un contratista).
     *
     * @return array{type: string, id: string}|null
     */
    private function resolve(string $code): ?array
    {
        if (preg_match('~/verificar/fotocheck/([A-Za-z0-9]{8,64})~', $code, $m)) {
            $code = $m[1];
        }
        $up = strtoupper($code);
        $cred = null;
        if (preg_match('/^[A-F0-9]{32}$/', $up)) {
            $cred = DB::selectOne("select worker_id, contractor_worker_id from credentials where token_hash = decode(?, 'hex') and deleted_at is null", [hash('sha256', $up)]);
        }
        if (! $cred && preg_match('/^[A-Z]{2,5}-\d{4}-\d{3,8}$/', $up)) {
            $cred = DB::selectOne('select worker_id, contractor_worker_id from credentials where credential_number = ? and deleted_at is null', [$up]);
        }
        if ($cred) {
            return $cred->worker_id ? ['type' => 'worker', 'id' => $cred->worker_id] : ['type' => 'contractor_worker', 'id' => $cred->contractor_worker_id];
        }
        $doc = preg_replace('/[\s.\-]/', '', $up);
        if (preg_match('/^\d{8}$/', $doc)) {
            $w = DB::selectOne("select id from workers where document_number_hash = decode(?, 'hex') and deleted_at is null order by (status = 'active') desc limit 1",
                [WorkerRules::documentHash('DNI', $doc)]);
            if ($w) {
                return ['type' => 'worker', 'id' => $w->id];
            }
            $cw = DB::selectOne("select id from contractor_workers where document_number_hash = decode(?, 'hex') and deleted_at is null order by (status = 'active') desc limit 1",
                [hash_hmac('sha256', 'contractor-doc|DNI|'.$doc, (string) config('app.key'))]);
            if ($cw) {
                return ['type' => 'contractor_worker', 'id' => $cw->id];
            }
        }

        return null;
    }

    /** Nombre, foto, puesto y empresa de la persona (nunca datos médicos). */
    private function person(array $s): array
    {
        if ($s['type'] === 'worker') {
            $w = DB::selectOne('select w.full_name, w.document_last4, w.photo_file_id, p.name as position, c.trade_name, c.legal_name
                                  from workers w left join positions p on p.id = w.current_position_id join companies c on c.id = w.company_id where w.id = ?', [$s['id']]);

            return ['kind' => 'worker', 'name' => $w?->full_name, 'company' => $w ? ($w->trade_name ?: $w->legal_name) : null,
                'document_masked' => $w?->document_last4 ? '****'.$w->document_last4 : null, 'position' => $w?->position, 'photo_file_id' => $w?->photo_file_id];
        }
        $w = DB::selectOne("select concat_ws(' ', w.first_name, w.paternal_last_name, w.maternal_last_name) as full_name, w.document_last4, w.photo_file_id, w.position_text,
                                   c.legal_name, c.trade_name
                              from contractor_workers w join contractors c on c.id = w.contractor_id where w.id = ?", [$s['id']]);

        return ['kind' => 'contractor_worker', 'name' => $w?->full_name, 'company' => $w ? ($w->trade_name ?: $w->legal_name) : null,
            'document_masked' => $w?->document_last4 ? '****'.$w->document_last4 : null, 'position' => $w?->position_text, 'photo_file_id' => $w?->photo_file_id];
    }

    private function present(array $event, array $person): array
    {
        return [
            'id' => $event['id'],
            'result' => $event['result'],
            'granted' => $event['result'] !== 'denied',
            'direction' => $event['direction'],
            'occurred_at' => $event['occurred_at'],
            'person' => $person,
            'reasons' => self::safeReasons($event['deny_reasons']),
        ];
    }

    /**
     * Motivos para el operador sin términos médicos (RN-ING-02): lo relacionado con la aptitud o el examen médico
     * se muestra como «Requisito pendiente».
     *
     * @param  list<string>  $reasons
     * @return list<string>
     */
    public static function safeReasons(array $reasons): array
    {
        $out = [];
        foreach ($reasons as $r) {
            $r = (string) $r;
            if (str_starts_with($r, 'Fotocheck no habilitado: ')) {
                $parts = array_map(fn ($p) => self::medical($p) ? 'Requisito pendiente' : $p, explode(', ', substr($r, strlen('Fotocheck no habilitado: '))));
                $r = 'Fotocheck no habilitado: '.implode(', ', array_values(array_unique($parts)));
            } elseif (self::medical($r)) {
                $r = 'Requisito pendiente';
            }
            $out[] = $r;
        }

        return array_values(array_unique($out));
    }

    private static function medical(string $s): bool
    {
        return (bool) preg_match('/m[ée]dic|aptitud|\bEMO\b|examen|salud ocupacional|diagn/iu', $s);
    }

    private function visitorHash(string $doc): string
    {
        return hash_hmac('sha256', 'visitor-doc|'.$doc, (string) config('app.key'));
    }

    /** Aviso al Jefe SST de un ingreso por excepción (RN-ING-04). */
    private function alertOverride(string $eventId, string $name, string $reason): void
    {
        $company = app(CompanyContext::class)->companyId;
        $to = DB::select(
            "select distinct m.user_id from memberships m join roles r on r.id = m.role_id
              where m.company_id = ? and coalesce(r.base_role_code, r.code) = 'sst_manager' and m.status = 'active' and m.deleted_at is null", [$company]);
        foreach ($to as $u) {
            DB::insert(
                "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, dedupe_key)
                 values (?, 'ing.access_override', 'access_events', ?, ?, ?, ?, '/m/ing', 'high', ?)",
                [$company, $eventId, $u->user_id, 'Ingreso por excepción en portería',
                    $name.' ingresó sin estar habilitado. Motivo: '.mb_substr($reason, 0, 300), 'ing.override.'.$eventId.'.'.$u->user_id]);
        }
    }

    /** Indicadores de hoy (hora de Lima). */
    private function kpis(): array
    {
        $k = DB::selectOne(
            "select count(*) filter (where direction = 'in' and result in ('granted','overridden')) as granted,
                    count(*) filter (where result = 'denied' and direction = 'in') as denied,
                    count(*) filter (where result = 'overridden') as overridden
               from access_events where (occurred_at at time zone 'America/Lima')::date = (now() at time zone 'America/Lima')::date");
        $inside = (int) DB::selectOne('select count(*) as n from ('.$this->insideSql(false).') x')->n;

        return ['granted_today' => (int) $k->granted, 'denied_today' => (int) $k->denied, 'overridden_today' => (int) $k->overridden, 'inside' => $inside];
    }

    /**
     * Personas cuyo último evento de hoy es un ingreso permitido (habilitado o por excepción). Un ingreso negado
     * no cuenta; cualquier salida, sí. Los visitantes se reconocen por su documento (HMAC) o, si falta, por nombre.
     */
    private function insideSql(bool $byPoint): string
    {
        return "with today as (
                  select e.*, v.host_name,
                         coalesce(e.worker_id::text, e.contractor_worker_id::text, 'v:' || coalesce(encode(v.document_hash, 'hex'), lower(e.visitor_name))) as subject_key
                    from access_events e left join access_visits v on v.access_event_id = e.id
                   where (e.occurred_at at time zone 'America/Lima')::date = (now() at time zone 'America/Lima')::date
                     and not (e.direction = 'in' and e.result = 'denied')
                ), last as (
                  select distinct on (subject_key) * from today order by subject_key, occurred_at desc, id desc
                )
                select l.id, l.subject_type, l.result, l.occurred_at, l.host_name, p.name as point_name,
                       coalesce(w.full_name, nullif(concat_ws(' ', cw.first_name, cw.paternal_last_name), ''), l.visitor_name) as name,
                       case when l.subject_type = 'visitor' then 'Visitante' when cw.id is not null then coalesce(ct.trade_name, ct.legal_name) else 'Personal propio' end as company,
                       coalesce('****' || w.document_last4, '****' || cw.document_last4, l.visitor_document_masked) as document_masked
                  from last l
                  join access_points p on p.id = l.access_point_id
                  left join workers w on w.id = l.worker_id
                  left join contractor_workers cw on cw.id = l.contractor_worker_id
                  left join contractors ct on ct.id = cw.contractor_id
                 where l.direction = 'in'".($byPoint ? ' and l.access_point_id = ?' : '').'
                 order by l.occurred_at desc';
    }
}
