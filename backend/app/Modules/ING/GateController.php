<?php

namespace App\Modules\ING;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\REC\RecordsService;
use App\Modules\REC\Registry;
use App\Modules\REC\Rules\WorkerRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Portería (M-ING SCR-ING-01/02/03):
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
        $points = DB::select(
            'select p.id, p.code, p.name, p.point_type, p.allows_visitors, p.requires_credential, s.name as site
               from access_points p left join sites s on s.id = p.site_id
              where p.deleted_at is null and p.is_active order by s.name nulls last, p.name');

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

    private function point(string $id): object
    {
        $p = DB::selectOne('select id, name, is_active from access_points where id = ? and deleted_at is null', [$id]);
        if (! $p) {
            throw ValidationException::withMessages(['access_point_id' => 'Elige un punto de acceso de esta empresa.']);
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
              where m.company_id = ? and r.code = 'sst_manager' and m.status = 'active' and m.deleted_at is null", [$company]);
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
