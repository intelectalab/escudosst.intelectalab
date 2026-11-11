<?php

namespace App\Modules\MRL;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Novedades normativas (M-MRL SCR-MRL-05, RN-MRL-05):
 *  GET  /mrl/news                          requisitos de la matriz que «requieren revisión» por un cambio de su norma (con el
 *                                          motivo) y normas del catálogo nuevas o modificadas en los últimos 90 días
 *  POST /mrl/requirements/{id}/reviewed    «Revisado»: el Jefe SST confirma que revisó el requisito {note?}; deja de estar marcado
 */
class NewsController extends Controller
{
    public function index(): JsonResponse
    {
        $flagged = DB::select(
            "select r.id, r.article, r.requirement, r.review_reason, r.review_flagged_at, r.legal_norm_id, n.norm_type, n.number, n.title as norm_title, n.status as norm_status,
                    u.full_name as responsible_name
               from legal_requirements r join legal_norms n on n.id = r.legal_norm_id left join users u on u.id = r.responsible_user_id
              where r.deleted_at is null and r.needs_review order by r.review_flagged_at desc nulls last limit 200"
        );
        $recent = DB::select(
            "select n.id, n.norm_type, n.number, n.title, n.status, n.updated_at, n.created_at, n.created_at > now() - interval '90 days' as is_new,
                    (select count(*) from legal_requirements r where r.legal_norm_id = n.id and r.deleted_at is null) as linked_count,
                    (select count(*) from legal_requirements r where r.legal_norm_id = n.id and r.deleted_at is null and r.needs_review) as review_count
               from legal_norms n where n.deleted_at is null and n.company_id is null and n.updated_at > now() - interval '90 days'
              order by n.updated_at desc limit 30"
        );

        return response()->json(['data' => [
            'needs_review' => $flagged,
            'recent_norms' => array_map(fn ($n) => [...(array) $n, 'is_new' => (bool) $n->is_new, 'linked_count' => (int) $n->linked_count, 'review_count' => (int) $n->review_count], $recent),
            'can_review' => in_array('mrl.legal_requirements.update', Permissions::effective(), true),
        ]]);
    }

    public function reviewed(Request $request, string $id): JsonResponse
    {
        if (! in_array('mrl.legal_requirements.update', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no revisa los requisitos de la matriz.');
        }
        $r = Str::isUuid($id) ? DB::selectOne('select id, needs_review, review_reason from legal_requirements where id = ? and deleted_at is null', [$id]) : null;
        if (! $r) {
            throw ApiProblem::notFound('El requisito no existe o es de otra empresa.');
        }
        if (! $r->needs_review) {
            throw new ApiProblem(409, 'conflict', 'Ya revisado', 'Este requisito no tiene cambios normativos por revisar.');
        }
        $note = mb_substr(trim((string) $request->input('note')), 0, 500);
        $uid = (string) auth()->id();
        DB::update('update legal_requirements set needs_review = false, reviewed_at = now(), reviewed_by = ?, updated_by = ? where id = ?', [$uid, $uid, $r->id]);
        $ctx = app(CompanyContext::class);
        DB::insert(
            "insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes, ip, user_agent)
             values (?, ?, ?, 'user', 'review', 'MRL', 'legal_requirements', ?, ?::jsonb, ?::inet, ?)",
            [$ctx->companyId, $uid, $ctx->roleCode, $r->id, json_encode(['needs_review' => ['from' => true, 'to' => false], 'reason' => $r->review_reason, 'note' => $note ?: null], JSON_UNESCAPED_UNICODE),
                $request->ip(), mb_substr((string) $request->userAgent(), 0, 300)]
        );

        return response()->json(['data' => ['id' => $r->id, 'needs_review' => false]]);
    }
}
