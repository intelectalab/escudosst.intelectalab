<?php

namespace App\Modules\DOC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\FIR\SignatureService;
use App\Modules\REC\RecordsService;
use App\Modules\REC\Registry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Bandeja de aprobación de documentos (M-DOC SCR-DOC-04).
 *  GET  /doc/inbox                      versiones «en revisión» con su documento, categoría, quién debe aprobar y el resumen
 *                                       de cambios frente a la versión vigente; dice si el usuario puede aprobarla y por qué no.
 *  POST /doc/versions/{id}/approve      aprobar confirmando la contraseña {password}; si su vigencia empieza hoy o antes
 *                                       (o no tiene fecha), queda vigente y la anterior pasa a obsoleta (RN-DOC-02).
 *                                       Deja la firma de aprobación (M-FIR) del aprobador sobre la huella del contenido.
 *  POST /doc/versions/{id}/return       devolver a borrador con comentario {reason}; se avisa a quien la envió.
 *
 * RN-DOC-03: aprueba el rol que exige la categoría (Jefe SST, Comité SST o Gerencia) y nunca quien elaboró la versión.
 * Los cambios de estado pasan por el motor de registros (mismas reglas de versiones y bitácora).
 */
class ApprovalController extends Controller
{
    /** requires_approval_by de la categoría => roles que pueden aprobar y texto para el usuario. */
    public const APPROVERS = [
        'sst' => [['sst_manager', 'consultant'], 'el Jefe SST'],
        'committee' => [['committee_member'], 'el Comité SST (un miembro del comité)'],
        'management' => [['management'], 'la Gerencia'],
    ];

    public function __construct(private RecordsService $records) {}

