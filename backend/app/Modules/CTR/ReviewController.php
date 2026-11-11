<?php

namespace App\Modules\CTR;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\REC\RecordsService;
use App\Modules\REC\Registry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Revisión de documentos de contratistas (M-CTR SCR-CTR-04 «Revisión de documentos»):
 *
 *  GET  /ctr/review?status=submitted|observed|approved|all&contractor_id=   bandeja con contadores
 *  POST /ctr/review/{document}   {decision: approve|observe|reject, comment?, expires_on?}
 *
 * Aprobar u observar exige ctr.contractor_documents.approve o .verify. Observar y rechazar exigen el motivo; un
 * documento aprobado no se vuelve a revisar (RN-CTR-07: se reemplaza por uno nuevo). Se avisa al contratista.
 */
class ReviewController extends Controller
{
    public function __construct(private RecordsService $records) {}

    public function index(Request $request): JsonResponse
    {
        $this->need(['ctr.contractor_documents.read']);
        $status = (string) $request->query('status', 'submitted');
        $where = ['d.deleted_at is null'];
        $bind = [];
        if ($status !== 'all') {
            $where[] = 'd.status = ?';
            $bind[] = in_array($status, ['submitted', 'observed', 'approved', 'rejected', 'expired'], true) ? $status : 'submitted';
        }
        $cid = (string) $request->query('contractor_id', '');
        if (Str::isUuid($cid)) {
            $where[] = 'd.contractor_id = ?';
            $bind[] = $cid;
        }
        $rows = DB::select(
            "select d.id, d.status, d.issued_on, d.expires_on, d.review_comments, d.reviewed_at, d.created_at, d.document_kind,
                    c.id as contractor_id, c.legal_name as contractor_name, c.ruc,
                    r.name as requirement_name, r.applies_to, r.validity_months,
                    w.id as worker_id, nullif(trim(concat_ws(' ', w.first_name, w.paternal_last_name, w.maternal_last_name)), '') as worker_name, w.document_last4,
                    f.id as file_id, f.original_name as file_name, f.mime_type, f.size_bytes,
                    up.full_name as uploaded_by_name, rv.full_name as reviewed_by_name
               from contractor_documents d
               join contractors c on c.id = d.contractor_id
               left join contractor_requirements r on r.id = d.requirement_id
               left join contractor_workers w on w.id = d.contractor_worker_id
               left join files f on f.id = d.file_id
               left join users up on up.id = d.uploaded_by
               left join users rv on rv.id = d.reviewed_by
              where ".implode(' and ', $where).'
              order by '.($status === 'submitted' ? 'd.created_at asc' : 'd.updated_at desc').' limit 200',
            $bind
        );
        $k = DB::selectOne(
            "select count(*) filter (where status = 'submitted') as submitted, count(*) filter (where status = 'observed') as observed,
                    count(*) filter (where status = 'approved' and reviewed_at >= date_trunc('month', now())) as approved_month,
                    count(*) filter (where status = 'approved' and expires_on < current_date) as expired
               from contractor_documents where deleted_at is null"
        );

        return response()->json([
            'data' => $rows,
            'counts' => ['submitted' => (int) $k->submitted, 'observed' => (int) $k->observed, 'approved_month' => (int) $k->approved_month, 'expired' => (int) $k->expired],
            'can_review' => $this->canReview(),
        ]);
    }

    public function review(Request $request, string $document): JsonResponse
    {
        if (! $this->canReview()) {
            throw ApiProblem::forbidden('Tu rol no revisa documentos de contratistas.');
        }
        $doc = Str::isUuid($document) ? DB::selectOne(
            'select d.id, d.status, d.contractor_id, d.uploaded_by, c.legal_name, r.name as requirement_name
               from contractor_documents d join contractors c on c.id = d.contractor_id left join contractor_requirements r on r.id = d.requirement_id
              where d.id = ? and d.deleted_at is null', [$document]) : null;
        if (! $doc) {
            throw ApiProblem::notFound('El documento no existe.');
        }
        if ($doc->status === 'approved') {
            throw new ApiProblem(409, 'conflict', 'Documento aprobado', 'Un documento aprobado no se modifica: el contratista debe cargar uno nuevo para reemplazarlo.');
        }
        $decision = (string) $request->input('decision');
        $status = ['approve' => 'approved', 'observe' => 'observed', 'reject' => 'rejected'][$decision] ?? null;
        if (! $status) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Elige aprobar u observar.', ['errors' => ['decision' => ['Elige aprobar u observar.']]]);
        }
        $comment = trim((string) $request->input('comment'));
        if ($status !== 'approved' && mb_strlen($comment) < 3) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Explica qué se debe corregir.', ['errors' => ['comment' => ['Explica qué se debe corregir.']]]);
        }
        $input = ['status' => $status];
        if ($comment !== '') {
            $input['review_comments'] = $comment;
        }
        if ($request->filled('expires_on')) {
            $input['expires_on'] = (string) $request->input('expires_on');
        }
        $row = $this->records->update(Registry::resource('CTR', 'contractor_documents'), $doc->id, $input);

        $what = $doc->requirement_name ?: 'documento';
        $users = array_filter([...ContractorStatus::adminUsers($doc->contractor_id), $doc->uploaded_by]);
        $users = array_values(array_diff($users, [(string) auth()->id()]));
        ContractorStatus::notify($users, 'ctr.document_'.$status, $doc->id,
            $status === 'approved' ? 'Documento aprobado' : ($status === 'observed' ? 'Documento observado' : 'Documento rechazado'),
            $status === 'approved' ? "Se aprobó «{$what}»." : "Revisa «{$what}»: {$comment}",
            '/m/ctr', $status === 'approved' ? 'normal' : 'high');

        return response()->json(['data' => $row]);
    }

    private function canReview(): bool
    {
        $p = Permissions::effective();

        return in_array('ctr.contractor_documents.update', $p, true)
            && (in_array('ctr.contractor_documents.approve', $p, true) || in_array('ctr.contractor_documents.verify', $p, true))
            && ContractorStatus::scopedContractorId() === null;
    }

    private function need(array $perms): void
    {
        if (array_diff($perms, Permissions::effective())) {
            throw ApiProblem::forbidden('Tu rol no puede ver esta bandeja.');
        }
        if (ContractorStatus::scopedContractorId() !== null) {
            throw ApiProblem::forbidden('La bandeja de revisión es de la empresa principal.');
        }
    }
}
