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
 * Autoevaluación del proveedor (M-HOM flujo 1), desde el portal del contratista:
 *  GET  /ctr/portal/homologations        evaluaciones de mi empresa por responder o ya resueltas, con los criterios de su plantilla
 *  POST /ctr/portal/homologations/{id}   responde {scores: {criterio: {score (0 a 5), comment?, evidence_file_id?}}} y la envía a SST (submitted)
 */
class VendorSelfController extends Controller
{
    public function index(): JsonResponse
    {
        $c = ContractorStatus::requirePortalContractor();
        $rows = DB::select(
            "select e.id, e.status, e.evaluated_on, e.total_score, e.rating, e.valid_until, e.scores, t.name as template, t.criteria, t.passing_score
               from vendor_evaluations e join vendor_evaluation_templates t on t.id = e.template_id
              where e.contractor_id = ? and e.deleted_at is null order by e.created_at desc", [$c->id]);
        foreach ($rows as $r) {
            $r->criteria = json_decode((string) $r->criteria, true) ?: [];
            $r->scores = json_decode((string) $r->scores, true) ?: [];
        }

        return response()->json(['data' => $rows]);
    }

    public function answer(Request $request, string $id, RecordsService $records): JsonResponse
    {
        $c = ContractorStatus::requirePortalContractor();
        if (! in_array('hom.vendor_evaluations.update', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no responde la autoevaluación.');
        }
        $e = Str::isUuid($id) ? DB::selectOne('select e.*, t.criteria from vendor_evaluations e join vendor_evaluation_templates t on t.id = e.template_id where e.id = ? and e.contractor_id = ? and e.deleted_at is null', [$id, $c->id]) : null;
        if (! $e) {
            throw ApiProblem::notFound('No encontramos esa evaluación.');
        }
        if ($e->status !== 'draft') {
            throw new ApiProblem(409, 'conflict', 'Ya enviada', 'La autoevaluación ya se envió.');
        }
        $max = \App\Modules\HOM\VendorScoring::MAX;
        $d = $request->validate(['scores' => ['required', 'array'], 'scores.*.score' => ['required', 'numeric', 'min:0', 'max:'.$max], 'scores.*.comment' => ['nullable', 'string', 'max:1000'],
            'scores.*.evidence_file_id' => ['nullable', 'uuid']], ['scores.*.score.required' => 'Califica cada criterio.', 'scores.*.score.min' => "Cada puntaje va de 0 a {$max}.",
                'scores.*.score.max' => "Cada puntaje va de 0 a {$max}."]);
        // El total y la calificación A/B/C los calcula VendorScoring (el mismo cálculo que «Calificar» de SST).
        $records->update(Registry::resource('HOM', 'vendor_evaluations'), $id, ['scores' => $d['scores'], 'status' => 'submitted']);

        return response()->json(['data' => ['id' => $id, 'status' => 'submitted']]);
    }
}
