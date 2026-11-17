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
 *  GET   /me/worker/iperc                Mi IPERC (M-IPC SCR-IPC-08): peligros, riesgos, nivel y controles de mi puesto en las
 *                                        matrices vigentes, y si ya firmé «Lo conozco» (firma con PIN, kind iperc = versión vigente)
 *
 * Un id de otro trabajador responde 404, igual que uno inexistente.
 */
class WorkerSelfController extends Controller
{
    private const KINDS = ['signature', 'document', 'risst', 'ppe', 'talk', 'ats', 'iperc'];

    public function home(): JsonResponse
    {
        $w = WorkerSelf::row();
        $ctx = app(CompanyContext::class);
        $today = WorkerSelf::today();
        $mods = [];
        foreach (['FOT', 'FIR', 'DOC', 'RIS', 'CAP', 'AUL', 'EPP', 'EMO', 'ACT', 'COM', 'PSI', 'OFF', 'IPC'] as $c) {
            $mods[$c] = WorkerSelf::usable($c);
        }
        $cred = $mods['FOT'] ? DB::selectOne("select status, expires_on from credentials where worker_id = ? and deleted_at is null and status <> 'revoked' order by issued_at desc nulls last limit 1", [$w->id]) : null;
        $ppe = $mods['EPP'] ? DB::selectOne("select count(*) as n, count(*) filter (where signature_id is null) as unsigned, count(*) filter (where expires_on between ?::date and ?::date + 30) as expiring
                                              from ppe_deliveries where worker_id = ? and deleted_at is null and status in ('delivered', 'pending_signature')", [$today, $today, $w->id]) : null;
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
                'notices' => (int) DB::selectOne("select count(*) as n from notifications where (recipient_worker_id = ? or recipient_user_id = ?) and read_at is null
                                                    and dismissed_at is null and deleted_at is null and coalesce(scheduled_for, created_at) <= now()", [$w->id, (string) auth()->id()])->n,
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

    /** GET /worker/notifications — mis avisos (canal «App» de las fichas): los 30 más recientes, sin datos clínicos. */
    public function notifications(): JsonResponse
    {
        $w = WorkerSelf::row();
        $rows = DB::select(
            "select id, event_code, title, body, priority, read_at, coalesce(scheduled_for, created_at) as at from notifications
              where (recipient_worker_id = ? or recipient_user_id = ?) and deleted_at is null and dismissed_at is null and coalesce(scheduled_for, created_at) <= now()
              order by coalesce(scheduled_for, created_at) desc limit 30",
            [$w->id, (string) auth()->id()]
        );

        return response()->json(['data' => $rows]);
    }

    /** POST /worker/notifications/read-all — marca como leídos mis avisos. */
    public function readNotifications(): JsonResponse
    {
        $w = WorkerSelf::row();
        DB::update('update notifications set read_at = now() where (recipient_worker_id = ? or recipient_user_id = ?) and read_at is null and deleted_at is null and coalesce(scheduled_for, created_at) <= now()',
            [$w->id, (string) auth()->id()]);

        return response()->json(['data' => ['ok' => true]]);
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
        // M-ATS RN-ATS-03: con ats_block_untrained activo, quien no tiene la capacitación exigida no firma el ATS.
        if ($kind === 'ats' && \App\Core\Support\LegalParams::flag('ats_block_untrained', false)
            && ($miss = \App\Modules\REC\AtsTrainingController::missing((string) $row->ats_form_id, $w->id))) {
            throw new ApiProblem(409, 'conflict', 'Falta capacitación', 'No puedes firmar este ATS: te falta la capacitación «'.implode('», «', $miss).'». Consulta con el área de SST.');
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
            \App\Modules\NOT\FichaEvents::signed($meta['request']->id);
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
                'ppe' => DB::update("update ppe_deliveries set signature_id = ?, status = case when status = 'pending_signature' then 'delivered' else status end, updated_by = ? where id = ?", [$sig, $uid, $row->id]),
                'talk' => DB::update('update talk_attendances set signature_id = ?, updated_by = ? where id = ?', [$sig, $uid, $row->id]),
                // RN-ATS-02: si el ATS ya estaba firmado o en ejecución, queda como firma tardía.
                // M-IPC SCR-IPC-08: la firma «Lo conozco» queda como firma del trabajador sobre la versión vigente del IPERC.
                'iperc' => null,
                'ats' => DB::update("update ats_participants p set signature_id = ?, signed_at = now(), updated_by = ?,
                        late_signature = exists (select 1 from ats_forms f where f.id = p.ats_form_id and f.status in ('signed', 'in_execution')) where p.id = ?", [$sig, $uid, $row->id]),
            };
        }
        DB::insert("insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes, ip, user_agent)
                    values (?, ?, 'worker', 'user', 'signature_signed', ?, ?, ?, ?::jsonb, ?::inet, ?)",
            [$ctx->companyId, $uid, Registry::data()['resources'][$table]['module'] ?? 'FIR', $table, $row->id, json_encode(['signature' => $sig, 'method' => 'pin', 'app' => 'worker']),
                $request->ip(), mb_substr((string) $request->userAgent(), 0, 300)]);

        return response()->json(['data' => ['status' => 'signed', 'signature_id' => $sig]]);
    }

    /** GET /me/worker/iperc — Mi IPERC (M-IPC SCR-IPC-08). */
    public function iperc(): JsonResponse
    {
        $w = WorkerSelf::row();
        $this->module('IPC');
        $out = [];
        foreach ($this->ipercVersions($w) as $v) {
            $out[] = ['version_id' => $v->version_id, 'code' => $v->code, 'title' => $v->title, 'version_number' => (int) $v->version_number, 'effective_on' => $v->effective_on,
                'signed' => $v->signed, 'signed_at' => $v->signed_at, 'rows' => $this->ipercRows($v->version_id, $w)];
        }

        return response()->json(['data' => ['position' => $w->position, 'matrices' => $out]]);
    }

    /** Versiones vigentes de las matrices con filas de mi puesto (la fila o la matriz indica el puesto), y si ya firmé «Lo conozco». */
    private function ipercVersions(object $w): array
    {
        $pos = DB::selectOne('select current_position_id from workers where id = ?', [$w->id])?->current_position_id;
        if (! $pos) {
            return [];
        }
        $rows = DB::select(
            "select v.id as version_id, m.code, m.title, v.version_number, v.effective_on, v.approved_at,
                    (select s.signed_at from signatures s where s.subject_type = 'iperc_versions' and s.subject_id = v.id and s.signer_worker_id = ? order by s.signed_at desc limit 1) as signed_at
               from iperc_matrices m join iperc_versions v on v.id = m.current_version_id and v.status = 'current' and v.deleted_at is null
              where m.deleted_at is null and m.status <> 'archived'
                and exists (select 1 from iperc_rows r where r.iperc_version_id = v.id and r.deleted_at is null and coalesce(r.position_id, m.position_id) = ?)
              order by m.code", [$w->id, $pos]);
        foreach ($rows as $r) {
            $r->signed = $r->signed_at !== null;
        }

        return $rows;
    }

    private function ipercRows(string $versionId, object $w): array
    {
        $pos = DB::selectOne('select current_position_id from workers where id = ?', [$w->id])?->current_position_id;
        $rows = DB::select(
            "select r.id, r.activity, r.task, r.is_routine, r.hazard, r.risk, r.consequence, r.risk_level, r.is_significant, r.residual_level
               from iperc_rows r join iperc_versions v on v.id = r.iperc_version_id join iperc_matrices m on m.id = v.iperc_matrix_id
              where r.iperc_version_id = ? and r.deleted_at is null and coalesce(r.position_id, m.position_id) = ? order by r.row_number", [$versionId, $pos]);
        foreach ($rows as $r) {
            $r->controls = DB::select('select hierarchy, description, is_existing from iperc_controls where iperc_row_id = ? and deleted_at is null order by is_existing desc, hierarchy', [$r->id]);
            unset($r->id);
        }

        return $rows;
    }

    public function trainings(): JsonResponse
    {
        $w = WorkerSelf::row();
        $cap = WorkerSelf::usable('CAP');
        $aul = WorkerSelf::usable('AUL');

        return response()->json(['data' => [
            // M-CH5 SCR-CH5-04 «Mis charlas»: charlas a las que asistí, con su firma.
            'talks' => WorkerSelf::usable('CH5') ? DB::select(
                "select t.id, k.topic, k.talk_on, k.duration_minutes, t.signature_id is not null as signed from talk_attendances t join safety_talks k on k.id = t.safety_talk_id
                  where t.worker_id = ? and t.deleted_at is null and k.deleted_at is null and k.status <> 'cancelled' order by k.talk_on desc limit 100", [$w->id]) : [],

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
                "select r.id, c.title, c.hours, coalesce(r.message, '') as message, r.reason_code, c.allow_self_enrollment from course_recommendations r join courses c on c.id = r.course_id
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
            "select d.id, i.name as item, d.quantity, d.size, d.delivered_at, d.expires_on, d.status, d.signature_id is not null as signed,
                    exists (select 1 from ppe_replacement_requests q where q.worker_id = d.worker_id and q.ppe_item_id = d.ppe_item_id and q.status = 'pending' and q.deleted_at is null) as replacement_requested
               from ppe_deliveries d join ppe_items i on i.id = d.ppe_item_id
              where d.worker_id = ? and d.deleted_at is null order by d.delivered_at desc limit 200", [$w->id]);
        foreach ($rows as $r) {
            $r->signed = (bool) $r->signed;
            $r->expiry = ! $r->expires_on ? 'none' : ($r->expires_on < $today ? 'expired' : ($r->expires_on <= date('Y-m-d', strtotime($today.' +30 days')) ? 'soon' : 'ok'));
        }

        return response()->json(['data' => $rows]);
    }

    /** M-EPP: el trabajador pide la reposición de un EPP que recibió (gastado, dañado, perdido o de otra talla). */
    public function ppeReplacement(Request $request, string $id): JsonResponse
    {
        $this->module('EPP');
        $w = WorkerSelf::row();
        $d = Str::isUuid($id) ? DB::selectOne("select d.id, d.ppe_item_id, i.name from ppe_deliveries d join ppe_items i on i.id = d.ppe_item_id where d.id = ? and d.worker_id = ? and d.deleted_at is null", [$id, $w->id]) : null;
        if (! $d) {
            throw ApiProblem::notFound('No encontramos esa entrega de EPP.');
        }
        $in = $request->validate(['reason' => ['required', 'in:worn,damaged,lost,size,other'], 'notes' => ['nullable', 'string', 'max:500']], ['reason.required' => 'Elige el motivo.']);
        if (DB::selectOne("select 1 as ok from ppe_replacement_requests where worker_id = ? and ppe_item_id = ? and status = 'pending' and deleted_at is null", [$w->id, $d->ppe_item_id])) {
            throw new ApiProblem(409, 'conflict', 'Ya lo pediste', 'Ya tienes una solicitud de reposición pendiente de este EPP.');
        }
        $uid = (string) auth()->id();
        $rid = DB::selectOne('insert into ppe_replacement_requests (company_id, worker_id, ppe_delivery_id, ppe_item_id, reason, notes, created_by, updated_by) values (?, ?, ?, ?, ?, ?, ?, ?) returning id',
            [app(CompanyContext::class)->companyId, $w->id, $d->id, $d->ppe_item_id, $in['reason'], $in['notes'] ?? null, $uid, $uid])->id;
        \App\Modules\NOT\Notify::users(\App\Modules\NOT\Notify::roles(['sst_manager', 'sst_supervisor']), 'epp.replacement_requested', 'EPP', $rid, 'Solicitud de reposición de EPP',
            "{$w->full_name} pide reponer «{$d->name}».", '/m/epp', 'normal', 'epp.replacement:'.$rid);

        return response()->json(['data' => ['id' => $rid]], 201);
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
                  where d.worker_id = ? and d.signature_id is null and d.status = 'pending_signature' and d.deleted_at is null order by d.delivered_at", [$w->id]) as $r) {
                $items[] = ['kind' => 'ppe', 'id' => $r->id, 'title' => 'Confirmar entrega de EPP', 'detail' => "{$r->name} × {$r->quantity} · ".date('d/m/Y', strtotime($r->delivered_at)), 'due' => null, 'at' => $r->delivered_at];
            }
        }
        if (WorkerSelf::usable('CH5')) {
            foreach (DB::select(
                "select a.id, t.topic, t.talk_on from talk_attendances a join safety_talks t on t.id = a.safety_talk_id
                  where a.worker_id = ? and a.signature_id is null and a.deleted_at is null and t.deleted_at is null order by t.talk_on", [$w->id]) as $r) {
                $items[] = ['kind' => 'talk', 'id' => $r->id, 'title' => 'Firmar asistencia a la charla', 'detail' => $r->topic.($r->talk_on ? ' · '.date('d/m/Y', strtotime($r->talk_on)) : ''), 'due' => null, 'at' => $r->talk_on];
            }
        }
        if (WorkerSelf::usable('ATS')) {
            foreach (DB::select(
                "select p.id, f.code, f.task, f.performed_on from ats_participants p join ats_forms f on f.id = p.ats_form_id
                  where p.worker_id = ? and p.signature_id is null and p.deleted_at is null and f.deleted_at is null and f.status in ('draft', 'signed', 'in_execution')
                  order by f.performed_on", [$w->id]) as $r) {
                $items[] = ['kind' => 'ats', 'id' => $r->id, 'title' => 'Firmar el ATS', 'detail' => trim(($r->code ? $r->code.' · ' : '').$r->task).($r->performed_on ? ' · '.date('d/m/Y', strtotime($r->performed_on)) : ''), 'due' => $r->performed_on, 'at' => $r->performed_on];
            }
        }
        if (WorkerSelf::usable('IPC')) {
            foreach ($this->ipercVersions($w) as $v) {
                if (! $v->signed) {
                    $items[] = ['kind' => 'iperc', 'id' => $v->version_id, 'title' => 'Conocer los riesgos de mi puesto (IPERC)',
                        'detail' => trim(($v->code ? $v->code.' · ' : '').$v->title).' · versión '.$v->version_number, 'due' => null, 'at' => $v->approved_at];
                }
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
                    'due' => $s->ends_on, 'at' => $s->created_at, 'url' => '/mi/encuesta/'.$s->id];
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
            case 'talk':
                $this->module('CH5');
                $t = DB::selectOne('select a.*, t.topic, t.content, t.talk_on from talk_attendances a join safety_talks t on t.id = a.safety_talk_id
                                     where a.id = ? and a.worker_id = ? and a.deleted_at is null', [$id, $w->id]) ?? throw $nf();
                $row = DB::selectOne('select * from talk_attendances where id = ?', [$t->id]);

                return ['talk_attendances', $row, ['title' => 'Charla de 5 minutos', 'subtitle' => $t->topic.($t->talk_on ? ' · '.date('d/m/Y', strtotime($t->talk_on)) : ''),
                    'signed' => $t->signature_id !== null, 'declaration' => "Declaro que asistí a la charla «{$t->topic}» y que entendí lo explicado. Firmo con mi PIN.",
                    'content_html' => $t->content ? '<p>'.nl2br(e($t->content)).'</p>' : null, 'file_id' => null]];
            case 'iperc':
                $this->module('IPC');
                $v = collect($this->ipercVersions($w))->firstWhere('version_id', $id) ?? throw $nf();
                $rows = $this->ipercRows($v->version_id, $w);
                $lvl = fn ($r) => $r->residual_level ?: $r->risk_level;
                $html = '<ol>'.implode('', array_map(fn ($r) => '<li><b>'.e((string) ($r->task ?: $r->activity)).'</b> — <b>Peligro:</b> '.e((string) $r->hazard)
                    .' — <b>Riesgo:</b> '.e((string) $r->risk).($lvl($r) ? ' — <b>Nivel:</b> '.e((string) $lvl($r)) : '')
                    .($r->controls ? ' — <b>Controles:</b> '.e(implode('; ', array_column($r->controls, 'description'))) : '').'</li>', $rows)).'</ol>';
                $row = DB::selectOne('select * from iperc_versions where id = ?', [$v->version_id]);

                return ['iperc_versions', $row, ['title' => 'Mi IPERC', 'subtitle' => trim(($v->code ? $v->code.' · ' : '').$v->title).' · versión '.$v->version_number,
                    'signed' => $v->signed, 'declaration' => 'Declaro que conozco los peligros, riesgos y controles de mi puesto que figuran en este IPERC y que aplicaré los controles. Firmo con mi PIN.',
                    'content_html' => $html, 'file_id' => null]];
            case 'ats':
                $this->module('ATS');
                $p = DB::selectOne("select p.*, f.code, f.task, f.work_location, f.performed_on, f.status as form_status from ats_participants p join ats_forms f on f.id = p.ats_form_id
                                     where p.id = ? and p.worker_id = ? and p.deleted_at is null", [$id, $w->id]) ?? throw $nf();
                $txt = fn ($v) => e(is_string($v) ? $v : implode(', ', array_map('strval', (array) json_decode((string) json_encode($v), true))));
                $steps = DB::select('select step_number, description, hazards, controls from ats_steps where ats_form_id = ? and deleted_at is null order by step_number', [$p->ats_form_id]);
                $html = $steps ? '<ol>'.implode('', array_map(fn ($st) => '<li>'.e((string) $st->description)
                    .($st->hazards ? ' — <b>Peligro:</b> '.$txt(json_decode((string) $st->hazards, true) ?? $st->hazards) : '')
                    .($st->controls ? ' — <b>Control:</b> '.$txt(json_decode((string) $st->controls, true) ?? $st->controls) : '').'</li>', $steps)).'</ol>' : null;
                $row = DB::selectOne('select * from ats_participants where id = ?', [$p->id]);

                return ['ats_participants', $row, ['title' => 'Análisis de Trabajo Seguro (ATS)', 'subtitle' => trim(($p->code ? $p->code.' · ' : '').$p->task),
                    'signed' => $p->signature_id !== null || in_array($p->form_status, ['closed', 'cancelled'], true),
                    'declaration' => "Declaro que participé en el análisis de la tarea «{$p->task}», que conozco sus peligros y controles y que los cumpliré. Firmo con mi PIN.",
                    'content_html' => $html, 'file_id' => null]];
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

    /** SCR-PSI-04: la encuesta en la app del trabajador (sin volver a escribir el documento); la respuesta es anónima. */
    public function survey(string $id): JsonResponse
    {
        $w = WorkerSelf::row();
        $this->module('PSI');
        $s = $this->openSurvey($id);
        $done = (bool) DB::selectOne("select 1 as ok from psychosocial_responses where survey_id = ? and respondent_token_hash = decode(?, 'hex')", [$s->id, PsychosocialSurvey::respondentHash($s->id, $w->id)]);
        $q = json_decode((string) $s->questions, true);
        unset($q['cutoffs']);

        return response()->json(['data' => ['id' => $s->id, 'title' => $s->title, 'ends_on' => $s->ends_on, 'answered' => $done, 'questions' => $done ? null : $q]]);
    }

    public function answerSurvey(Request $request, string $id): JsonResponse
    {
        $w = WorkerSelf::row();
        $this->module('PSI');
        $s = $this->openSurvey($id);
        $data = $request->validate(['answers' => ['required', 'array']]);
        $row = DB::selectOne('select id, current_site_id, current_area_id from workers where id = ?', [$w->id]);
        PsychosocialSurvey::store($s, $row, PsychosocialSurvey::answers($s, $data['answers']));

        return response()->json(['data' => ['ok' => true]], 201);
    }

    private function openSurvey(string $id): object
    {
        $today = WorkerSelf::today();
        $s = Str::isUuid($id) ? DB::selectOne("select * from psychosocial_surveys where id = ? and deleted_at is null and status = 'open'
                                                 and (starts_on is null or starts_on <= ?::date) and (ends_on is null or ends_on >= ?::date)", [$id, $today, $today]) : null;

        return $s ?? throw new ApiProblem(409, 'conflict', 'La encuesta no está abierta', 'Esta encuesta ya cerró o todavía no empieza.');
    }

    private function module(string $code): void
    {
        if (! WorkerSelf::usable($code)) {
            throw new ApiProblem(403, 'module_not_in_plan', 'No disponible', 'Esta sección no está activa en tu empresa.', ['module' => $code]);
        }
    }
}