    public function inbox(): JsonResponse
    {
        $rows = DB::select(
            "select v.id, v.document_id, v.version_number, v.version_label, v.change_summary, v.file_id, v.content_html, v.submitted_by, v.submitted_at,
                    v.effective_on, v.requires_ack, d.code as document_code, d.title as document_title, c.name as category_name,
                    coalesce(c.requires_approval_by, 'sst') as requires_approval_by, u.full_name as submitted_by_name,
                    f.original_name as file_name, f.mime_type as file_mime,
                    p.version_number as current_number, p.version_label as current_label, p.change_summary as current_summary, p.effective_on as current_effective_on
               from document_versions v
               join documents d on d.id = v.document_id and d.deleted_at is null
               left join document_categories c on c.id = d.category_id
               left join users u on u.id = v.submitted_by
               left join files f on f.id = v.file_id
               left join lateral (select x.version_number, x.version_label, x.change_summary, x.effective_on from document_versions x
                                   where x.document_id = v.document_id and x.status = 'current' and x.deleted_at is null limit 1) p on true
              where v.status = 'in_review' and v.deleted_at is null
              order by v.submitted_at nulls last, v.created_at"
        );

        return response()->json(['data' => array_map(function ($r) {
            [$can, $why] = $this->canApprove($r->requires_approval_by, $r->submitted_by);

            return [...(array) $r, 'approver_label' => self::APPROVERS[$r->requires_approval_by][1] ?? 'el Jefe SST', 'can_approve' => $can, 'cannot_reason' => $why];
        }, $rows)]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $v = $this->inReview($id);
        [$can, $why] = $this->canApprove($v->requires_approval_by, $v->submitted_by);
        if (! $can) {
            throw ApiProblem::forbidden($why);
        }
        $password = (string) $request->input('password');
        if ($password === '' || ! Hash::check($password, (string) $request->user()->getAuthPassword())) {
            $this->fail('password', 'Confirma tu contraseña para aprobar: equivale a tu firma de aprobación.');
        }
        $res = Registry::resource('DOC', 'document_versions');
        $out = DB::transaction(function () use ($res, $v) {
            $row = $this->records->update($res, $v->id, ['status' => 'approved', 'reviewed_by' => (string) auth()->id()]);
            $publish = ! $v->effective_on || $v->effective_on <= now('America/Lima')->toDateString();
            if ($publish) {
                $row = $this->records->update($res, $v->id, ['status' => 'current']);
            }
            // Firma de aprobación (M-FIR): evidencia del aprobador sobre la huella del contenido de la versión, que sigue
            // «Íntegra» aunque la versión pase luego a obsoleta (la huella no incluye el estado).
            [$reqId, $sigId] = SignatureService::signNow('document_versions', $v->id, 'DOC', 'approver', (string) auth()->id());
            DB::update('update document_versions set signature_request_id = ? where id = ?', [$reqId, $v->id]);
            $this->audit('approve', $v->id, ['status' => ['from' => 'in_review', 'to' => $row['status']], 'confirmed_with' => 'password', 'signature_id' => $sigId]);

            return $row + ['signature_id' => $sigId];
        });

        return response()->json(['data' => [
            'id' => $v->id, 'status' => $out['status'], 'signature_id' => $out['signature_id'], 'requires_ack' => (bool) $v->requires_ack, 'document_id' => $v->document_id,
            'message' => $out['status'] === 'current'
                ? 'Versión aprobada y vigente desde hoy. La anterior quedó obsoleta.'
                : 'Versión aprobada. Quedará vigente desde el '.date('d/m/Y', strtotime($v->effective_on)).'.',
        ]]);
    }

    public function giveBack(Request $request, string $id): JsonResponse
    {
        $v = $this->inReview($id);
        if (! in_array('doc.document_versions.approve', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no aprueba ni devuelve versiones de documentos.');
        }
        $reason = trim((string) $request->input('reason'));
        if (mb_strlen($reason) < 5) {
            $this->fail('reason', 'Escribe el comentario de por qué devuelves la versión (qué se debe corregir).');
        }
        DB::transaction(function () use ($v, $reason) {
            $this->records->update(Registry::resource('DOC', 'document_versions'), $v->id, [
                'status' => 'draft', 'review_comments' => mb_substr($reason, 0, 2000), 'reviewed_by' => (string) auth()->id(),
            ]);
            $this->audit('return', $v->id, ['status' => ['from' => 'in_review', 'to' => 'draft'], 'reason' => mb_substr($reason, 0, 500)]);
            if ($v->submitted_by && (string) $v->submitted_by !== (string) auth()->id()) {
                DB::insert(
                    "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, dedupe_key)
                     values (?, 'doc.version_returned', 'document_versions', ?, ?, ?, ?, '/m/doc', 'normal', ?)",
                    [$v->company_id, $v->id, $v->submitted_by, "Versión devuelta: {$v->document_code}", mb_substr("Comentario: {$reason}", 0, 500),
                        'doc_returned:'.$v->id.':'.Str::uuid()]
                );
            }
        });

        return response()->json(['data' => ['id' => $v->id, 'status' => 'draft']]);
    }

    /** @return array{0: bool, 1: ?string} */
    private function canApprove(string $requiredBy, ?string $author): array
    {
        if (! in_array('doc.document_versions.approve', Permissions::effective(), true)) {
            return [false, 'Tu rol no aprueba versiones de documentos.'];
        }
        if ($author && (string) $author === (string) auth()->id()) {
            return [false, 'Tú elaboraste esta versión: la aprueba otra persona.'];
        }
        [$roles, $label] = self::APPROVERS[$requiredBy] ?? self::APPROVERS['sst'];
        if (! in_array(app(CompanyContext::class)->roleCode, $roles, true)) {
            return [false, "Esta categoría la aprueba {$label}."];
        }

        return [true, null];
    }

    private function inReview(string $id): object
    {
        $v = Str::isUuid($id) ? DB::selectOne(
            "select v.*, d.code as document_code, coalesce(c.requires_approval_by, 'sst') as requires_approval_by
               from document_versions v join documents d on d.id = v.document_id left join document_categories c on c.id = d.category_id
              where v.id = ? and v.deleted_at is null", [$id]) : null;
        if (! $v) {
            throw ApiProblem::notFound('La versión no existe o es de otra empresa.');
        }
        if ($v->status !== 'in_review') {
            throw new ApiProblem(409, 'conflict', 'La versión no está en revisión', 'Esta versión ya no está en la bandeja: alguien la aprobó o la devolvió.');
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

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}
