<?php

namespace App\Modules\TRB;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\FIR\SignatureImages;
use App\Modules\FIR\SignatureService;
use App\Modules\PSI\PsychosocialSurvey;
use App\Modules\REC\Registry;
use App\Modules\REC\Rules\PeopleRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * App del trabajador (docs/07 §5.2; docs/05 §4.3): solo sus propios datos, siempre por el trabajador de su membresía.
 *
 *  GET   /me/worker                      Mi ficha (SCR-TRB-06) y resumen de las tarjetas del inicio
 *  PATCH /me/worker/profile              editar su celular y su contacto de emergencia
 *  GET   /me/worker/credential           Mi fotocheck (SCR-FOT-05): estado habilitado / no habilitado y QR de verificación
 *  GET   /me/worker/pending              Pendientes: firmas (FIR), cargos de documentos (DOC) y del RISST, EPP por recibir,
 *                                        cursos asignados (AUL), encuestas abiertas (PSI) y elección (COM)
 *  GET   /me/worker/items/{kind}/{id}    detalle de un pendiente para leer antes de firmar
 *  POST  /me/worker/sign                 firmar con PIN {kind: signature|document|risst|ppe, id, pin, image?}
 *  GET   /me/worker/trainings            Mis capacitaciones, certificados, cursos del aula y «Recomendados para ti»
 *  GET   /me/worker/ppe                  Mi EPP (SCR-EPP-06): entregas con vencimiento y si falta firmar la recepción
 *  GET   /me/worker/reports              Mis reportes (SCR-ACT-06) con su estado
 *
 * Un id de otro trabajador responde 404, igual que uno inexistente.
 */
class WorkerSelfController extends Controller
{
    private const KINDS = ['signature', 'document', 'risst', 'ppe'];

