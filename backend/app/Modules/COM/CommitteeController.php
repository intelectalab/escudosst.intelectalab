<?php

namespace App\Modules\COM;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\FIR\SignatureController;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Comité vigente y actas de reunión (M-COM SCR-COM-01, 07, 08):
 *  GET  /com/committee                        comité vigente: integrantes, mandato con semáforo, próxima reunión, acuerdos pendientes, reuniones
 *  POST /com/meetings/{id}/notify             «Enviar convocatoria»: aviso en la app y correo a los integrantes con usuario
 *  GET  /com/meetings/{id}/minutes            acta de la reunión con asistentes, acuerdos y firmas (para ver e imprimir)
 *  POST /com/meetings/{id}/attendance         «Tomar asistencia» {member_ids[]}: calcula el quórum (RN-COM-08) y marca la reunión realizada
 *  POST /com/meetings/{id}/request-signatures pide la firma del acta a los integrantes que asistieron (FIR)
 */
class CommitteeController extends Controller
{
    private const ROLE = ['president' => 'Presidente', 'secretary' => 'Secretario', 'member' => 'Miembro', 'observer' => 'Observador'];

    public function __construct(private AccountsService $accounts) {}

    public function current(): JsonResponse
    {
        $c = DB::selectOne(
            "select id, committee_type, name, period_start_on, period_end_on, installation_on, status, members_required
               from committees where deleted_at is null and status in ('active', 'forming')
              order by (status = 'active') desc, period_start_on desc limit 1");
        if (! $c) {
            return response()->json(['data' => null]);
        }
        $members = $this->members($c->id);
        $days = (int) floor((strtotime($c->period_end_on) - strtotime(date('Y-m-d'))) / 86400);
        $next = DB::selectOne(
            "select id, number, meeting_type, scheduled_at, location, modality, agenda from committee_meetings
              where committee_id = ? and status = 'scheduled' and deleted_at is null and (scheduled_at is null or scheduled_at >= now() - interval '1 day')
              order by scheduled_at nulls last limit 1", [$c->id]);
        if ($next) {
            $next->notified = (bool) DB::selectOne('select 1 as ok from notifications where dedupe_key like ? and deleted_at is null limit 1', ['com.meeting_called:'.$next->id.':%']);
        }
        $agreements = DB::select(
            "select a.id, a.number, a.description, a.due_on, a.status, m.number as meeting_number, coalesce(w.full_name, u.full_name) as responsible,
                    (a.status <> 'fulfilled' and a.due_on is not null and a.due_on < current_date) as is_overdue
               from meeting_agreements a join committee_meetings m on m.id = a.meeting_id
               left join workers w on w.id = a.responsible_worker_id left join users u on u.id = a.responsible_user_id
              where m.committee_id = ? and a.deleted_at is null and a.status in ('agreed', 'in_progress', 'overdue')
              order by a.due_on nulls last limit 20", [$c->id]);
        $meetings = DB::select(
            "select m.id, m.number, m.meeting_type, m.scheduled_at, m.held_at, m.status, m.quorum_reached,
                    coalesce(jsonb_array_length(case when jsonb_typeof(m.attendance) = 'array' then m.attendance end), 0) as attendees,
                    (select count(*) from meeting_agreements a where a.meeting_id = m.id and a.deleted_at is null) as agreements,
                    (select count(*) from signatures s where s.subject_type = 'committee_meetings' and s.subject_id = m.id) as signatures
               from committee_meetings m where m.committee_id = ? and m.deleted_at is null
              order by coalesce(m.held_at, m.scheduled_at) desc nulls last limit 24", [$c->id]);

        return response()->json(['data' => [
            'committee' => (array) $c + ['days_left' => $days, 'term_tone' => $days < 0 ? 'bad' : ($days <= 60 ? 'warn' : 'ok')],
            'members' => $members,
            'counts' => [
                'employer' => count(array_filter($members, fn ($m) => $m->representation === 'employer' && $m->member_type === 'titular')),
                'workers' => count(array_filter($members, fn ($m) => in_array($m->representation, ['workers', 'supervisor'], true) && $m->member_type === 'titular')),
                'alternates' => count(array_filter($members, fn ($m) => $m->member_type === 'alternate')),
            ],
            'next_meeting' => $next,
            'agreements' => $agreements,
            'meetings' => $meetings,
            'can' => ['notify' => $this->can('com.committee_meetings.create') || $this->can('com.committee_meetings.update'), 'sign_request' => $this->can('com.committee_meetings.update')],
        ]]);
    }

    public function notify(string $id): JsonResponse
    {
        $m = $this->meeting($id);
        if ($m->status !== 'scheduled') {
            throw new ApiProblem(409, 'conflict', 'Reunión no programada', 'Solo se convoca a una reunión programada.');
        }
        $type = ['ordinary' => 'ordinaria', 'extraordinary' => 'extraordinaria', 'installation' => 'de instalación'][$m->meeting_type] ?? '';
        $title = 'Convocatoria: reunión '.$type.($m->number ? ' N.° '.$m->number : '').' del Comité SST';
        $when = $m->scheduled_at ? date('d/m/Y H:i', strtotime($m->scheduled_at)) : 'fecha por confirmar';
        $body = "Fecha: {$when}".($m->location ? " · Lugar: {$m->location}" : '').($m->agenda ? ' · Agenda: '.Str::limit(strip_tags($m->agenda), 300) : '');
        $company = app(CompanyContext::class)->companyName;
        $url = rtrim((string) config('escudo.frontend_url'), '/').'/m/com';
        $sent = 0;
        $emailed = 0;
        foreach ($this->members($m->committee_id) as $mem) {
            if (! $mem->user_id) {
                continue;
            }
            $dedupe = 'com.meeting_called:'.$m->id.':'.$mem->user_id;
            if (! DB::selectOne('select 1 as ok from notifications where recipient_user_id = ? and dedupe_key = ? and deleted_at is null', [$mem->user_id, $dedupe])) {
                DB::insert(
                    "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, recipient_worker_id, title, body, link, priority, due_at, dedupe_key)
                     values (?, 'com.meeting_called', 'committee_meetings', ?, ?, ?, ?, ?, '/m/com', 'normal', ?, ?)",
                    [$m->company_id, $m->id, $mem->user_id, $mem->worker_id, $title, mb_substr($body, 0, 500), $m->scheduled_at, $dedupe]
                );
            }
            $sent++;
            if ($mem->email) {
                try {
                    Mail::to($mem->email)->send(new CommitteeNoticeMail(explode(' ', (string) $mem->full_name)[0], $company, $title, $body, $url));
                    $emailed++;
                } catch (\Throwable $ex) {
                    Log::warning('No se pudo enviar la convocatoria del comité', ['meeting' => $m->id, 'error' => $ex->getMessage()]);
                }
            }
        }
        $this->accounts->audit((string) auth()->id(), 'committee_meeting_called', 'committee_meetings', $m->id, ['notified' => $sent, 'emailed' => $emailed]);

        return response()->json(['data' => ['notified' => $sent, 'emailed' => $emailed,
            'without_user' => count(array_filter($this->members($m->committee_id), fn ($x) => ! $x->user_id))]]);
    }

    public function minutes(string $id): JsonResponse
    {
        $m = $this->meeting($id);
        $c = DB::selectOne('select id, name, committee_type, period_start_on, period_end_on from committees where id = ?', [$m->committee_id]);
        $co = DB::selectOne('select legal_name, trade_name, ruc from companies where id = ?', [$m->company_id]);
        $members = $this->members($m->committee_id, true);
        $present = is_string($m->attendance) ? (json_decode($m->attendance, true) ?: []) : [];
        $present = array_map(fn ($x) => is_array($x) ? (string) ($x['id'] ?? $x['member_id'] ?? '') : (string) $x, $present);
        foreach ($members as $mem) {
            $mem->present = in_array((string) $mem->id, $present, true) || in_array((string) $mem->worker_id, $present, true);
        }
        $agreements = DB::select(
            "select a.number, a.description, a.due_on, a.status, coalesce(w.full_name, u.full_name) as responsible
               from meeting_agreements a left join workers w on w.id = a.responsible_worker_id left join users u on u.id = a.responsible_user_id
              where a.meeting_id = ? and a.deleted_at is null order by a.number nulls last, a.created_at", [$m->id]);
        $signatures = DB::select(
            "select s.id, s.signer_name, s.signed_at, s.method, k.mime_type, encode(k.image, 'base64') as image
               from signatures s left join signature_strokes k on k.signature_id = s.id
              where s.subject_type = 'committee_meetings' and s.subject_id = ? order by s.signed_at", [$m->id]);
        foreach ($signatures as $s) {
            $s->image = $s->image ? 'data:'.$s->mime_type.';base64,'.str_replace(["\n", "\r"], '', $s->image) : null;
            unset($s->mime_type);
        }
        $pending = DB::select(
            "select r.id, coalesce(u.full_name, w.full_name) as signer_name, r.status from signature_requests r
               left join users u on u.id = r.signer_user_id left join workers w on w.id = r.signer_worker_id
              where r.subject_type = 'committee_meetings' and r.subject_id = ? and r.status = 'pending' and r.deleted_at is null", [$m->id]);

        return response()->json(['data' => [
            'company' => ['name' => $co->trade_name ?: $co->legal_name, 'legal_name' => $co->legal_name, 'ruc' => trim((string) $co->ruc)],
            'committee' => $c,
            'meeting' => [
                'id' => $m->id, 'number' => $m->number, 'meeting_type' => $m->meeting_type, 'scheduled_at' => $m->scheduled_at, 'held_at' => $m->held_at,
                'location' => $m->location, 'modality' => $m->modality, 'quorum_reached' => $m->quorum_reached, 'status' => $m->status,
                'agenda' => $m->agenda ? trim(strip_tags((string) $m->agenda)) : null,
                // El acta se redacta en el editor del registro; aquí se muestra como texto (sin HTML) para imprimir.
                'minutes_text' => $m->minutes_html ? trim(html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/li|\/h\d)\s*\/?>/i', "\n", (string) $m->minutes_html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : null,
                'has_minutes_file' => (bool) $m->minutes_file_id,
            ],
            'members' => array_map(fn ($x) => ['id' => $x->id, 'name' => $x->full_name, 'role' => self::ROLE[$x->role_in_committee] ?? $x->role_in_committee,
                'representation' => $x->representation, 'member_type' => $x->member_type, 'present' => $x->present], $members),
            'agreements' => $agreements,
            'signatures' => $signatures,
            'pending_signatures' => $pending,
            'can_manage' => $this->can('com.committee_meetings.update'),
            'can_request_signatures' => $this->can('com.committee_meetings.update') && in_array($m->status, ['held', 'minutes_draft', 'minutes_signed'], true),
        ]]);
    }

    public function attendance(Request $request): JsonResponse
    {
        $m = $this->meeting((string) $request->route('id'));
        if (! in_array($m->status, ['scheduled', 'held', 'minutes_draft'], true)) {
            throw new ApiProblem(409, 'conflict', 'Acta cerrada', 'La asistencia se toma antes de firmar el acta.');
        }
        $ids = array_values(array_unique(array_map('strval', (array) $request->input('member_ids', []))));
        $members = $this->members($m->committee_id);
        $valid = array_column($members, 'id');
        if (array_diff($ids, array_map('strval', $valid))) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Marca solo a integrantes del comité.', ['errors' => ['member_ids' => ['Marca solo a integrantes del comité.']]]);
        }
        // Quórum: mitad más uno de los titulares (parámetro committee_quorum_ratio, valor de referencia 0.5 + 1 integrante).
        $titulars = array_values(array_filter($members, fn ($x) => $x->member_type === 'titular'));
        $ratio = LegalParams::number('committee_quorum_ratio', 0.5);
        $needed = (int) floor(count($titulars) * $ratio) + 1;
        $present = count(array_filter($titulars, fn ($x) => in_array((string) $x->id, $ids, true)));
        $quorum = count($titulars) > 0 && $present >= $needed;
        DB::update(
            "update committee_meetings set attendance = ?::jsonb, quorum_reached = ?, status = case when status = 'scheduled' then 'held' else status end,
                    held_at = coalesce(held_at, now()), updated_by = ? where id = ?",
            [json_encode($ids), $quorum, (string) auth()->id(), $m->id]
        );
        $this->accounts->audit((string) auth()->id(), 'committee_meeting_attendance', 'committee_meetings', $m->id, ['present' => count($ids), 'quorum' => $quorum]);

        return response()->json(['data' => ['present' => count($ids), 'titulars_present' => $present, 'needed' => $needed, 'quorum_reached' => $quorum,
            'message' => $quorum ? 'Hay quórum: se pueden adoptar acuerdos.' : 'Sin quórum: el acta registra «sin quórum» y no se adoptan acuerdos.']]);
    }

    public function requestSignatures(string $id): JsonResponse
    {
        $m = $this->meeting($id);
        if (! in_array($m->status, ['held', 'minutes_draft', 'minutes_signed'], true)) {
            throw new ApiProblem(409, 'conflict', 'Reunión no realizada', 'Las firmas del acta se piden después de realizar la reunión.');
        }
        $present = is_string($m->attendance) ? (json_decode($m->attendance, true) ?: []) : [];
        $present = array_map(fn ($x) => is_array($x) ? (string) ($x['id'] ?? $x['member_id'] ?? '') : (string) $x, $present);
        $row = DB::selectOne('select * from committee_meetings where id = ?', [$m->id]);
        $hash = SignatureController::hashRow($row);
        $uid = (string) auth()->id();
        $created = 0;
        foreach ($this->members($m->committee_id) as $mem) {
            if ($present && ! in_array((string) $mem->id, $present, true) && ! in_array((string) $mem->worker_id, $present, true)) {
                continue;   // firman los asistentes; si no se tomó asistencia, todos los integrantes
            }
            $signer = $mem->user_id ? ['signer_user_id', $mem->user_id] : ['signer_worker_id', $mem->worker_id];
            if (DB::selectOne("select 1 as ok from signature_requests where subject_type = 'committee_meetings' and subject_id = ? and {$signer[0]} = ? and status in ('pending','signed') and deleted_at is null",
                [$m->id, $signer[1]])) {
                continue;
            }
            $order = (int) DB::selectOne("select coalesce(max(sequence_order), 0) + 1 as n from signature_requests where subject_type = 'committee_meetings' and subject_id = ?", [$m->id])->n;
            DB::insert(
                "insert into signature_requests (company_id, subject_type, subject_id, subject_module, document_hash, signer_user_id, signer_worker_id, role_in_document,
                    sequence_order, status, expires_at, requested_by, created_by, updated_by)
                 values (?, 'committee_meetings', ?, 'COM', decode(?, 'hex'), ?, ?, 'committee_member', ?, 'pending', now() + interval '15 days', ?, ?, ?)",
                [$m->company_id, $m->id, $hash, $mem->user_id, $mem->user_id ? null : $mem->worker_id, $order, $uid, $uid, $uid]
            );
            if ($mem->user_id) {
                DB::insert(
                    "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, dedupe_key)
                     values (?, 'com.minutes_signature', 'committee_meetings', ?, ?, ?, 'Revisa el acta y fírmala desde tus pendientes.', '/m/com', 'normal', ?)",
                    [$m->company_id, $m->id, $mem->user_id, 'Firma pendiente: acta'.($m->number ? ' N.° '.$m->number : '').' del Comité SST', 'com.minutes_signature:'.$m->id.':'.$mem->user_id]
                );
            }
            $created++;
        }
        $this->accounts->audit($uid, 'committee_minutes_signatures_requested', 'committee_meetings', $m->id, ['requests' => $created]);

        return response()->json(['data' => ['requested' => $created]], 201);
    }

    /** @return list<object> integrantes activos con su usuario (si tienen) para avisos y firmas */
    private function members(string $committee, bool $all = false): array
    {
        return DB::select(
            "select cm.id, cm.worker_id, w.full_name, p.name as position, cm.representation, cm.member_type, cm.role_in_committee, cm.starts_on, cm.ends_on, cm.status,
                    coalesce(w.user_id, (select m.user_id from memberships m where m.worker_id = w.id and m.company_id = w.company_id and m.status = 'active' and m.deleted_at is null limit 1)) as user_id,
                    (select u.email from users u where u.id = coalesce(w.user_id, (select m.user_id from memberships m where m.worker_id = w.id and m.company_id = w.company_id and m.status = 'active' and m.deleted_at is null limit 1))) as email
               from committee_members cm join workers w on w.id = cm.worker_id left join positions p on p.id = w.current_position_id
              where cm.committee_id = ? and cm.deleted_at is null".($all ? '' : " and cm.status = 'active'")."
              order by case cm.role_in_committee when 'president' then 0 when 'secretary' then 1 else 2 end, cm.member_type desc, cm.representation, w.full_name", [$committee]);
    }

    private function meeting(string $id): object
    {
        $m = Str::isUuid($id) ? DB::selectOne('select * from committee_meetings where id = ? and deleted_at is null', [$id]) : null;
        if (! $m) {
            throw ApiProblem::notFound('La reunión no existe o es de otra empresa.');
        }

        return $m;
    }

    private function can(string $p): bool
    {
        return in_array($p, Permissions::effective(), true);
    }
}
