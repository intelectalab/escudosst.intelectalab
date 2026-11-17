<?php

namespace App\Modules\REC;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Informes imprimibles de registros (§11 de las fichas):
 *  GET /reports/management_reviews/{id}  M-RVD: informe de revisión por la dirección (entradas congeladas, conclusiones, salidas y firmas)
 *  GET /reports/signatures/{id}          M-FIR: constancia de firma (huellas, fecha y hora de Lima, método y evidencia)
 *  GET /reports/elections/{id}/call      M-COM: acta de convocatoria a elecciones (cronograma, puestos, junta electoral y firmas)
 *  GET /reports/committees/{id}          M-COM: acta de instalación (integrantes, presidente y secretario, mandato y firmas)
 *  GET /reports/ppe_deliveries/worker/{id} M-EPP: registro de entrega de EPP del trabajador con sus firmas (constancia)
 *  GET /reports/documents/{id}           M-DOC: historial de versiones con aprobadores y constancias de cargo de la versión vigente
 */
class RecordReportsController extends FieldReportsController
{
    public function review(string $id): JsonResponse
    {
        $this->can('rvd.management_reviews.read');
        $r = $this->row('management_reviews', $id, 'La revisión no existe o es de otra empresa.');
        $outputs = DB::select(
            "select o.output_type, o.description, o.due_on, o.status, u.full_name as responsible, a.code as action_code, a.status as action_status
               from management_review_outputs o left join users u on u.id = o.responsible_user_id left join corrective_actions a on a.id = o.corrective_action_id
              where o.review_id = ? and o.deleted_at is null order by o.created_at", [$id]);

        return response()->json(['data' => [
            'company' => $this->company(),
            'review' => ['year' => $r->year, 'title' => $r->title, 'review_on' => $r->review_on, 'status' => $r->status, 'conclusions' => $r->conclusions,
                'policy_changes_needed' => $r->policy_changes_needed, 'approved_at' => $r->approved_at, 'approved_by' => $this->person($r->approved_by, null),
                'inputs' => $this->json($r->inputs), 'participants' => $this->json($r->participants)],
            'outputs' => $outputs,
            'signatures' => $this->signatures('management_reviews', $id),
        ]]);
    }

    public function signature(string $id): JsonResponse
    {
        $this->can('fir.signatures.read');
        $s = Str::isUuid($id) ? DB::selectOne(
            "select s.*, encode(s.document_hash, 'hex') as document_hex, encode(s.evidence_hash, 'hex') as evidence_hex, r.role_in_document, r.subject_module,
                    r.created_at as requested_at, ru.full_name as requested_by
               from signatures s left join signature_requests r on r.id = s.signature_request_id left join users ru on ru.id = r.requested_by
              where s.id = ?", [$id]) : null;
        if (! $s) {
            throw \App\Core\Http\ApiProblem::notFound('La firma no existe o es de otra empresa.');
        }
        $res = Registry::data()['resources'][$s->subject_type] ?? null;
        $evidence = $this->json($s->evidence) ?: [];
        $lima = fn ($v) => $v ? (new \DateTimeImmutable((string) $v))->setTimezone(new \DateTimeZone('America/Lima'))->format('d/m/Y H:i:s') : null;

        return response()->json(['data' => [
            'company' => $this->company(),
            'signature' => ['id' => $s->id, 'signer_name' => $s->signer_name, 'signer_document_masked' => $s->signer_document_masked, 'method' => $s->method,
                'role' => $s->role_in_document, 'signed_at' => $s->signed_at, 'signed_at_lima' => $lima($s->signed_at), 'requested_at_lima' => $lima($s->requested_at),
                'requested_by' => $s->requested_by, 'document_hash' => $s->document_hex, 'evidence_hash' => $s->evidence_hex, 'hash_scope' => $evidence['hash_scope'] ?? null,
                'ip' => $s->ip, 'user_agent' => $s->user_agent, 'otp_channel' => $s->otp_channel, 'geo' => $s->geo_lat ? [$s->geo_lat, $s->geo_lng] : null,
                'subject' => ['table' => $s->subject_type, 'id' => $s->subject_id, 'title' => $res['title'] ?? $s->subject_type, 'module' => $res['module'] ?? $s->subject_module],
                'image' => $this->stroke($s->id)],
        ]]);
    }

    public function electionCall(string $id): JsonResponse
    {
        $this->can('com.elections.read');
        $e = $this->row('elections', $id, 'La elección no existe o es de otra empresa.');
        $c = $e->committee_id ? DB::selectOne('select c.name, c.committee_type, c.period_start_on, c.period_end_on, s.name as site from committees c left join sites s on s.id = c.site_id where c.id = ?', [$e->committee_id]) : null;

        return response()->json(['data' => [
            'company' => $this->company(),
            'election' => ['title' => $e->title, 'period_label' => $e->period_label, 'status' => $e->status, 'convened_at' => $e->convened_at,
                'nominations_open_at' => $e->nominations_open_at, 'nominations_close_at' => $e->nominations_close_at, 'voting_opens_at' => $e->voting_opens_at,
                'voting_closes_at' => $e->voting_closes_at, 'seats_titular' => $e->seats_titular, 'seats_alternate' => $e->seats_alternate,
                'electoral_board' => $this->json($e->electoral_board), 'eligible_voters' => $e->eligible_voters],
            'committee' => $c,
            'signatures' => $this->signatures('elections', $id),
        ]]);
    }

    public function committee(string $id): JsonResponse
    {
        $this->can('com.committees.read');
        $c = $this->row('committees', $id, 'El comité no existe o es de otra empresa.');
        $members = DB::select(
            "select w.full_name, '****' || w.document_last4 as document, ps.name as position, m.representation, m.member_type, m.role_in_committee, m.starts_on, m.ends_on,
                    m.designation_reference, e.title as election
               from committee_members m join workers w on w.id = m.worker_id left join positions ps on ps.id = w.current_position_id left join elections e on e.id = m.election_id
              where m.committee_id = ? and m.deleted_at is null and m.status = 'active'
              order by m.representation, case m.role_in_committee when 'president' then 0 when 'secretary' then 1 else 2 end, m.member_type, w.full_name", [$id]);

        return response()->json(['data' => [
            'company' => $this->company(),
            'committee' => ['name' => $c->name, 'committee_type' => $c->committee_type, 'site' => $this->name('sites', $c->site_id), 'installation_on' => $c->installation_on,
                'period_start_on' => $c->period_start_on, 'period_end_on' => $c->period_end_on, 'members_required' => $c->members_required, 'status' => $c->status, 'notes' => $c->notes],
            'members' => $members,
            'signatures' => $this->signatures('committees', $id),
        ]]);
    }

    public function ppeWorker(string $id): JsonResponse
    {
        $this->can('epp.ppe_deliveries.read');
        $w = Str::isUuid($id) ? DB::selectOne(
            "select w.full_name, '****' || w.document_last4 as document, ps.name as position, a.name as area, s.name as site
               from workers w left join positions ps on ps.id = w.current_position_id left join areas a on a.id = w.current_area_id left join sites s on s.id = w.current_site_id
              where w.id = ? and w.deleted_at is null", [$id]) : null;
        if (! $w) {
            throw \App\Core\Http\ApiProblem::notFound('El trabajador no existe o es de otra empresa.');
        }
        $rows = DB::select(
            "select d.id, i.code, i.name as item, i.technical_standard, d.quantity, d.size, d.delivered_at, d.delivery_reason, d.expires_on, d.returned_at, d.return_condition, d.status,
                    u.full_name as delivered_by
               from ppe_deliveries d join ppe_items i on i.id = d.ppe_item_id left join users u on u.id = d.delivered_by
              where d.worker_id = ? and d.deleted_at is null order by d.delivered_at", [$id]);
        foreach ($rows as $r) {
            $r->signatures = $this->signatures('ppe_deliveries', $r->id);
            unset($r->id);
        }

        return response()->json(['data' => ['company' => $this->company(), 'worker' => $w, 'deliveries' => $rows]]);
    }

    public function document(string $id): JsonResponse
    {
        $this->can('doc.documents.read');
        $d = $this->row('documents', $id, 'El documento no existe o es de otra empresa.');
        $versions = DB::select(
            "select v.id, v.version_number, v.version_label, v.change_summary, v.status, v.submitted_at, su.full_name as submitted_by, ru.full_name as reviewed_by,
                    v.review_comments, v.approved_at, au.full_name as approved_by, v.effective_on, v.effective_until, v.obsoleted_at
               from document_versions v left join users su on su.id = v.submitted_by left join users ru on ru.id = v.reviewed_by left join users au on au.id = v.approved_by
              where v.document_id = ? and v.deleted_at is null order by v.version_number", [$id]);
        $acks = [];
        if ($d->current_version_id && in_array('doc.document_acknowledgements.read', \App\Core\Auth\Permissions::effective(), true)) {
            $acks = DB::select(
                "select coalesce(w.full_name, u.full_name) as person, '****' || w.document_last4 as document, ps.name as position, k.method, k.acknowledged_at
                   from document_acknowledgements k left join workers w on w.id = k.worker_id left join users u on u.id = k.user_id
                   left join positions ps on ps.id = w.current_position_id
                  where k.document_version_id = ? order by k.acknowledged_at", [$d->current_version_id]);
        }
        foreach ($versions as $v) {
            $v->signatures = $this->signatures('document_versions', $v->id);
            unset($v->id);
        }

        return response()->json(['data' => [
            'company' => $this->company(),
            'document' => ['code' => $d->code, 'title' => $d->title, 'status' => $d->status, 'category' => $this->name('document_categories', $d->category_id),
                'owner' => $this->person($d->owner_user_id, null), 'next_review_on' => $d->next_review_on,
                'current_version' => $d->current_version_id ? DB::selectOne('select version_number, version_label from document_versions where id = ?', [$d->current_version_id]) : null],
            'versions' => $versions,
            'acknowledgements' => $acks,
        ]]);
    }

    /** Columnas jsonb: algunas guardan un texto JSON dentro del JSON; se decodifica hasta obtener el valor. */
    private function json(mixed $v): mixed
    {
        for ($i = 0; $i < 2 && is_string($v); $i++) {
            $d = json_decode($v, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                break;
            }
            $v = $d;
        }

        return $v;
    }
}
