<?php

namespace App\Modules\DOC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ficha del documento y campaña de cargos (M-DOC SCR-DOC-02 y SCR-DOC-05).
 *  GET  /doc/documents/{id}/sheet       línea de tiempo de versiones (envío, revisión, aprobación, vigencia) y cargos de la
 *                                       versión vigente: recibidos vs. esperados, con la lista de pendientes.
 *  POST /doc/documents/{id}/campaign    pedir la lectura de la versión vigente a los trabajadores de una sede, área y/o puesto
 *                                       {site_id?, area_id?, position_id?}: a cada uno le llega el aviso «Documento pendiente
 *                                       de cargo» (M-NOT, app y WhatsApp si lo aceptó). Quien ya firmó el cargo no se cuenta.
 *  POST /doc/documents/{id}/remind      recordatorio a los pendientes; como máximo uno cada «ack_reminder_days» días.
 *
 * Los esperados son los trabajadores a quienes se pidió el cargo de la versión vigente (RN-DOC-05: una versión nueva exige
 * un cargo nuevo). Si aún no hay campaña, se estiman con el alcance del documento (empresa, sede, área o puesto).
 */
class CampaignController extends Controller
{
    private const EVENT = 'doc.ack_requested';

    public function sheet(string $id): JsonResponse
    {
        $doc = $this->document($id);
        $versions = DB::select(
            'select v.id, v.version_number, v.version_label, v.status, v.change_summary, v.submitted_at, v.approved_at, v.effective_on, v.obsoleted_at,
                    v.review_comments, v.requires_ack, v.created_at, us.full_name as submitted_by_name, ua.full_name as approved_by_name, ur.full_name as reviewed_by_name
               from document_versions v left join users us on us.id = v.submitted_by left join users ua on ua.id = v.approved_by left join users ur on ur.id = v.reviewed_by
              where v.document_id = ? and v.deleted_at is null order by v.version_number desc',
            [$doc->id]
        );
        $current = collect($versions)->firstWhere('status', 'current');

        return response()->json(['data' => [
            'document' => $doc,
            'versions' => $versions,
            'acks' => $current ? $this->progress($doc, $current) : null,
        ]]);
    }

