<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GET /rvd/summary — indicadores de la revisión por la dirección (M-RVD): revisiones del año, la última cerrada, cuándo toca la
 * siguiente (management_review_months, RN-RVD-04) y las decisiones abiertas o cumplidas con sus acciones.
 */
class ReviewSummaryController extends Controller
{
    public function __invoke(): JsonResponse
    {
        if (! in_array('rvd.management_reviews.read', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no ve la revisión por la dirección.');
        }
        $months = (int) LegalParams::number('management_review_months', 12);
        $last = DB::selectOne("select id, title, review_on from management_reviews where deleted_at is null and status = 'closed' order by review_on desc nulls last limit 1");
        $out = DB::selectOne(
            "select count(*) filter (where o.status in ('open', 'in_progress')) as open, count(*) filter (where o.status = 'done') as done,
                    count(*) filter (where o.corrective_action_id is not null) as with_action, count(*) as total
               from management_review_outputs o join management_reviews r on r.id = o.review_id and r.deleted_at is null where o.deleted_at is null");

        return response()->json(['data' => [
            'this_year' => (int) DB::selectOne("select count(*) as n from management_reviews where deleted_at is null and year = extract(year from now())")->n,
            'last' => $last,
            'months' => $months,
            'next_due' => $last?->review_on ? date('Y-m-d', strtotime($last->review_on." +{$months} months")) : null,
            'outputs' => ['open' => (int) $out->open, 'done' => (int) $out->done, 'with_action' => (int) $out->with_action, 'total' => (int) $out->total],
        ]]);
    }
}