    public function home(): JsonResponse
    {
        $w = WorkerSelf::row();
        $ctx = app(CompanyContext::class);
        $today = WorkerSelf::today();
        $mods = [];
        foreach (['FOT', 'FIR', 'DOC', 'RIS', 'CAP', 'AUL', 'EPP', 'EMO', 'ACT', 'COM', 'PSI', 'OFF'] as $c) {
            $mods[$c] = WorkerSelf::usable($c);
        }
        $cred = $mods['FOT'] ? DB::selectOne("select status, expires_on from credentials where worker_id = ? and deleted_at is null and status <> 'revoked' order by issued_at desc nulls last limit 1", [$w->id]) : null;
        $ppe = $mods['EPP'] ? DB::selectOne("select count(*) as n, count(*) filter (where signature_id is null) as unsigned, count(*) filter (where expires_on between ?::date and ?::date + 30) as expiring
                                              from ppe_deliveries where worker_id = ? and deleted_at is null and status = 'delivered'", [$today, $today, $w->id]) : null;
        $fit = $mods['EMO'] ? DB::selectOne('select status, valid_until from fitness_status where worker_id = ? and is_current and deleted_at is null limit 1', [$w->id]) : null;
        $certs = $mods['CAP'] ? (int) DB::selectOne("select count(*) as n from training_certificates where worker_id = ? and deleted_at is null and status = 'validated'", [$w->id])->n : 0;
        $courses = $mods['AUL'] ? (int) DB::selectOne("select count(*) as n from course_enrollments where worker_id = ? and deleted_at is null and status in ('enrolled', 'in_progress')", [$w->id])->n : 0;
        $reports = $mods['ACT'] ? (int) DB::selectOne('select count(*) as n from substandard_reports where (reporter_worker_id = ? or reporter_user_id = ?) and deleted_at is null', [$w->id, (string) auth()->id()])->n : 0;

        return response()->json(['data' => [
            'worker' => [
                'id' => $w->id, 'full_name' => $w->full_name, 'first_name' => Str::before(trim((string) $w->first_name), ' ') ?: $w->first_name,
                'document_masked' => $w->document_last4 ? '****'.$w->document_last4 : null, 'position' => $w->position, 'area' => $w->area, 'site' => $w->site,
                'hire_on' => $w->hire_on, 'phone' => WorkerAppAccess::decrypt($w->phone_e164),
                'emergency_contact_name' => WorkerAppAccess::decrypt($w->emergency_contact_name), 'emergency_contact_phone' => WorkerAppAccess::decrypt($w->emergency_contact_phone),
            ],
            'company' => ['id' => $ctx->companyId, 'name' => $ctx->companyName],
            'modules' => $mods,
            'summary' => [
                'credential' => $cred ? ['status' => $cred->status, 'expires_on' => $cred->expires_on] : null,
                'pending' => count($this->pendingItems($w)),
                'ppe' => $ppe ? ['total' => (int) $ppe->n, 'unsigned' => (int) $ppe->unsigned, 'expiring' => (int) $ppe->expiring] : null,
                'fitness' => $fit ? ['status' => $fit->status, 'valid_until' => $fit->valid_until] : null,
                'certificates' => $certs, 'courses' => $courses, 'reports' => $reports,
                'election' => $mods['COM'] ? $this->election($w->id) : null,
            ],
        ]]);
    }

    public function profile(Request $request): JsonResponse
    {
        $w = WorkerSelf::row();
        $d = $request->validate([
            'phone' => ['nullable', 'string', 'max:20'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:20'],
        ], [], ['phone' => 'celular', 'emergency_contact_name' => 'contacto de emergencia', 'emergency_contact_phone' => 'celular del contacto']);
        $sets = [];
        $bind = [];
        foreach (['phone' => 'phone_e164', 'emergency_contact_name' => 'emergency_contact_name', 'emergency_contact_phone' => 'emergency_contact_phone'] as $k => $col) {
            if (! $request->has($k)) {
                continue;
            }
            $v = trim((string) ($d[$k] ?? ''));
            if ($v !== '' && $k !== 'emergency_contact_name') {
                $digits = preg_replace('/\D+/', '', $v);
                if (strlen($digits) < 7 || strlen($digits) > 15) {
                    throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Escribe un número de celular válido.', ['errors' => [$k => ['Escribe un número válido.']]]);
                }
                $v = '+'.(strlen($digits) === 9 && $digits[0] === '9' ? '51'.$digits : $digits);
            }
            $sets[] = "{$col} = ?";
            $bind[] = $v === '' ? null : Crypt::encryptString($v);
        }
        if ($sets) {
            DB::update('update workers set '.implode(', ', $sets).', updated_by = ? where id = ?', [...$bind, (string) auth()->id(), $w->id]);
            WorkerAppAccess::audit(app(CompanyContext::class)->companyId, (string) auth()->id(), 'worker', 'worker_self_profile_updated', $w->id, ['fields' => array_keys(array_intersect_key($d, array_flip(['phone', 'emergency_contact_name', 'emergency_contact_phone'])))]);
        }

        return $this->home();
    }

    public function credential(): JsonResponse
    {
        $this->module('FOT');
        $w = WorkerSelf::row();
        $c = DB::selectOne(
            "select c.*, t.name as type_name from credentials c left join credential_types t on t.id = c.credential_type_id
              where c.worker_id = ? and c.deleted_at is null and c.status <> 'revoked' order by (c.status = 'active') desc, c.issued_at desc nulls last limit 1", [$w->id]);
        if (! $c) {
            return response()->json(['data' => ['credential' => null, 'enabled' => false, 'reasons' => ['Aún no tienes fotocheck emitido.'],
                'worker' => $this->holder($w)]]);
        }
        $today = WorkerSelf::today();
        $reasons = PeopleRules::credentialReasons($c->credential_type_id, $w->id, null, $c->expires_on);
        if ($c->expires_on && $c->expires_on < $today) {
            $reasons[] = 'El fotocheck venció el '.date('d/m/Y', strtotime($c->expires_on)).'.';
        }
        if (! in_array($c->status, ['active', 'issued'], true)) {
            $reasons[] = $c->status === 'blocked' ? 'El fotocheck está bloqueado.' : ($c->status === 'draft' ? 'El fotocheck aún no se emite.' : 'El fotocheck no está vigente.');
        }
        // Mismo token del QR que el fotocheck impreso (PrintController::credential).
        $token = PeopleRules::credentialToken($c->company_id, $c->credential_number);
        if (bin2hex(WorkerAppAccess::bytes($c->token_hash)) !== hash('sha256', $token)) {
            DB::update("update credentials set token_hash = decode(?, 'hex'), token_last4 = ? where id = ?", [hash('sha256', $token), substr($token, -4), $c->id]);
        }

        return response()->json(['data' => [
            'credential' => ['id' => $c->id, 'number' => $c->credential_number, 'type' => $c->type_name, 'status' => $c->status, 'valid_from' => $c->valid_from, 'expires_on' => $c->expires_on],
            'enabled' => $reasons === [],
            'reasons' => array_values(array_unique($reasons)),
            'verify_url' => rtrim((string) config('escudo.frontend_url'), '/').'/verificar/fotocheck/'.$token,
            'worker' => $this->holder($w),
        ]]);
    }

    public function pending(): JsonResponse
    {
        return response()->json(['data' => $this->pendingItems(WorkerSelf::row())]);
    }

    public function item(string $kind, string $id): JsonResponse
    {
        $w = WorkerSelf::row();
        if (! in_array($kind, self::KINDS, true) || ! Str::isUuid($id)) {
            throw ApiProblem::notFound('No encontramos ese pendiente.');
        }
        [, , $meta] = $this->subject($kind, $id, $w);
        unset($meta['request']);

        return response()->json(['data' => $meta + ['kind' => $kind, 'id' => $id]]);
    }

    public function sign(Request $request): JsonResponse
    {
        $w = WorkerSelf::row();
        $kind = (string) $request->input('kind');
        $id = (string) $request->input('id');
        if (! in_array($kind, self::KINDS, true) || ! Str::isUuid($id)) {
            throw ApiProblem::notFound('No encontramos ese pendiente.');
        }
        [$table, $row, $meta] = $this->subject($kind, $id, $w);
        if ($meta['signed'] ?? false) {
            throw new ApiProblem(409, 'conflict', 'Ya firmaste', 'Este pendiente ya está firmado.');
        }
        WorkerAppAccess::requirePin($w->id, $request->input('pin'));
        $ctx = app(CompanyContext::class);
        $uid = (string) auth()->id();
        $image = $request->input('image') ?: null;

        if ($kind === 'signature') {
            $hash = SignatureService::hash($table, $row);
            if (! hash_equals(bin2hex(WorkerAppAccess::bytes($meta['request']->document_hash)), $hash)) {
                throw new ApiProblem(409, 'conflict', 'El registro cambió', 'El registro cambió después de pedir tu firma. Pide al área de SST que vuelva a enviarla.');
            }
            $sig = $this->signature($ctx->companyId, $meta['request']->id, $table, $row->id, $w, $uid, $hash, $image);
            DB::update("update signature_requests set status = 'signed', signed_at = now(), updated_by = ? where id = ?", [$uid, $meta['request']->id]);
        } else {
            $hash = SignatureService::hash($table, $row);
            $order = (int) DB::selectOne('select coalesce(max(sequence_order), 0) + 1 as n from signature_requests where subject_type = ? and subject_id = ? and deleted_at is null', [$table, $row->id])->n;
            $req = DB::selectOne(
                "insert into signature_requests (company_id, subject_type, subject_id, subject_module, document_hash, signer_user_id, signer_worker_id, role_in_document,
                    sequence_order, status, requested_by, signed_at, created_by, updated_by)
                 values (?, ?, ?, ?, decode(?, 'hex'), ?, ?, 'worker', ?, 'signed', ?, now(), ?, ?) returning id",
                [$ctx->companyId, $table, $row->id, Registry::data()['resources'][$table]['module'] ?? null, $hash, $uid, $w->id, $order, $uid, $uid, $uid])->id;
            $sig = $this->signature($ctx->companyId, $req, $table, $row->id, $w, $uid, $hash, $image);
            match ($kind) {
                'document' => DB::insert("insert into document_acknowledgements (company_id, document_version_id, worker_id, user_id, method, signature_id, acknowledged_at, ip, user_agent, created_by)
                                          values (?, ?, ?, ?, 'app_signature', ?, now(), ?::inet, ?, ?)",
                    [$ctx->companyId, $row->id, $w->id, $uid, $sig, $request->ip(), mb_substr((string) $request->userAgent(), 0, 300), $uid]),
                'risst' => DB::update("update risst_deliveries set status = 'signed', method = 'app_signature', signature_id = ?, delivered_on = coalesce(delivered_on, ?::date), updated_by = ? where id = ?",
                    [$sig, WorkerSelf::today(), $uid, $row->id]),
                'ppe' => DB::update('update ppe_deliveries set signature_id = ?, updated_by = ? where id = ?', [$sig, $uid, $row->id]),
            };
        }
        DB::insert("insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes, ip, user_agent)
                    values (?, ?, 'worker', 'user', 'signature_signed', ?, ?, ?, ?::jsonb, ?::inet, ?)",
            [$ctx->companyId, $uid, Registry::data()['resources'][$table]['module'] ?? 'FIR', $table, $row->id, json_encode(['signature' => $sig, 'method' => 'pin', 'app' => 'worker']),
                $request->ip(), mb_substr((string) $request->userAgent(), 0, 300)]);

        return response()->json(['data' => ['status' => 'signed', 'signature_id' => $sig]]);
    }

    public function trainings(): JsonResponse
    {
        $w = WorkerSelf::row();
        $cap = WorkerSelf::usable('CAP');
        $aul = WorkerSelf::usable('AUL');

        return response()->json(['data' => [
            'attendances' => $cap ? DB::select(
                'select a.id, t.title, s.starts_at, coalesce(a.hours, t.duration_minutes / 60.0) as hours, a.attended, s.status
                   from training_attendances a join training_sessions s on s.id = a.session_id join trainings t on t.id = s.training_id
                  where a.worker_id = ? and a.deleted_at is null and s.deleted_at is null order by s.starts_at desc limit 100', [$w->id]) : [],
            'upcoming' => $cap ? DB::select(
                "select s.id, t.title, s.starts_at, s.location, s.virtual_url from training_attendances a join training_sessions s on s.id = a.session_id join trainings t on t.id = s.training_id
                  where a.worker_id = ? and a.deleted_at is null and s.status = 'scheduled' and s.starts_at > now() order by s.starts_at limit 20", [$w->id]) : [],
            'certificates' => $cap ? DB::select(
                "select id, course_title, certificate_code, issued_on, expires_on, hours, status from training_certificates
                  where worker_id = ? and deleted_at is null and status in ('validated', 'expired') order by issued_on desc", [$w->id]) : [],
            'aul_enabled' => $aul,
            'courses' => $aul ? DB::select(
                "select e.id, c.title, c.hours, e.status, e.progress_pct, e.due_on, e.passed, e.certificate_id from course_enrollments e join courses c on c.id = e.course_id
                  where e.worker_id = ? and e.deleted_at is null and e.status <> 'cancelled' order by (e.status in ('enrolled','in_progress')) desc, e.enrolled_at desc", [$w->id]) : [],
            'recommended' => $aul ? DB::select(
                "select r.id, c.title, c.hours, coalesce(r.message, '') as message, r.reason_code from course_recommendations r join courses c on c.id = r.course_id
                  where r.worker_id = ? and r.deleted_at is null and r.status in ('sent', 'viewed', 'clicked')
                    and not exists (select 1 from course_enrollments e where e.course_id = r.course_id and e.worker_id = r.worker_id and e.deleted_at is null and e.status <> 'cancelled')
                  order by r.created_at desc limit 10", [$w->id]) : [],
        ]]);
    }

    public function ppe(): JsonResponse
    {
        $this->module('EPP');
        $w = WorkerSelf::row();
        $today = WorkerSelf::today();
        $rows = DB::select(
            'select d.id, i.name as item, d.quantity, d.size, d.delivered_at, d.expires_on, d.status, d.signature_id is not null as signed
               from ppe_deliveries d join ppe_items i on i.id = d.ppe_item_id
              where d.worker_id = ? and d.deleted_at is null order by d.delivered_at desc limit 200', [$w->id]);
        foreach ($rows as $r) {
            $r->signed = (bool) $r->signed;
            $r->expiry = ! $r->expires_on ? 'none' : ($r->expires_on < $today ? 'expired' : ($r->expires_on <= date('Y-m-d', strtotime($today.' +30 days')) ? 'soon' : 'ok'));
        }

        return response()->json(['data' => $rows]);
    }

    public function reports(): JsonResponse
    {
        $this->module('ACT');
        $w = WorkerSelf::row();

        return response()->json(['data' => DB::select(
            'select id, code, report_type, description, location_text, status, reported_at, rejection_reason, closed_at
               from substandard_reports where (reporter_worker_id = ? or reporter_user_id = ?) and deleted_at is null order by reported_at desc limit 100',
            [$w->id, (string) auth()->id()])]);
    }

    // ── Apoyo ──

    /** @return list<array<string, mixed>> */
    private function pendingItems(object $w): array
    {
        $uid = (string) auth()->id();
        $items = [];
        if (WorkerSelf::usable('FIR')) {
            foreach (DB::select(
                "select id, subject_type, subject_id, role_in_document, expires_at, created_at from signature_requests
                  where status = 'pending' and deleted_at is null and (signer_worker_id = ? or signer_user_id = ?) and (expires_at is null or expires_at > now())
                  order by created_at", [$w->id, $uid]) as $r) {
                $items[] = ['kind' => 'signature', 'id' => $r->id, 'title' => 'Firmar: '.(Registry::data()['resources'][$r->subject_type]['title'] ?? 'registro'),
                    'detail' => $r->expires_at ? 'Vence el '.date('d/m/Y', strtotime($r->expires_at)) : 'Firma pendiente', 'due' => $r->expires_at, 'at' => $r->created_at];
            }
        }
        if (WorkerSelf::usable('DOC')) {
            foreach (DB::select(
                "select distinct on (v.id) v.id, d.code, d.title, v.version_number, v.version_label, n.created_at
                   from notifications n join document_versions v on v.id = n.subject_id join documents d on d.id = v.document_id
                  where n.event_code in ('doc.ack_requested', 'doc.ack_reminder') and n.recipient_worker_id = ? and n.deleted_at is null
                    and v.status = 'current' and v.deleted_at is null
                    and not exists (select 1 from document_acknowledgements a where a.document_version_id = v.id and a.worker_id = ?)
                  order by v.id, n.created_at", [$w->id, $w->id]) as $r) {
                $items[] = ['kind' => 'document', 'id' => $r->id, 'title' => "Leer y firmar: {$r->title}",
                    'detail' => $r->code.' · '.($r->version_label ?: 'versión '.$r->version_number), 'due' => null, 'at' => $r->created_at];
            }
        }
        if (WorkerSelf::usable('RIS')) {
            foreach (DB::select(
                "select r.id, v.title, v.version_number, r.created_at from risst_deliveries r join risst_versions v on v.id = r.risst_version_id
                  where r.worker_id = ? and r.status = 'pending' and r.deleted_at is null order by r.created_at", [$w->id]) as $r) {
                $items[] = ['kind' => 'risst', 'id' => $r->id, 'title' => 'Firmar el cargo del RISST', 'detail' => "{$r->title} · versión {$r->version_number}", 'due' => null, 'at' => $r->created_at];
            }
        }
        if (WorkerSelf::usable('EPP')) {
            foreach (DB::select(
                "select d.id, i.name, d.quantity, d.delivered_at from ppe_deliveries d join ppe_items i on i.id = d.ppe_item_id
                  where d.worker_id = ? and d.signature_id is null and d.status = 'delivered' and d.deleted_at is null order by d.delivered_at", [$w->id]) as $r) {
                $items[] = ['kind' => 'ppe', 'id' => $r->id, 'title' => 'Confirmar entrega de EPP', 'detail' => "{$r->name} × {$r->quantity} · ".date('d/m/Y', strtotime($r->delivered_at)), 'due' => null, 'at' => $r->delivered_at];
            }
        }
        if (WorkerSelf::usable('AUL')) {
            foreach (DB::select(
                "select e.id, c.title, e.progress_pct, e.due_on, e.enrolled_at from course_enrollments e join courses c on c.id = e.course_id
                  where e.worker_id = ? and e.deleted_at is null and e.status in ('enrolled', 'in_progress') order by e.due_on nulls last, e.enrolled_at", [$w->id]) as $r) {
                $items[] = ['kind' => 'course', 'id' => $r->id, 'title' => "Curso: {$r->title}",
                    'detail' => 'Avance '.round((float) $r->progress_pct).'%'.($r->due_on ? ' · vence el '.date('d/m/Y', strtotime($r->due_on)) : ''), 'due' => $r->due_on, 'at' => $r->enrolled_at];
            }
        }
        if (WorkerSelf::usable('PSI')) {
            $today = WorkerSelf::today();
            foreach (DB::select("select id, title, ends_on, created_at from psychosocial_surveys where status = 'open' and deleted_at is null
                                  and (starts_on is null or starts_on <= ?::date) and (ends_on is null or ends_on >= ?::date)", [$today, $today]) as $s) {
                if (DB::selectOne("select 1 as ok from psychosocial_responses where survey_id = ? and respondent_token_hash = decode(?, 'hex')", [$s->id, PsychosocialSurvey::respondentHash($s->id, $w->id)])) {
                    continue;
                }
                $items[] = ['kind' => 'survey', 'id' => $s->id, 'title' => "Encuesta: {$s->title}", 'detail' => 'Es anónima'.($s->ends_on ? ' · hasta el '.date('d/m/Y', strtotime($s->ends_on)) : ''),
                    'due' => $s->ends_on, 'at' => $s->created_at, 'url' => PsychosocialSurvey::publicUrl(app(CompanyContext::class)->companyId, $s->id)];
            }
        }
        if (WorkerSelf::usable('COM') && ($e = $this->election($w->id)) && $e['on_roll'] && ! $e['has_voted']) {
            $items[] = ['kind' => 'election', 'id' => $e['id'], 'title' => 'Votar en la elección del Comité', 'detail' => $e['voting_closes_at'] ? 'Cierra el '.date('d/m/Y H:i', strtotime($e['voting_closes_at'])) : $e['title'], 'due' => $e['voting_closes_at'], 'at' => null];
        }

        return $items;
    }

    /** Elección con votación abierta y si el trabajador está en el padrón (como /com/my-election). */
    private function election(string $workerId): ?array
    {
        $e = DB::selectOne("select id, title, voting_opens_at, voting_closes_at from elections where deleted_at is null and status = 'voting_open' order by created_at desc limit 1");
        if (! $e || ($e->voting_closes_at && strtotime($e->voting_closes_at) <= time()) || ($e->voting_opens_at && strtotime($e->voting_opens_at) > time())) {
            return null;
        }
        $roll = DB::selectOne('select excluded, has_voted from election_roll where election_id = ? and worker_id = ?', [$e->id, $workerId]);

        return ['id' => $e->id, 'title' => $e->title, 'voting_closes_at' => $e->voting_closes_at, 'on_roll' => $roll && ! $roll->excluded, 'has_voted' => (bool) ($roll->has_voted ?? false)];
    }

    /**
     * Registro a firmar, solo si es del trabajador. @return array{0: string, 1: object, 2: array}
     */
    private function subject(string $kind, string $id, object $w): array
    {
        $uid = (string) auth()->id();
        $nf = fn () => ApiProblem::notFound('No encontramos ese pendiente.');
        $decl = fn (string $what) => "Declaro que recibí {$what} y que lo leí. Firmo con mi PIN.";
        switch ($kind) {
            case 'signature':
                $this->module('FIR');
                $r = DB::selectOne('select * from signature_requests where id = ? and deleted_at is null and (signer_worker_id = ? or signer_user_id = ?)', [$id, $w->id, $uid]) ?? throw $nf();
                $res = Registry::data()['resources'][$r->subject_type] ?? throw $nf();
                $row = DB::selectOne("select * from \"{$r->subject_type}\" where id = ?", [$r->subject_id]) ?? throw $nf();
                if ($r->status === 'pending' && $r->expires_at && strtotime($r->expires_at) < time()) {
                    throw new ApiProblem(409, 'conflict', 'Firma vencida', 'Esta firma venció. Pide al área de SST que la vuelva a enviar.');
                }
                $label = $res['label'] ?? null;
                $name = $label && isset($row->{$label}) && is_scalar($row->{$label}) ? (string) $row->{$label} : null;

                return [$r->subject_type, $row, ['title' => $res['title'] ?? 'Registro', 'subtitle' => $name, 'signed' => $r->status !== 'pending', 'request' => $r,
                    'declaration' => 'Revisé «'.($name ?: ($res['title'] ?? 'el registro')).'» y estoy de acuerdo. Firmo con mi PIN.', 'content_html' => null, 'file_id' => null]];
            case 'document':
                $this->module('DOC');
                $v = DB::selectOne(
                    "select v.*, d.code, d.title from document_versions v join documents d on d.id = v.document_id
                      where v.id = ? and v.deleted_at is null and v.status = 'current'
                        and exists (select 1 from notifications n where n.subject_id = v.id and n.recipient_worker_id = ? and n.event_code in ('doc.ack_requested', 'doc.ack_reminder') and n.deleted_at is null)",
                    [$id, $w->id]) ?? throw $nf();
                $signed = (bool) DB::selectOne('select 1 as ok from document_acknowledgements where document_version_id = ? and worker_id = ?', [$v->id, $w->id]);
                $row = DB::selectOne('select * from document_versions where id = ?', [$v->id]);

                return ['document_versions', $row, ['title' => $v->title, 'subtitle' => $v->code.' · '.($v->version_label ?: 'versión '.$v->version_number), 'signed' => $signed,
                    'declaration' => $decl("el documento «{$v->title}»"), 'content_html' => $v->content_html, 'file_id' => $v->file_id]];
            case 'risst':
                $this->module('RIS');
                $r = DB::selectOne('select r.*, v.title, v.version_number, v.content_html, v.file_id as version_file_id from risst_deliveries r join risst_versions v on v.id = r.risst_version_id
                                     where r.id = ? and r.worker_id = ? and r.deleted_at is null', [$id, $w->id]) ?? throw $nf();
                $row = DB::selectOne('select * from risst_deliveries where id = ?', [$r->id]);

                return ['risst_deliveries', $row, ['title' => 'Reglamento Interno de SST', 'subtitle' => "{$r->title} · versión {$r->version_number}", 'signed' => $r->status !== 'pending',
                    'declaration' => $decl('el Reglamento Interno de Seguridad y Salud en el Trabajo'), 'content_html' => $r->content_html, 'file_id' => $r->version_file_id]];
            default:
                $this->module('EPP');
                $d = DB::selectOne('select d.*, i.name as item from ppe_deliveries d join ppe_items i on i.id = d.ppe_item_id where d.id = ? and d.worker_id = ? and d.deleted_at is null', [$id, $w->id]) ?? throw $nf();
                $row = DB::selectOne('select * from ppe_deliveries where id = ?', [$d->id]);
                $what = "{$d->item} × {$d->quantity}".($d->size ? " (talla {$d->size})" : '');

                return ['ppe_deliveries', $row, ['title' => 'Entrega de EPP', 'subtitle' => $what.' · '.date('d/m/Y', strtotime($d->delivered_at)), 'signed' => $d->signature_id !== null,
                    'declaration' => "Declaro que recibí: {$what}, en buen estado, y que sé usarlo. Firmo con mi PIN.", 'content_html' => null, 'file_id' => null,
                    'expires_on' => $d->expires_on]];
        }
    }

    private function signature(string $company, string $requestId, string $table, string $subjectId, object $w, string $uid, string $hash, ?string $image): string
    {
        $req = request();
        $doc = $w->document_last4 ? '****'.$w->document_last4 : null;
        $evidence = ['request_id' => $requestId, 'method' => 'pin', 'channel' => 'worker_app', 'signed_by_user_id' => $uid, 'signer_worker_id' => $w->id, 'signer' => $w->full_name,
            'subject' => "{$table}/{$subjectId}", 'document_hash' => $hash, 'hash_scope' => SignatureService::scope($table), 'ip' => $req->ip(),
            'user_agent' => mb_substr((string) $req->userAgent(), 0, 300), 'at' => now()->toIso8601String()];
        $id = DB::selectOne(
            "insert into signatures (company_id, signature_request_id, subject_type, subject_id, signer_user_id, signer_worker_id, signer_name, signer_document_masked, method,
                document_hash, evidence_hash, evidence, ip, user_agent, signed_at, created_by)
             values (?, ?, ?, ?, ?, ?, ?, ?, 'pin', decode(?, 'hex'), decode(?, 'hex'), ?::jsonb, ?::inet, ?, now(), ?) returning id",
            [$company, $requestId, $table, $subjectId, $uid, $w->id, $w->full_name, $doc, $hash, hash('sha256', json_encode($evidence)), json_encode($evidence, JSON_UNESCAPED_UNICODE),
                $req->ip(), mb_substr((string) $req->userAgent(), 0, 300), $uid])->id;
        if ($image) {
            SignatureImages::stamp($id, $company, null, $image);
        }

        return $id;
    }

    private function holder(object $w): array
    {
        return ['full_name' => $w->full_name, 'document_masked' => $w->document_last4 ? '****'.$w->document_last4 : null, 'position' => $w->position, 'site' => $w->site];
    }

    private function module(string $code): void
    {
        if (! WorkerSelf::usable($code)) {
            throw new ApiProblem(403, 'module_not_in_plan', 'No disponible', 'Esta sección no está activa en tu empresa.', ['module' => $code]);
        }
    }
}