    public function campaign(Request $request, string $id): JsonResponse
    {
        $this->can('doc.documents.share');
        $d = $request->validate([
            'site_id' => ['nullable', 'uuid'],
            'area_id' => ['nullable', 'uuid'],
            'position_id' => ['nullable', 'uuid'],
        ]);
        $doc = $this->document($id);
        $version = $this->currentVersion($doc);
        [$where, $bind] = $this->target($d);
        $workers = DB::select(
            "select w.id, coalesce(w.user_id, m.user_id) as user_id from workers w
               left join lateral (select user_id from memberships where worker_id = w.id and status = 'active' and deleted_at is null limit 1) m on true
              where {$where}
                and not exists (select 1 from document_acknowledgements a where a.document_version_id = ? and a.worker_id = w.id)",
            [...$bind, $version->id]
        );
        $already = (int) DB::selectOne("select count(*) as n from workers w where {$where}
            and exists (select 1 from document_acknowledgements a where a.document_version_id = ? and a.worker_id = w.id)", [...$bind, $version->id])->n;
        if ($workers === [] && $already === 0) {
            $this->fail('site_id', 'No hay trabajadores activos con esa sede, área y puesto.');
        }
        $label = "{$doc->code} · {$doc->title}".($version->version_label ? " ({$version->version_label})" : " (v{$version->version_number})");
        $new = 0;
        foreach ($workers as $w) {
            $new += $this->notify($version->id, $w, "doc_ack:{$version->id}:{$w->id}", 'Documento pendiente de cargo', "Lee y firma el cargo de recepción de: {$label}.", 'high');
        }
        $this->audit('ack_campaign', $version->id, ['filters' => array_filter($d), 'requested' => count($workers), 'new' => $new, 'already_acknowledged' => $already]);

        return response()->json(['data' => ['requested' => count($workers), 'new' => $new, 'already_acknowledged' => $already, 'acks' => $this->progress($doc, $version)]], 201);
    }

    public function remind(string $id): JsonResponse
    {
        $this->can('doc.documents.share');
        $doc = $this->document($id);
        $version = $this->currentVersion($doc);
        $days = max(1, (int) LegalParams::number('ack_reminder_days', 3));
        $last = DB::selectOne("select max(created_at) as at from notifications where event_code = 'doc.ack_reminder' and subject_id = ? and deleted_at is null", [$version->id])->at;
        if ($last && strtotime($last) > time() - $days * 86400) {
            throw new ApiProblem(429, 'too_many_requests', 'Recordatorio reciente',
                'Ya se envió un recordatorio el '.date('d/m/Y', strtotime($last))." (se envía como máximo uno cada {$days} días).");
        }
        $pending = DB::select(
            'select distinct n.recipient_worker_id as id, n.recipient_user_id as user_id from notifications n
              where n.event_code = ? and n.subject_id = ? and n.deleted_at is null and n.recipient_worker_id is not null
                and not exists (select 1 from document_acknowledgements a where a.document_version_id = n.subject_id and a.worker_id = n.recipient_worker_id)',
            [self::EVENT, $version->id]
        );
        if ($pending === []) {
            throw new ApiProblem(409, 'conflict', 'Nada que recordar', 'No hay cargos pendientes de esta versión: primero lanza la campaña de cargos.');
        }
        $stamp = date('Ymd');
        $sent = 0;
        foreach ($pending as $w) {
            $sent += $this->notify($version->id, $w, "doc_ack_rem:{$version->id}:{$w->id}:{$stamp}", 'Recordatorio: documento pendiente de cargo',
                "Aún falta tu cargo de lectura de {$doc->code} · {$doc->title}.", 'normal', 'doc.ack_reminder');
        }

        return response()->json(['data' => ['sent' => $sent, 'acks' => $this->progress($doc, $version)]]);
    }

    /** Recibidos vs. esperados de una versión, con la lista de pendientes (hasta 200). */
    private function progress(object $doc, object $version): array
    {
        $campaign = (bool) DB::selectOne('select 1 as ok from notifications where event_code = ? and subject_id = ? and deleted_at is null limit 1', [self::EVENT, $version->id]);
        if ($campaign) {
            $expectedSql = 'select distinct recipient_worker_id as id from notifications where event_code = ? and subject_id = ? and deleted_at is null and recipient_worker_id is not null';
            $expectedBind = [self::EVENT, $version->id];
        } else {
            $scope = match ($doc->scope_type) {
                'site' => ['site_id' => $doc->site_id], 'area' => ['area_id' => $doc->area_id], 'position' => ['position_id' => $doc->position_id], default => [],
            };
            [$where, $bind] = $this->target(array_filter($scope));
            $expectedSql = "select w.id from workers w where {$where}";
            $expectedBind = $bind;
        }
        $counts = DB::selectOne(
            "select count(*) as expected, count(a.worker_id) as received from ({$expectedSql}) e
               left join document_acknowledgements a on a.document_version_id = ? and a.worker_id = e.id",
            [...$expectedBind, $version->id]
        );
        $total = (int) DB::selectOne('select count(*) as n from document_acknowledgements where document_version_id = ?', [$version->id])->n;
        $pending = DB::select(
            "select w.id, w.full_name, s.name as site_name, ar.name as area_name, p.name as position_name,
                    (select max(n.created_at) from notifications n where n.recipient_worker_id = w.id and n.subject_id = ? and n.event_code in ('doc.ack_requested', 'doc.ack_reminder') and n.deleted_at is null) as notified_at
               from ({$expectedSql}) e join workers w on w.id = e.id
               left join sites s on s.id = w.current_site_id left join areas ar on ar.id = w.current_area_id left join positions p on p.id = w.current_position_id
              where not exists (select 1 from document_acknowledgements a where a.document_version_id = ? and a.worker_id = w.id)
              order by w.full_name limit 200",
            [$version->id, ...$expectedBind, $version->id]
        );
        $last = DB::selectOne("select max(created_at) as at from notifications where event_code in ('doc.ack_requested', 'doc.ack_reminder') and subject_id = ? and deleted_at is null", [$version->id])->at;
        $expected = (int) $counts->expected;
        $received = (int) $counts->received;

        return [
            'version_id' => $version->id, 'version_number' => (int) $version->version_number, 'version_label' => $version->version_label,
            'requires_ack' => (bool) $version->requires_ack, 'campaign' => $campaign, 'expected' => $expected, 'received' => $received,
            'received_total' => $total, 'pct' => $expected > 0 ? (int) round($received * 100 / $expected) : null, 'last_sent_at' => $last, 'pending' => $pending,
        ];
    }

    /** Trabajadores activos (o de licencia) con la sede, área y puesto elegidos. */
    private function target(array $d): array
    {
        $where = ["w.deleted_at is null and w.status in ('active', 'on_leave')"];
        $bind = [];
        foreach (['site_id' => 'current_site_id', 'area_id' => 'current_area_id', 'position_id' => 'current_position_id'] as $k => $col) {
            if (! empty($d[$k])) {
                $where[] = "w.{$col} = ?";
                $bind[] = $d[$k];
            }
        }

        return [implode(' and ', $where), $bind];
    }

    private function notify(string $versionId, object $w, string $dedupe, string $title, string $body, string $priority, string $event = self::EVENT): int
    {
        return DB::affectingStatement(
            "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, recipient_worker_id, title, body, link, priority, dedupe_key)
             select ?, ?, 'document_versions', ?, ?, ?, ?, ?, '/m/doc', ?, ?
              where not exists (select 1 from notifications where dedupe_key = ? and deleted_at is null)",
            [app(CompanyContext::class)->companyId, $event, $versionId, $w->user_id, $w->id, $title, mb_substr($body, 0, 500), $priority, $dedupe, $dedupe]
        );
    }

    private function document(string $id): object
    {
        $this->can('doc.documents.read');
        $doc = Str::isUuid($id) ? DB::selectOne(
            'select d.id, d.code, d.title, d.status, d.scope_type, d.site_id, d.area_id, d.position_id, d.next_review_on, d.current_version_id,
                    c.name as category_name, c.requires_approval_by, c.requires_ack as category_requires_ack, u.full_name as owner_name
               from documents d left join document_categories c on c.id = d.category_id left join users u on u.id = d.owner_user_id
              where d.id = ? and d.deleted_at is null', [$id]) : null;
        if (! $doc) {
            throw ApiProblem::notFound('El documento no existe o es de otra empresa.');
        }

        return $doc;
    }

    private function currentVersion(object $doc): object
    {
        $v = DB::selectOne("select id, version_number, version_label, requires_ack from document_versions where document_id = ? and status = 'current' and deleted_at is null limit 1", [$doc->id]);
        if (! $v) {
            throw new ApiProblem(409, 'conflict', 'Sin versión vigente', 'El documento aún no tiene una versión vigente: apruébala antes de pedir los cargos.');
        }

        return $v;
    }

    private function audit(string $action, string $id, array $changes): void
    {
        $ctx = app(CompanyContext::class);
        DB::insert(
            "insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes, ip, user_agent)
             values (?, ?, ?, 'user', ?, 'DOC', 'document_versions', ?, ?::jsonb, ?::inet, ?)",
            [$ctx->companyId, (string) auth()->id(), $ctx->roleCode, $action, $id, json_encode($changes, JSON_UNESCAPED_UNICODE),
                request()->ip(), mb_substr((string) request()->userAgent(), 0, 300)]
        );
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede hacer esto con los documentos.');
        }
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}
